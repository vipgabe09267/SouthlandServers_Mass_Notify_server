<?php
declare(strict_types=1);
namespace SLS\MassNotify;

require_once __DIR__ . '/ScheduleCalendar.php';

final class ScheduleProgrammes
{
    public static function defaults(): array { return ['schema'=>1, 'enabled'=>false, 'calendars'=>[]]; }

    private static function date($value): string
    {
        if (!is_string($value) || !preg_match('/^(20\d\d|21\d\d)-(\d{2})-(\d{2})$/D', $value, $p)
            || !checkdate((int)$p[2], (int)$p[3], (int)$p[1])) {
            throw new \DomainException('Programme dates must use YYYY-MM-DD, from 2000 through 2199.');
        }
        return $value;
    }

    private static function object($value, array $fields): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value), $fields)) {
            throw new \DomainException('The shared calendar contains unsupported or invalid fields.');
        }
        return $value;
    }

    public static function programme($value): array
    {
        $row = self::object($value, ['id','name','timezone','cycle_start','cycle_mode','day_types','date_types']);
        if (!is_string($row['id'] ?? null) || !preg_match('/^cal_[a-f0-9]{24}$/D', $row['id'])) {
            throw new \DomainException('The shared calendar identifier is invalid.');
        }
        if (!is_string($row['name'] ?? null) || !mb_check_encoding($row['name'], 'UTF-8')
            || trim($row['name']) === '' || mb_strlen($row['name']) > 80 || preg_match('/\p{C}/u', $row['name'])) {
            throw new \DomainException('Enter a shared calendar name of 1–80 characters.');
        }
        $zone = $row['timezone'] ?? null;
        if (!is_string($zone) || !in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \DomainException('Choose an IANA timezone for the shared calendar.');
        }
        $types = $row['day_types'] ?? null;
        if (!is_array($types) || !array_is_list($types) || !$types || count($types) > 12) {
            throw new \DomainException('Define 1–12 day types in their repeating order, such as A and B.');
        }
        foreach ($types as $type) {
            if (!is_string($type) || !preg_match('/^[A-Z][A-Z0-9_-]{0,15}$/D', $type)) {
                throw new \DomainException('Day types use 1–16 uppercase letters, digits, underscores or hyphens.');
            }
        }
        if (count(array_unique($types)) !== count($types)) { throw new \DomainException('Day types must be unique.'); }
        $mode = $row['cycle_mode'] ?? 'school_days';
        if (!in_array($mode, ['school_days','weeks'], true)) { throw new \DomainException('Choose a school-day or weekly programme cycle.'); }
        $overrides = $row['date_types'] ?? [];
        if (!is_array($overrides) || !array_is_list($overrides) || count($overrides) > 100) {
            throw new \DomainException('A programme supports at most 100 whole-day substitutions.');
        }
        $out = ['id'=>$row['id'], 'name'=>trim($row['name']), 'timezone'=>$zone,
            'cycle_start'=>self::date($row['cycle_start'] ?? null), 'cycle_mode'=>$mode,
            'day_types'=>$types, 'date_types'=>[]];
        $seen = [];
        foreach ($overrides as $override) {
            $override = self::object($override, ['date','day_type']);
            $date = self::date($override['date'] ?? null); $type = $override['day_type'] ?? null;
            if (isset($seen[$date]) || !is_string($type) || ($type !== '' && !in_array($type, $types, true))) {
                throw new \DomainException('Each substitution needs a unique date and a saved day type, or an empty type to skip the entire day.');
            }
            $seen[$date] = true; $out['date_types'][] = ['date'=>$date, 'day_type'=>$type];
        }
        usort($out['date_types'], static fn($a,$b)=>strcmp($a['date'],$b['date']));
        return $out;
    }

    public static function normalize($value): array
    {
        $value = self::object($value, ['schema','enabled','calendars']);
        if (($value['schema'] ?? 1) !== 1 || !is_bool($value['enabled'] ?? false)) {
            throw new \DomainException('Shared calendars require schema 1 and a boolean enabled flag.');
        }
        $rows = $value['calendars'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 25) {
            throw new \DomainException('Save at most 25 shared calendars.');
        }
        $out = self::defaults(); $out['enabled'] = $value['enabled'] ?? false; $seen = [];
        foreach ($rows as $row) {
            $row = self::object($row, ['id','name','timezone','cycle_start','cycle_mode','day_types','date_types','calendar']);
            $calendar = ScheduleCalendar::validate($row['calendar'] ?? []);
            if (isset($calendar['programme'])) { throw new \DomainException('A shared calendar cannot include another programme snapshot.'); }
            unset($row['calendar']); $programme = self::programme($row);
            if (isset($seen[$programme['id']])) { throw new \DomainException('Shared calendar identifiers must be unique.'); }
            if ($programme['cycle_start'] > $calendar['until']) { throw new \DomainException('The programme starts after its calendar ends.'); }
            $utc = new \DateTimeZone('UTC');
            $span = (new \DateTimeImmutable($calendar['until'], $utc))->getTimestamp() - (new \DateTimeImmutable($programme['cycle_start'], $utc))->getTimestamp();
            if ($span > 5*366*86400) { throw new \DomainException('Shared programmes are limited to five years.'); }
            foreach ($programme['date_types'] as $override) {
                if ($override['date'] < $programme['cycle_start'] || $override['date'] > $calendar['until']) {
                    throw new \DomainException('Whole-day substitutions must fall within the programme dates.');
                }
            }
            self::validateDateSubstitutions($calendar + ['programme'=>$programme + ['selected_types'=>$programme['day_types']]]);
            $seen[$programme['id']] = true; $out['calendars'][] = $programme + ['calendar'=>$calendar];
        }
        return $out;
    }

    public static function snapshot(array $settings, string $id, array $selected): array
    {
        $settings = self::normalize($settings);
        if (!$settings['enabled']) { throw new \DomainException('Enable Shared calendars before using a saved programme.'); }
        foreach ($settings['calendars'] as $row) {
            if ($row['id'] !== $id) { continue; }
            $calendar = $row['calendar']; unset($row['calendar']);
            return $calendar + ['programme'=>self::snapshotProgramme($row + ['selected_types'=>$selected])];
        }
        throw new \DomainException('The shared calendar no longer exists. Choose an available programme.');
    }

    public static function snapshotProgramme($value): array
    {
        $value = self::object($value, ['id','name','timezone','cycle_start','cycle_mode','day_types','date_types','selected_types']);
        $selected = $value['selected_types'] ?? null; unset($value['selected_types']);
        $out = self::programme($value);
        if (!is_array($selected) || !array_is_list($selected) || !$selected || count($selected) > 12) {
            throw new \DomainException('Select at least one distinct programme day type for this announcement.');
        }
        foreach ($selected as $type) {
            if (!is_string($type) || !in_array($type, $out['day_types'], true)) {
                throw new \DomainException('The selected programme day type is unavailable.');
            }
        }
        if (count(array_unique($selected)) !== count($selected)) {
            throw new \DomainException('Select distinct programme day types.');
        }
        return $out + ['selected_types'=>$selected];
    }

    public static function visualPreview(array $row,string $focus,string $view): array
    {
        $row=self::normalize(['calendars'=>[$row]])['calendars'][0];$focus=self::date($focus);
        if(!in_array($view,['month','week','list'],true)){throw new \DomainException('Select month, week or list view.');}
        if(substr($focus,0,7)<substr($row['cycle_start'],0,7)||substr($focus,0,7)>substr($row['calendar']['until'],0,7)){
            throw new \DomainException('Choose a date within this programme’s calendar months.');
        }
        $utc=new \DateTimeZone('UTC');$at=new \DateTimeImmutable($focus,$utc);
        $first=$view==='week'?$at->modify('monday this week'):$at->modify('first day of this month')->modify('monday this week');
        $programme=$row;unset($programme['calendar']);
        $types=self::dateTypes($row['calendar']+['programme'=>self::snapshotProgramme($programme+['selected_types'=>$row['day_types']])]);
        $overrides=array_column($row['calendar']['overrides'],'time','date');$days=[];$count=$view==='week'?7:42;
        for($i=0;$i<$count;$i++){
            $date=$first->modify('+'.$i.' days')->format('Y-m-d');$weekday=(int)$first->modify('+'.$i.' days')->format('N');
            $status='outside';$reason='';$type=null;
            if($date>=$row['cycle_start']&&$date<=$row['calendar']['until']){
                $status='inactive';
                foreach($row['calendar']['exclusions'] as $range){if($date>=$range['start']&&$date<=$range['end']){$status='holiday';$reason=$range['reason']??'';break;}}
                if(array_key_exists($date,$types)){$type=$types[$date];$status=$type===''?'skipped':'active';}
            }
            $days[]=['date'=>$date,'weekday'=>$weekday,'day_type'=>$type,'status'=>$status,'reason'=>$reason,
                'time_override'=>$status==='active'?($overrides[$date]??''):'','editable'=>array_key_exists($date,$types)];
        }
        return ['success'=>true,'timezone'=>$row['timezone'],'focus'=>$focus,'view'=>$view,'days'=>$days,
            'minimum'=>$row['cycle_start'],'maximum'=>$row['calendar']['until'],'day_types'=>$row['day_types']];
    }

    public static function dateTypes(array $calendar): array
    {
        $p = self::snapshotProgramme($calendar['programme']);
        $cursor = new \DateTimeImmutable($p['cycle_start'], new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($calendar['until'], new \DateTimeZone('UTC'));
        if ($cursor > $end || $end->getTimestamp()-$cursor->getTimestamp() > 5*366*86400) {
            throw new \DomainException('Programme dates must span no more than five years.');
        }
        $overrides = array_column($p['date_types'], 'day_type', 'date'); $out = []; $index = 0;
        $anchor = $cursor->modify('monday this week');
        for (; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            if (!in_array((int)$cursor->format('N'), $calendar['weekdays'], true)) { continue; }
            foreach ($calendar['exclusions'] as $range) {
                if ($date >= $range['start'] && $date <= $range['end']) { continue 2; }
            }
            $cycle = $p['cycle_mode'] === 'weeks' ? intdiv($cursor->getTimestamp()-$anchor->getTimestamp(), 7*86400) : $index;
            $out[$date] = $overrides[$date] ?? $p['day_types'][$cycle % count($p['day_types'])];
            ++$index;
        }
        foreach ($overrides as $date=>$type) {
            if (!array_key_exists($date, $out)) { throw new \DomainException('A whole-day substitution falls on an excluded or unavailable programme date.'); }
        }
        return $out;
    }

    /** Validate at most 100 substitutions; never expand a calendar during config reads. */
    public static function validateDateSubstitutions(array $calendar): void
    {
        $p=$calendar['programme'];$utc=new \DateTimeZone('UTC');
        $start=new \DateTimeImmutable($p['cycle_start'],$utc);$end=new \DateTimeImmutable($calendar['until'],$utc);
        if($start>$end||$end->getTimestamp()-$start->getTimestamp()>5*366*86400){throw new \DomainException('Programme dates must span no more than five years.');}
        foreach($p['date_types']as $override){
            $date=$override['date'];
            if($date<$p['cycle_start']||$date>$calendar['until']||!in_array((int)(new \DateTimeImmutable($date,$utc))->format('N'),$calendar['weekdays'],true)){
                throw new \DomainException('A whole-day substitution falls outside the available programme dates.');
            }
            foreach($calendar['exclusions']as $range){
                if($date>=$range['start']&&$date<=$range['end']){throw new \DomainException('A whole-day substitution falls on an excluded programme date.');}
            }
        }
    }
}
