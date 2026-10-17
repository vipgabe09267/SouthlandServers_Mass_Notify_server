#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || count($argv)!==1) { exit(64); }
ini_set('display_errors','0');
try {
    $library=dirname(__DIR__).'/EnterpriseClusterIntegration.php';
    if (!is_file($library)) { $library='/var/www/html/admin/modules/slsmassnotifyserver/EnterpriseClusterIntegration.php'; }
    require_once $library;
    require_once dirname($library).'/ConfigCrypto.php';
    $raw=stream_get_contents(STDIN,262145);
    if (!is_string($raw) || strlen($raw)>262144) { throw new DomainException('Cluster guard input exceeds its limit.'); }
    if (!class_exists('SlsAtomicJson',false)) {
        $jsonLibrary=__DIR__.'/sls_atomic_json.php';
        require_once is_file($jsonLibrary)?$jsonLibrary:__DIR__.'/sls_mass_notify/sls_atomic_json.php';
    }
    $input=\SlsAtomicJson::decodeObject($raw,32);
    $action=$input['action']??null;
    if ($action==='authority') {
        if (count($input)!==1) { throw new DomainException('Invalid authority check schema.'); }
    } elseif ($action==='begin') {
        if (count($input)!==4 || array_diff(array_keys($input),['action','channel','target','request'])
            || !is_string($input['channel']) || !is_string($input['target']) || !is_array($input['request'])) {
            throw new DomainException('Invalid effect begin schema.');
        }
    } elseif ($action==='finish') {
        if (count($input)!==3 || array_diff(array_keys($input),['action','claim','receipt'])
            || !is_array($input['claim']) || !is_array($input['receipt'])) { throw new DomainException('Invalid effect finish schema.'); }
    } else { throw new DomainException('Unsupported effect guard operation.'); }
    $settings=\FreePBX\modules\SlsConfigCrypto::readFile('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');
    if ($action==='authority') {
        echo json_encode(\SLS\MassNotify\EnterpriseClusterIntegration::authority($settings),JSON_THROW_ON_ERROR),"\n"; exit(0);
    }
    if (!\SLS\MassNotify\EnterpriseClusterConfig::enabled($settings)) { echo "{\"ok\":true,\"enabled\":false,\"claim\":null}\n"; exit(0); }
    $runtime=new \SLS\MassNotify\EnterpriseClusterRuntime($settings);
    if ($action==='begin') {
        $intent=\SLS\MassNotify\EnterpriseClusterIntegration::intent($settings,$input['request'],$input['channel'],$input['target']);
        $claim=$runtime->begin($intent); $output=['ok'=>true,'enabled'=>true,'claim'=>$claim];
    } else {
        $runtime->finish($input['claim'],$input['receipt']); $output=['ok'=>true,'enabled'=>true];
    }
    echo json_encode($output,JSON_THROW_ON_ERROR),"\n";
} catch (Throwable $error) {
    fwrite(STDERR,"SLS cluster: effect fenced or receipt uncertain.\n"); echo "{\"ok\":false,\"error\":\"cluster_effect_fenced_or_uncertain\"}\n"; exit(1);
}
