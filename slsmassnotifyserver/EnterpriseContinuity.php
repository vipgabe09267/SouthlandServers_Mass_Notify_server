<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/OperatorAuth.php';
require_once __DIR__.'/bin/sls_mass_notify/sls_runtime_state.php';

/** Configuration-only continuity review. It opens no network or device connection. */
final class EnterpriseContinuity
{
    private static function enabled($value): bool { return in_array($value,[true,1,'1'],true); }
    public static function report(array $settings): array
    {
        $running=RuntimeState::running($settings);$notifications=$running&&$settings!==[];$integrations=$settings['enterprise_integrations']??[];
        $labs=$notifications&&self::enabled($integrations['enabled']??false);$rows=[];
        $add=static function(string $id,string $name,bool $enabled,string $requires,string $offline)use(&$rows):void{
            $rows[]=['id'=>$id,'name'=>$name,'enabled'=>$enabled,'requires'=>$requires,'offline_behavior'=>$offline,'runtime_acceptance'=>'not_measured'];
        };
        $add('phones','Internal phone audio', $notifications,'Running Asterisk, fresh authenticated collector, online PJSIP contacts and locally installed tones/voice model.',
            'May operate on the local PBX/LAN without Internet. Registration, auto-answer and complete playback require a local drill.');
        $add('desktops','Desktop app',$notifications&&!empty($settings['desktop_clients']),
            'Clients must reach the configured PBX HTTPS origin and authenticate with current credentials.',
            'Local delivery requires local DNS/routing and a reachable certificate-valid origin. Disconnected clients intentionally skip missed announcements.');
        $add('speakers','SIP speakers',$labs&&self::enabled($integrations['speakers']['enabled']??false),
            'Saved online PBX extensions, approved firmware and configured device auto-answer.',
            'May use local LAN audio. SIP acceptance does not confirm audibility; verify offline and busy device results.');
        $add('connectors','ntfy / Gotify',$labs&&self::enabled($integrations['connectors']['enabled']??false),
            'Saved HTTPS service, trusted certificate, pinned private address and scoped token.',
            'Self-hosted services may remain reachable on the LAN. Their downstream browser/mobile delivery can still depend on external push infrastructure.');
        $add('external_voice','External calls',$notifications&&self::enabled($settings['outbound_voice']['enabled']??false),
            'Authorized outbound route, trunk, carrier and answer-gated playback.',
            'Availability depends on the trunk and carrier. A local PBX cannot guarantee service during an Internet or carrier outage.');
        $add('email','Email',$notifications&&self::enabled($settings['announcement_email']['enabled']??false),
            'Working local Postfix and the recipient mail route.',
            'Local queue acceptance can continue; remote SMTP delivery may wait for connectivity. Accepted mail is not a delivery or human receipt.');
        $add('sms','SMS / MMS',$notifications&&self::enabled($settings['announcement_sms']['enabled']??false),
            'Authorized provider HTTPS API, consented recipients and provider connectivity.',
            'Cloud submission requires connectivity. Uncertain or failed submissions are never automatically resent.');
        $add('browser_push','Browser Web Push',$notifications&&self::enabled($settings['subscriber_browser']['enabled']??false)&&self::enabled($settings['subscriber_browser']['push']['enabled']??false),
            'Verified enrollment, browser permission, valid VAPID key and browser push-service HTTPS access.',
            'Requires the browser vendor’s external push service. Expired alerts are discarded; no local push-service substitute is claimed.');
        $add('weather','Weather and lightning',$notifications&&((self::enabled($settings['enabled']??false)&&!empty($settings['nws_zones']))||self::enabled($settings['xweather']['enabled']??false)),
            'Fresh authorized provider data and a currently valid alert.',
            'New remote data requires connectivity. Delayed, expired or superseded alerts must not become a fresh warning after recovery.');
        $add('identity','Enterprise identity',self::enabled($settings['enterprise_identity']['enabled']??false),
            'Reachable saved IdP and matching local operator grant.',
            'New SSO sessions depend on the IdP. Keep a tested local password/TOTP administrator and FreePBX recovery access.');
        $add('sensors','Enrolled physical inputs',$labs&&self::enabled($integrations['sensors']['enabled']??false),
            'Reachable local HTTPS trigger route, signature, current enrollment and fresh sensor heartbeat.',
            'Can operate on a reachable LAN. Heartbeat freshness does not prove sensor accuracy or supervise a life-safety panel.');
        $local=0;foreach($settings['operator_access']['accounts']??[] as $account){
            if(($account['source']??'')==='portal'&&self::enabled($account['enabled']??false)&&($account['role']??'')==='administrator'&&OperatorAuth::ready($account)){$local++;}
        }
        $portal=self::enabled($settings['operator_access']['enabled']??false)&&self::enabled($settings['operator_access']['portal_enabled']??false);
        return ['schema'=>1,'generated_at'=>gmdate('c'),'basis'=>'saved_configuration_only','notifications_running'=>$running,'channels'=>$rows,
            'local_recovery'=>['portal_enabled'=>$portal,'enrolled_local_administrators'=>$local,'ready_for_local_login'=>$portal&&$local>0,
                'required_drill'=>'Sign in with a local administrator password and TOTP while the IdP/WAN is isolated in a separate lab. Keep FreePBX recovery access available.'],
            'cluster_scope'=>'Notification coordination only. Matching PBX configuration, Asterisk/trunks and complete PBX failover are not qualified by this report.',
            'qualification'=>'This review sends no alert and does not simulate an outage on the live PBX. Run acceptance drills in an isolated test environment.'];
    }
}
