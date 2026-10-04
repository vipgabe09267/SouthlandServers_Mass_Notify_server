<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/RecipientSelection.php';
class EmailResponseCaptured extends RuntimeException {}
function slsmassnotifyserver_json_response($response) { throw new EmailResponseCaptured(); }
class EmailControllerFixture {
    public $call=[];
    public function sendSipNotifyAnnouncement(...$args) { $this->call=$args; return []; }
    public function saveAnnouncementGroup(...$args) { $this->call=$args; return []; }
}
$source=file_get_contents(dirname(__DIR__).'/slsmassnotifyserver/page.slsmassnotifyserver.php');
$id='email_'.str_repeat('a',24);
foreach (['send_announcement'=>'announcement_email_recipient_ids','save_announcement_group'=>'group_email_recipient_ids'] as $action=>$field) {
    $selection=array_fill_keys(\SLS\MassNotify\RecipientSelection::FIELDS[$action],[]);$selection[$field]=[$id];
    $_POST=\SLS\MassNotify\RecipientSelection::decode(['slsmassnotifyserver_action'=>$action,'sls_recipient_selection_present'=>'1','sls_recipient_selection_complete'=>'1','sls_recipient_selection_json'=>json_encode($selection),'announcement_body'=>'Text only','announcement_audio_mode'=>'none']);
    $_SERVER['REQUEST_METHOD']='POST';$slsmassnotifyserver=new EmailControllerFixture();
    $marker="if (\$_SERVER['REQUEST_METHOD'] === 'POST' && (\$_POST['slsmassnotifyserver_action'] ?? '') === '".$action."') {";
    $start=strpos($source,$marker);if($start===false)throw new RuntimeException('Controller branch missing');$end=strpos($source,"\nif (",$start+strlen($marker));$branch=substr($source,$start,$end-$start);
    try { eval($branch); } catch (EmailResponseCaptured $e) {}
    if ($action==='send_announcement') {
        if (($slsmassnotifyserver->call[5]['email_recipient_ids']??null)!==[$id] || $slsmassnotifyserver->call[5]['audio_mode']!=='none' || $slsmassnotifyserver->call[0]!==[]) throw new RuntimeException('Email-only send not routed intact');
    } elseif (count($slsmassnotifyserver->call)!==8 || $slsmassnotifyserver->call[5]!==[$id]) throw new RuntimeException('Group email IDs not sixth argument');
}
echo "Actual announcement/group controller branches preserve decoded email IDs and email-only audio None.\n";
