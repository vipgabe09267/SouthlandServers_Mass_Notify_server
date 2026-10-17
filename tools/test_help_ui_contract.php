<?php
declare(strict_types=1);

$view = file_get_contents(__DIR__ . '/../slsmassnotifyserver/views/help.php');
if (!is_string($view) || $view === '') {
	fwrite(STDERR, "Unable to read the Help view.\n");
	exit(1);
}

$phoneView = file_get_contents(__DIR__ . '/../slsmassnotifyserver/views/detected_phones.php');
if (!is_string($phoneView) || $phoneView === '') {
	fwrite(STDERR, "Unable to read the detected-phone view.\n");
	exit(1);
}
$view .= $phoneView;
$installationProfile = file_get_contents(__DIR__ . '/../slsmassnotifyserver/views/installation_profile.php');
if (!is_string($installationProfile) || $installationProfile === '') {
	fwrite(STDERR, "Unable to read the installation compatibility view.\n");
	exit(1);
}
$view .= $installationProfile;
$controller = file_get_contents(__DIR__ . '/../slsmassnotifyserver/page.slsmassnotifyserver_help.php');
if (!is_string($controller) || $controller === '') {
	fwrite(STDERR, "Unable to read the Help controller.\n");
	exit(1);
}
if (strpos($controller, 'JSON_INVALID_UTF8_SUBSTITUTE') === false
	|| strpos($controller, 'catch (\\JsonException | \\InvalidArgumentException $error)') === false
	|| strpos($controller, 'catch (\\Throwable $error)') !== false) {
	fwrite(STDERR, "Help JSON response encoding or parser exception handling regressed.\n");
	exit(1);
}
$required = [
	'sls-help-endpoint-table',
	'min-width: 660px',
	'white-space: nowrap',
	"<?php echo _('Extension'); ?>",
	"last_acknowledged_at",
	"Live stream active",
	"do not prove the user read the message",
	"value.success!==true",
	"typeof value.message==='string'",
	"The compatibility report is unavailable. Run slsconsole doctor from the CLI.",
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
if (strpos($installationProfile, ":error.message") !== false) {
	fwrite(STDERR, "The installation report still exposes raw browser exception text.\n");
	exit(1);
}

echo "Help endpoint layout contract passed.\n";

if (!function_exists('_')) { function _($text) { return $text; } }
$endpointDiagnostics = [['extension'=>'1000', 'unknown'=>true, 'devices'=>[
    ['format'=>'yealink','transport'=>'tls','user_agent'=>'Yealink T48G'],
    ['format'=>'yealink_image','transport'=>'tls','user_agent'=>'Yealink T48G image override'],
    ['format'=>'poly','transport'=>'tcp','user_agent'=>'Poly VVX'],
    ['format'=>'unknown','transport'=>'udp','user_agent'=>'<script>unexpected()</script>'],
    ['format'=>'fanvil','transport'=>'udp','user_agent'=>'Fanvil X6','override'=>true],
]]];
if (!ob_start()) { throw new RuntimeException('Unable to start the detected-phone render buffer.'); }
try {
	include __DIR__.'/../slsmassnotifyserver/views/detected_phones.php';
	$rendered=ob_get_clean();
} catch (Throwable $error) {
	ob_end_clean();
	throw $error;
}
if (!is_string($rendered)) { throw new RuntimeException('Unable to collect the detected-phone render.'); }
$document=new DOMDocument();$previous=libxml_use_internal_errors(true);$loaded=$document->loadHTML('<table>'.$rendered.'</table>');libxml_clear_errors();libxml_use_internal_errors($previous);
if (!$loaded) { throw new RuntimeException('Detected-phone fixture did not produce parseable HTML.'); }
$xpath=new DOMXPath($document);$rows=$xpath->query('//tbody/tr');$warnings=$xpath->query('//span[contains(@class,"label-warning")]');$scripts=$xpath->query('//script');
if ($rows===false || $warnings===false || $scripts===false || $rows->length!==1 || substr_count($rendered,'1000')!==1 || $warnings->length!==1
    || $scripts->length!==0 || strpos($rendered,'Yealink')===false || strpos($rendered,'Poly')===false
    || strpos($rendered,'always image')===false || strpos($rendered,'TLS')===false || strpos($rendered,'TCP')===false || strpos($rendered,'Fanvil')===false) {
    throw new RuntimeException('Mixed registrations hid known devices, used extension-wide formats, or failed escaping.');
}
echo "Help groups one extension row while preserving individual vendors, unknown devices, transports and escaping.\n";
