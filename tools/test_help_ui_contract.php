<?php

$view = file_get_contents(__DIR__ . '/../slsmassnotifyserver/views/help.php');
if (!is_string($view) || $view === '') {
	fwrite(STDERR, "Unable to read the Help view.\n");
	exit(1);
}

$phoneView = file_get_contents(__DIR__ . '/../slsmassnotifyserver/views/detected_phones.php');
$view .= $phoneView;
$required = [
	'sls-help-endpoint-table',
	'min-width: 660px',
	'white-space: nowrap',
	"<?php echo _('Extension'); ?>",
	"last_acknowledged_at",
	"Live stream active",
	"do not prove the user read the message",
];
foreach ($required as $needle) {
	if (strpos($view, $needle) === false) {
		fwrite(STDERR, "Help endpoint layout contract is missing: {$needle}\n");
		exit(1);
	}
}

if (strpos($view, "<?php echo _('Ext'); ?>") !== false) {
	fwrite(STDERR, "The abbreviated endpoint heading is still present.\n");
	exit(1);
}

echo "Help endpoint layout contract passed.\n";

if (!function_exists('_')) { function _($text) { return $text; } }
$endpointDiagnostics = [['extension'=>'1000', 'unknown'=>true, 'devices'=>[
    ['format'=>'yealink','transport'=>'tls','user_agent'=>'Yealink T48G'],
    ['format'=>'poly','transport'=>'tcp','user_agent'=>'Poly VVX'],
    ['format'=>'unknown','transport'=>'udp','user_agent'=>'<script>unexpected()</script>'],
    ['format'=>'fanvil','transport'=>'udp','user_agent'=>'Fanvil X6','override'=>true],
]]];
ob_start(); include __DIR__.'/../slsmassnotifyserver/views/detected_phones.php'; $rendered=ob_get_clean();
$document=new DOMDocument();@$document->loadHTML('<table>'.$rendered.'</table>');$xpath=new DOMXPath($document);
if ($xpath->query('//tbody/tr')->length!==4 || $xpath->query('//span[contains(@class,"label-warning")]')->length!==1
    || $xpath->query('//script')->length!==0 || strpos($rendered,'Yealink')===false || strpos($rendered,'Poly')===false
    || strpos($rendered,'TLS')===false || strpos($rendered,'TCP')===false || strpos($rendered,'Fanvil')===false) {
    throw new RuntimeException('Mixed registrations hid known devices, used extension-wide formats, or failed escaping.');
}
echo "Help mixed-registration rendering preserves individual vendors, unknown devices, transports and escaping.\n";
