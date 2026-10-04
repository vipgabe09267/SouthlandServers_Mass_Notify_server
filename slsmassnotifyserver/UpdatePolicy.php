<?php
namespace SLS\MassNotify;

/** Portable update preferences. Publisher verification remains mandatory. */
final class UpdatePolicy
{
    public static function defaults(): array
    {
        return ['github_enabled'=>'0', 'repository'=>'vipgabe09267/SouthlandServers_Mass_Notify_server',
            'channel'=>'beta', 'pinned_version'=>'', 'window_start'=>'', 'window_end'=>'', 'rollout_delay_hours'=>0];
    }

    public static function errors(array $value): array
    {
        $errors=[];
        foreach (['channel','pinned_version','window_start','window_end'] as $key) {
            if (array_key_exists($key,$value) && !is_string($value[$key])) { $errors[]='Update '.$key.' must be text.'; }
        }
        if (isset($value['channel']) && !in_array($value['channel'],['stable','beta'],true)) { $errors[]='Choose the stable or beta update channel.'; }
        if (is_string($value['pinned_version'] ?? null) && $value['pinned_version']!==''
            && !preg_match('/^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}(?:-beta)?$/D',$value['pinned_version'])) { $errors[]='Pin a release such as 0.1.5-beta, or leave the version blank.'; }
        foreach (['window_start','window_end'] as $key) {
            if (is_string($value[$key] ?? null) && $value[$key]!=='' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value[$key])) { $errors[]='Update window times must use HH:MM in the PBX operating-system timezone.'; }
        }
        $start=$value['window_start'] ?? ''; $end=$value['window_end'] ?? '';
        if (($start==='') !== ($end==='') || ($start!=='' && $start===$end)) { $errors[]='Set both update window times with different start/end times, or leave both blank for any time.'; }
        if (is_string($start) && is_string($end) && preg_match('/^([0-9]{2}):([0-9]{2})$/D',$start,$a) && preg_match('/^([0-9]{2}):([0-9]{2})$/D',$end,$b)) {
            $minutes=((int)$b[1]*60+(int)$b[2]-(int)$a[1]*60-(int)$a[2]+1440)%1440;
            if ($minutes<60) { $errors[]='Allow at least one hour for the update window; automatic checks run hourly at minute 17.'; }
        }
        if (array_key_exists('rollout_delay_hours',$value) && (!is_int($value['rollout_delay_hours']) || $value['rollout_delay_hours']<0 || $value['rollout_delay_hours']>168)) { $errors[]='Update rollout delay must be a whole number from 0 to 168 hours.'; }
        if (($value['channel'] ?? '')==='stable' && is_string($value['pinned_version'] ?? null) && str_ends_with($value['pinned_version'],'-beta')) { $errors[]='A beta version pin requires the beta update channel.'; }
        return $errors;
    }

    public static function normalize(array $value): array
    {
        $result=array_replace(self::defaults(),array_intersect_key($value,self::defaults()));
        $result['repository']=self::defaults()['repository'];
        $result['github_enabled']=empty($value['github_enabled'])?'0':'1';
        return $result;
    }

    public static function form(array $input, array $existing): array
    {
        if (array_key_exists('rollout_delay_hours',$input)) {
            if (!is_string($input['rollout_delay_hours']) || !preg_match('/^(?:0|[1-9][0-9]{0,2})$/D',$input['rollout_delay_hours'])) {
                throw new \DomainException('Update rollout delay must be a whole number from 0 to 168 hours.');
            }
            $input['rollout_delay_hours']=(int)$input['rollout_delay_hours'];
        }
        $value=self::normalize(array_replace($existing,$input));
        $errors=self::errors($value);
        if ($errors) { throw new \DomainException(implode(' ',$errors)); }
        return $value;
    }
}
