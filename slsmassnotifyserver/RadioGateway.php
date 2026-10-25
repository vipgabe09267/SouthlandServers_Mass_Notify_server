<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/IncidentConfig.php';

final class RadioGateway
{
    public static function catalog(): array
    {
        return [
            ['model'=>'JPS ACU-Z1','docs'=>'https://jps.com/products/acu-z1/'],
            ['model'=>'Omnitronics IPR110+','docs'=>'https://www.omnitronicsworld.com/radio-over-ip-roip/hardware-roip-gateways/ipr110-roip-gateway/'],
            ['model'=>'Cubic Vocality RoIP','docs'=>'https://www.cubic.com/support/defense/vocality'],
            ['model'=>'Generic SIP-to-radio gateway','docs'=>''],
        ];
    }
    private static function flag($value): string
    {
        if(!in_array($value,['0','1',0,1,false,true],true)){throw new \InvalidArgumentException('Radio enabled settings must be on or off.');}
        return empty($value)?'0':'1';
    }
    public static function normalize(array $value): array
    {
        $value=IncidentConfig::object($value,['enabled','devices'],'Radio gateways');$devices=[];$ids=[];$extensions=[];
        foreach(IncidentConfig::listOf($value['devices']??[],25,'Radio gateways') as $row){
            $row=IncidentConfig::object($row,['id','name','enabled','extension','model','channel_label','maximum_audio_seconds'],'Radio gateway');
            $id=IncidentConfig::identifier($row['id']??'','/^rad_[a-f0-9]{24}$/D','Radio gateway identifier');
            $extension=IncidentConfig::identifier($row['extension']??'','/^[0-9]{1,12}$/D','Radio gateway PJSIP extension');
            if(isset($ids[$id])||isset($extensions[$extension])){throw new \InvalidArgumentException('Each radio gateway needs a unique identifier and extension.');}
            $ids[$id]=true;$extensions[$extension]=true;$limit=$row['maximum_audio_seconds']??30;
            if(!is_int($limit)||$limit<5||$limit>120){throw new \InvalidArgumentException('Radio audio limits must be whole seconds from 5 through 120.');}
            $devices[]=['id'=>$id,'name'=>IncidentConfig::text($row['name']??'',100,'Radio gateway name'),
                'enabled'=>self::flag($row['enabled']??'0'),'extension'=>$extension,
                'model'=>IncidentConfig::text($row['model']??'',100,'Gateway model',true),
                'channel_label'=>IncidentConfig::text($row['channel_label']??'',80,'Radio channel label',true),'maximum_audio_seconds'=>$limit];
        }
        return ['enabled'=>self::flag($value['enabled']??'0'),'devices'=>$devices];
    }

    public static function select(array $settings, array $ids): array
    {
        $master=$settings['enterprise_integrations']??[];$config=self::normalize($master['radios']??[]);
        if(($master['enabled']??'0')!=='1'||$config['enabled']!=='1'){throw new \DomainException('Radio gateway integration is disabled.');}
        IncidentConfig::listOf($ids,25,'Radio gateway selection');
        if(!$ids||count(array_unique($ids,SORT_REGULAR))!==count($ids)){throw new \InvalidArgumentException('Select distinct enabled radio gateways.');}
        $known=array_column($config['devices'],null,'id');$bindings=[];$limit=120;
        foreach($ids as $id){
            IncidentConfig::identifier($id,'/^rad_[a-f0-9]{24}$/D','Radio gateway identifier');$row=$known[$id]??null;
            if(!$row||$row['enabled']!=='1'){throw new \DomainException('A selected radio gateway is disabled or unavailable.');}
            $bindings[$id]=['extension'=>$row['extension'],'fingerprint'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR))];
            $limit=min($limit,$row['maximum_audio_seconds']);
        }
        return ['schema'=>1,'bindings'=>$bindings,'maximum_audio_seconds'=>$limit];
    }

    public static function current(array $context, array $settings): int
    {
        if(array_diff(array_keys($context),['schema','bindings','maximum_audio_seconds'])||($context['schema']??null)!==1
            ||!is_array($context['bindings']??null)||!$context['bindings']||array_is_list($context['bindings'])||count($context['bindings'])>25){
            throw new \DomainException('The frozen radio gateway selection is invalid. No radio audio was submitted.');
        }
        $current=self::select($settings,array_keys($context['bindings']));
        if($current!==$context){throw new \DomainException('A radio gateway profile changed after submission. Review the saved profile before sending a new page.');}
        return $current['maximum_audio_seconds'];
    }

    public static function forExtensions(array $settings,array $extensions): ?array
    {
        $master=$settings['enterprise_integrations']??[];
        if(($master['enabled']??'0')!=='1'||($master['radios']['enabled']??'0')!=='1'){return null;}
        $config=self::normalize($master['radios']);$selected=[];
        foreach($config['devices'] as $row){if($row['enabled']==='1'&&in_array($row['extension'],$extensions,true)){$selected[]=$row['id'];}}
        return $selected?self::select($settings,$selected):null;
    }
}
