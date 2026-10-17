<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseApprovalService.php';
use SLS\MassNotify\{EnterpriseApprovalService,EnterpriseOperationsStore};
function aliasCheck(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function aliasReject(callable $work): void {
    try { $work(); }
    catch (DomainException $error) {
        aliasCheck(trim($error->getMessage())!=='','Approval rejection did not explain the security decision.');
        return;
    }
    throw new RuntimeException('One identity satisfied two-person approval.');
}
$root=sys_get_temp_dir().'/sls-approval-identities-'.bin2hex(random_bytes(8));
aliasCheck(mkdir($root,0700)&&chmod($root,0700),'Unable to create a private approval fixture directory.');
register_shutdown_function(static function() use ($root): void {
    if (!is_dir($root)||is_link($root)) { return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()&&!$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($root);
});
$alias='api_'.str_repeat('a',24);$policy='approval_'.str_repeat('b',24);$alice=str_repeat('c',64);$bob=str_repeat('d',64);
$settings=['enterprise_operations'=>['enabled'=>true,'dual_approval'=>['enabled'=>true,'policies'=>[
    ['id'=>$policy,'name'=>'Two independent accounts','enabled'=>true,'channels'=>['phones'],
     'approver_ids'=>['pbx:alice',$alias,'pbx:bob'],'expires_seconds'=>300]]]]];
$store=new EnterpriseOperationsStore($root.'/ops');$service=new EnterpriseApprovalService($store);
$request=['phones'=>['1000'],'message'=>'Private fixture'];
$ticket=$service->request($settings,$request,'pbx:alice',$alice);$id=$ticket['review_id'];
aliasReject(fn()=>$service->approve($id,$settings,$alias,static fn()=>true,$alice));
aliasReject(fn()=>$service->claim($id,$settings,'pbx:alice',static fn()=>true));
$approved=$service->approve($id,$settings,'pbx:bob',static fn()=>true,$bob);aliasCheck($approved['ready'],'Two different current identities were not ready.');
$claimed=$service->claim($id,$settings,'pbx:bob',static fn()=>true);
aliasCheck($service->permits($settings,$claimed,static fn($person,$body,$identity)=>$identity===$alice||$identity===$bob),'Independent identities were refused at final delivery.');
aliasCheck(!$service->permits($settings,$claimed,static fn($person)=>$person!=='pbx:bob'),'Revoked second approver retained permission.');
// Retained reviews from an older generation must also lose the alias bypass.
$store->transaction(static function(array &$state) use ($id,$policy,$alias,$alice): void {
    $review=&$state['approvals'][$id];$review['state']='awaiting_approval';unset($review['claimed_at']);
    if (($review['history'][count($review['history'])-1]['action']??'')==='submission_claimed') { array_pop($review['history']); }
    $review['approvals']=[$policy=>['pbx:alice'=>gmdate('c'),$alias=>gmdate('c')]];
    $review['approval_identities']=['pbx:alice'=>$alice,$alias=>$alice];
});
aliasReject(fn()=>$service->claim($id,$settings,$alias,static fn()=>true));
$store->transaction(static function(array &$state) use ($id,$alias): void {
    $review=&$state['approvals'][$id];$review['state']='submitting';$review['claimed_at']=$review['created_at'];
    $review['history'][]=['at'=>gmdate('c',$review['claimed_at']),'action'=>'submission_claimed','person'=>$alias];
});
aliasCheck(!$service->permits($settings,$claimed,static fn()=>true),'A retained submitting review counted two aliases of the same identity.');
echo "Dual approval: same-account aliases, retained reviews, independent identities and revocation passed.\n";
