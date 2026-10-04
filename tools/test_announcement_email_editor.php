<?php
declare(strict_types=1);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$settings=['announcement_email'=>['enabled'=>'0','recipients'=>[['id'=>'email_'.str_repeat('a',24),'name'=>'</script><script>alert(1)</script>','address'=>'office@example.com','enabled'=>'1']]]];
ob_start(); require dirname(__DIR__).'/slsmassnotifyserver/views/announcement_email.php'; $html=ob_get_clean();
foreach (['name="announcement_email_present"','name="announcement_email_complete"','name="announcement_email_recipients_json"','name="announcement_email_enabled"','maxlength="240"','maxlength="254"','Disabled by default.','Saving this list does not send email.','data-email-template','data-email-remove','data-email-add','\\u003C\/script\\u003E'] as $needle) {
    if (strpos($html,$needle)===false) throw new RuntimeException('Missing editor contract: '.$needle);
}
if (strpos($html,'</script><script>alert(1)</script>')!==false) throw new RuntimeException('Saved recipient name escaped script context');
if (preg_match('/name="announcement_email_enabled"[^>]*checked/',$html)) throw new RuntimeException('Default channel enabled');
if (substr_count($html,'<script>')!==1) throw new RuntimeException('Unexpected executable markup');
echo "Announcement email editor form contract, disabled default and hostile-name escaping passed.\n";
