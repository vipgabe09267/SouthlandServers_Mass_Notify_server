<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/IncidentConfig.php';

final class EnterpriseOperationsConfig
{
    public static function defaults(): array
    {
        return ['schema'=>1,'enabled'=>false,'floorplans'=>['enabled'=>false,'plans'=>[]],
            'drills'=>['enabled'=>false,'assignments'=>[]],'shift_routing'=>['enabled'=>false,'routes'=>[]],
            'dual_approval'=>['enabled'=>false,'policies'=>[]]];
    }
    public static function normalize($value): array
    {
        $v = IncidentConfig::object($value, array_keys(self::defaults()), 'Enterprise operations');
        if (($v['schema'] ?? 1) !== 1) { throw new \InvalidArgumentException('Enterprise operations schema is unsupported.'); }
        $out = self::defaults(); $out['enabled'] = IncidentConfig::flag($v['enabled'] ?? false, 'Enterprise operations');
        foreach (['floorplans'=>'plans','drills'=>'assignments','shift_routing'=>'routes','dual_approval'=>'policies'] as $section=>$list) {
            $input = IncidentConfig::object($v[$section] ?? [], ['enabled',$list], $section);
            $out[$section]['enabled'] = IncidentConfig::flag($input['enabled'] ?? false, $section);
            $seen = [];
            foreach (IncidentConfig::listOf($input[$list] ?? [], $section === 'drills' ? 100 : 25, $section) as $row) {
                $entry = self::$section($row);
                if (isset($seen[$entry['id']])) { throw new \InvalidArgumentException('Duplicate '.$section.' identifier.'); }
                $seen[$entry['id']] = true; $out[$section][$list][] = $entry;
            }
        }
        return $out;
    }
    private static function base($value, array $fields, string $prefix): array
    {
        $v = IncidentConfig::object($value, array_merge(['id','name','enabled'], $fields), $prefix);
        return ['id'=>IncidentConfig::identifier($v['id'] ?? '', '/^'.$prefix.'_[a-f0-9]{24}$/D', $prefix.' identifier'),
            'name'=>IncidentConfig::text($v['name'] ?? '', 80, $prefix.' name'),
            'enabled'=>IncidentConfig::flag($v['enabled'] ?? false, $prefix)] + $v;
    }
    private static function floorplans($value): array
    {
        $v = self::base($value, ['site_id','image_id','markers'], 'plan');
        $v['site_id'] = IncidentConfig::identifier($v['site_id'] ?? '', '/^[A-Za-z0-9_-]{1,64}$/D', 'Floor plan site');
        $v['image_id'] = IncidentConfig::identifier($v['image_id'] ?? '', '/^img_[a-f0-9]{64}$/D', 'Private image');
        $markers=[]; $seen=[];
        foreach (IncidentConfig::listOf($v['markers'] ?? [], 250, 'Floor plan markers') as $m) {
            $m = IncidentConfig::object($m, ['id','label','kind','target_id','x','y'], 'Floor plan marker');
            $id=IncidentConfig::identifier($m['id']??'', '/^mark_[a-f0-9]{24}$/D', 'Marker identifier');
            if (isset($seen[$id]) || !in_array($m['kind']??'', ['location','phone','desktop','sensor'],true)) { throw new \InvalidArgumentException('Marker identifiers must be unique and use a supported device kind.'); }
            foreach (['x','y'] as $axis) { if ((!is_int($m[$axis]??null) && !is_float($m[$axis]??null)) || !is_finite((float)$m[$axis]) || $m[$axis]<0 || $m[$axis]>100) { throw new \InvalidArgumentException('Marker coordinates must be percentages from 0 to 100.'); } }
            $m['id']=$id; $m['label']=IncidentConfig::text($m['label']??'',80,'Marker label');
            $m['target_id']=IncidentConfig::identifier($m['target_id']??'', '/^[A-Za-z0-9_.@-]{1,80}$/D','Marker target');
            $seen[$id]=true; $markers[]=$m;
        }
        $v['markers']=$markers; return $v;
    }
    private static function drills($value): array
    {
        $v=self::base($value,['template_id','site_ids','owner_id','reviewer_ids','due_at','objectives'],'drill');
        $v['template_id']=IncidentConfig::identifier($v['template_id']??'', '/^tpl_[a-f0-9]{24}$/D','Drill template');
        $v['site_ids']=self::selectors($v['site_ids']??[], '/^[A-Za-z0-9_-]{1,64}$/D',25,'Drill sites');
        $v['owner_id']=self::actor($v['owner_id']??'');
        $v['reviewer_ids']=self::actors($v['reviewer_ids']??[]);
        $v['due_at']=self::timestamp($v['due_at']??'', 'Drill deadline');
        $v['objectives']=[];
        foreach (IncidentConfig::listOf($value['objectives']??[],25,'Drill objectives') as $text) { $v['objectives'][]=IncidentConfig::text($text,200,'Drill objective'); }
        return $v;
    }
    private static function shift_routing($value): array
    {
        $v=self::base($value,['template_ids','timezone','days','start','end','delivery'],'shift');
        $v['template_ids']=self::selectors($v['template_ids']??[], '/^tpl_[a-f0-9]{24}$/D',50,'Shift incident templates');
        if (!$v['template_ids']) { throw new \InvalidArgumentException('Select at least one incident template for each shift.'); }
        $v['timezone']=IncidentConfig::text($v['timezone']??'',80,'Shift timezone');
        if (!in_array($v['timezone'],\DateTimeZone::listIdentifiers(),true)) { throw new \InvalidArgumentException('Choose an IANA timezone for this shift.'); }
        $v['days']=IncidentConfig::listOf($v['days']??[],7,'Shift weekdays');
        if (!$v['days'] || count(array_unique($v['days'],SORT_REGULAR))!==count($v['days'])) { throw new \InvalidArgumentException('Select unique weekdays for the shift.'); }
        foreach ($v['days'] as $day) { if (!is_int($day)||$day<1||$day>7) { throw new \InvalidArgumentException('Shift weekdays use integers 1 (Monday) through 7 (Sunday).'); } }
        foreach (['start','end'] as $key) { $v[$key]=IncidentConfig::identifier($v[$key]??'', '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D','Shift '.$key); }
        if ($v['start']===$v['end']) { throw new \InvalidArgumentException('Shift start and end must differ. Use two shifts for a full day.'); }
        $v['delivery']=IncidentConfig::delivery($v['delivery']??[]);
        if (!IncidentConfig::hasAudience($v['delivery'])) { throw new \InvalidArgumentException('A shift must select a delivery audience.'); }
        return $v;
    }
    private static function dual_approval($value): array
    {
        $v=self::base($value,['channels','approver_ids','expires_seconds'],'approval');
        $v['channels']=self::selectors($v['channels']??[], '/^(?:phones|desktops|webhooks|voice_recipient_ids|email_recipient_ids|sms_recipient_ids)$/D',6,'Approval channels');
        $v['approver_ids']=self::actors($v['approver_ids']??[]);
        if (!$v['channels'] || count($v['approver_ids'])<2) { throw new \InvalidArgumentException('Approval policies require channels and at least two distinct authorized people.'); }
        $v['expires_seconds']=$v['expires_seconds']??300;
        if (!is_int($v['expires_seconds']) || $v['expires_seconds']<60 || $v['expires_seconds']>900) { throw new \InvalidArgumentException('Approval expiry must be 60–900 seconds.'); }
        return $v;
    }
    public static function actor($value): string { return IncidentConfig::identifier($value,'/^(?:pbx:[A-Za-z0-9_.@-]{1,80}|api_[a-f0-9]{24})$/D','Authorized person'); }
    private static function selectors($value,string $pattern,int $limit,string $label): array
    {
        $rows=IncidentConfig::listOf($value,$limit,$label);foreach($rows as $row){IncidentConfig::identifier($row,$pattern,$label);}
        if(count(array_unique($rows))!==count($rows)){throw new \InvalidArgumentException($label.' must not contain duplicates.');}return $rows;
    }
    private static function actors($value): array { $rows=IncidentConfig::listOf($value,100,'Authorized people');foreach($rows as $row){self::actor($row);}if(count(array_unique($rows))!==count($rows)){throw new \InvalidArgumentException('Authorized people must be unique.');}return $rows; }
    public static function timestamp($value,string $label): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$value)) { throw new \InvalidArgumentException($label.' requires a timezone-aware timestamp.'); }
        $date=new \DateTimeImmutable($value);$errors=\DateTimeImmutable::getLastErrors();if($errors && ($errors['warning_count']||$errors['error_count'])){throw new \InvalidArgumentException($label.' is invalid.');}return $date->format('c');
    }
    public static function activeShift(array $settings,string $template,int $now): ?array
    {
        $cfg=self::normalize($settings['enterprise_operations']??[]);
        if (!$cfg['enabled'] || !$cfg['shift_routing']['enabled']) { return null; }
        $matches=[];
        foreach ($cfg['shift_routing']['routes'] as $route) {
            if (!$route['enabled'] || !in_array($template,$route['template_ids'],true)) { continue; }
            $local=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone($route['timezone']));$time=$local->format('H:i');$day=(int)$local->format('N');
            $overnight=$route['start']>$route['end'];
            if ($overnight && $time<$route['end']) { $day=$day===1?7:$day-1; }
            $active=$overnight?($time>=$route['start']||$time<$route['end']):($time>=$route['start']&&$time<$route['end']);
            if ($active && in_array($day,$route['days'],true)) { $matches[]=$route; }
        }
        if (count($matches)>1) { throw new \DomainException('Multiple active shifts match this template. Resolve the overlap before sending.'); }
        if (!$matches) {
            foreach ($cfg['shift_routing']['routes'] as $route) { if ($route['enabled']&&in_array($template,$route['template_ids'],true)){throw new \DomainException('No on-duty shift covers this template at the current local time. No announcement was submitted.');} }
        }
        return $matches[0]??null;
    }
    public static function policies(array $settings,array $request): array
    {
        $cfg=self::normalize($settings['enterprise_operations']??[]);
        if (!$cfg['enabled'] || !$cfg['dual_approval']['enabled']) { return []; }
        return array_values(array_filter($cfg['dual_approval']['policies'],static function(array $p)use($request):bool{if(!$p['enabled']){return false;}foreach($p['channels'] as $channel){if(!empty($request[$channel])){return true;}}return false;}));
    }
}
