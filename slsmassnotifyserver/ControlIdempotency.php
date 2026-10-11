<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityStore.php';

/** A durable claim precedes submission; an interrupted claim never sends again. */
final class ControlIdempotency
{
    public const RETENTION_SECONDS=2592000;
    private EnterpriseIdentityStore $store;
    public function __construct(string $directory) { $this->store=new EnterpriseIdentityStore($directory); }
    private static function canonical($value)
    {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value,SORT_STRING); }
        foreach ($value as &$child) { $child=self::canonical($child); } unset($child);
        return $value;
    }
    public function submit(string $key,array $principal,array $body,callable $send,int $now): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$key)) { throw new \InvalidArgumentException('Idempotency-Key must contain 16–128 letters, digits, underscores or hyphens.'); }
        $identity=(string)($principal['id']??'');
        if ($identity==='') { throw new \DomainException('An authenticated credential is required.'); }
        $id=hash('sha256',$identity."\0".$key);
        $fingerprint=hash('sha256',json_encode(self::canonical([$body,$principal['audience']??[],$principal['scopes']??[]]),JSON_THROW_ON_ERROR));
        $claim=$this->store->transaction('announcement_claims',static function(array &$rows) use($id,$fingerprint,$now): array {
            foreach ($rows as $savedId=>$row) {
                if (!preg_match('/^[a-f0-9]{64}$/D',(string)$savedId)||!is_array($row)||!in_array($row['state']??null,['claimed','complete'],true)
                    ||!is_int($row['expires_at']??null)||!preg_match('/^[a-f0-9]{64}$/D',$row['fingerprint']??'')) { throw new \RuntimeException('Announcement idempotency history is invalid. Preserve it for recovery.'); }
                if ($row['expires_at']<=$now) { unset($rows[$savedId]); }
            }
            if (isset($rows[$id])) {
                if (!hash_equals($rows[$id]['fingerprint'],$fingerprint)) { throw new \DomainException('Idempotency-Key was already used with different announcement details or permissions.'); }
                return ['new'=>false,'row'=>$rows[$id]];
            }
            if (count($rows)>=10000) { throw new \RuntimeException('Announcement idempotency capacity reached. No announcement was submitted.'); }
            $row=['state'=>'claimed','fingerprint'=>$fingerprint,'created_at'=>$now,'expires_at'=>$now+self::RETENTION_SECONDS];
            $rows[$id]=$row; return ['new'=>true,'row'=>$row];
        });
        if (!$claim['new']) {
            if ($claim['row']['state']==='complete') { return $claim['row']['result']; }
            return ['success'=>false,'error_code'=>'announcement_submission_uncertain','retryable'=>false,
                'message'=>'This request was reserved but its submission result is unconfirmed. Review delivery history; repeating this key will not send another announcement.'];
        }
        $result=$send();
        if (!is_array($result)||strlen(json_encode($result,JSON_THROW_ON_ERROR))>65536) { throw new \RuntimeException('Announcement submission result could not be stored. Do not resend using a new key before checking delivery history.'); }
        return $this->store->transaction('announcement_claims',static function(array &$rows) use($id,$fingerprint,$result): array {
            if (!isset($rows[$id])||!hash_equals($rows[$id]['fingerprint'],$fingerprint)||$rows[$id]['state']!=='claimed') { throw new \RuntimeException('Announcement claim changed during submission. Preserve delivery history.'); }
            $rows[$id]['state']='complete'; $rows[$id]['result']=$result; return $result;
        });
    }
}
