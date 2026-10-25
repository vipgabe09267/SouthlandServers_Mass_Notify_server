<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/IncidentConfig.php';

/** Independent OAuth renewal settings; existing per-provider access tokens stay compatible. */
final class MeetingAccounts
{
    public const PROVIDERS = ['zoom','google_meet','webex'];

    public static function defaults(): array
    {
        return ['oauth_refresh_enabled'=>'0','oauth_client_id'=>'','oauth_client_secret'=>'',
            'oauth_refresh_token'=>'','oauth_expires_at'=>0];
    }

    public static function provider($value): string
    {
        if (!is_string($value) || !in_array($value,self::PROVIDERS,true)) {
            throw new \InvalidArgumentException('Choose Zoom, Google Meet or Webex for this meeting.');
        }
        return $value;
    }

    public static function tokenField(string $provider): string
    {
        return self::provider($provider)==='google_meet' ? 'google_access_token' : $provider.'_access_token';
    }

    public static function profiles(array $meeting): array
    {
        $defaultProvider=self::provider($meeting['provider']??'zoom');
        $legacy=!array_key_exists('oauth_profiles',$meeting);
        $profiles=IncidentConfig::object($meeting['oauth_profiles']??[],self::PROVIDERS,'Meeting OAuth accounts');
        $out=[];
        foreach (self::PROVIDERS as $provider) {
            $profile=IncidentConfig::object($profiles[$provider]??[],array_keys(self::defaults()),$provider.' OAuth account');
            if ($legacy && $defaultProvider===$provider) {
                $profile=array_intersect_key($meeting,self::defaults());
            }
            $profile=array_replace(self::defaults(),$profile);
            if (!in_array($profile['oauth_refresh_enabled'],['0','1',0,1,false,true],true)) {
                throw new \InvalidArgumentException('OAuth renewal must be enabled or disabled.');
            }
            $profile['oauth_refresh_enabled']=empty($profile['oauth_refresh_enabled'])?'0':'1';
            foreach (['oauth_client_id','oauth_client_secret','oauth_refresh_token'] as $field) {
                if (!is_string($profile[$field]) || strlen($profile[$field])>8192 || preg_match('/[\x00-\x1f\x7f]/',$profile[$field])) {
                    throw new \InvalidArgumentException('The '.$provider.' OAuth credential is invalid.');
                }
            }
            if (!is_int($profile['oauth_expires_at']) || $profile['oauth_expires_at']<0 || $profile['oauth_expires_at']>4102444800) {
                throw new \InvalidArgumentException('The '.$provider.' token expiry is invalid.');
            }
            if ($profile['oauth_refresh_enabled']==='1' && ($profile['oauth_client_id']==='' || $profile['oauth_client_secret']==='' || $profile['oauth_refresh_token']==='')) {
                throw new \InvalidArgumentException('Automatic '.$provider.' token renewal requires its client ID, client secret and refresh token.');
            }
            $out[$provider]=$profile;
        }
        return $out;
    }

    public static function select(array $meeting, ?string $provider=null): array
    {
        $provider=self::provider($provider??($meeting['provider']??'zoom'));
        $profile=self::profiles($meeting)[$provider];
        $out=array_replace($meeting,$profile,['provider'=>$provider]);
        unset($out['oauth_profiles']);
        foreach (self::PROVIDERS as $other) {
            if ($other!==$provider) $out[self::tokenField($other)]='';
        }
        return $out;
    }

    public static function replace(array $meeting, array $selected): array
    {
        $provider=self::provider($selected['provider']??null);
        $meeting['oauth_profiles']=self::profiles($meeting);
        $meeting['oauth_profiles'][$provider]=array_intersect_key($selected,self::defaults());
        $field=self::tokenField($provider);
        $meeting[$field]=$selected[$field]??'';
        if ($meeting['provider']===$provider) $meeting=array_replace($meeting,$meeting['oauth_profiles'][$provider]);
        return $meeting;
    }
}
