<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Reviewed metadata on an existing audience, never imported consent or grants. */
if (!class_exists(PersonProfiles::class, false)) {
final class PersonProfiles
{
    public const LANGUAGES=['en'=>'English','es'=>'Spanish','fr'=>'French','de'=>'German','pt'=>'Portuguese'];
    public static function normalize($value): array
    {
        if(!is_array($value)||array_diff(array_keys($value),['directory_person_id','language','ownership_reviewed','reviewed_at'])){
            throw new \DomainException('Person profile contains unsupported fields.');
        }
        $id=$value['directory_person_id']??'';$language=$value['language']??'en';
        if(!is_string($id)||($id!==''&&!preg_match('/^dirp_[a-f0-9]{40}$/D',$id))
            ||!is_string($language)||!isset(self::LANGUAGES[$language])||($value['ownership_reviewed']??null)!==true
            ||!is_int($value['reviewed_at']??null)||$value['reviewed_at']<1){
            throw new \DomainException('Confirm ownership of the selected devices and choose a supported person language.');
        }
        return ['directory_person_id'=>$id,'language'=>$language,'ownership_reviewed'=>true,'reviewed_at'=>$value['reviewed_at']];
    }
    public static function people(array $settings): array
    {
        $people=[];
        foreach($settings['directory_sync']['sources']??[] as $source){
            if(!is_array($source)||($source['enabled']??false)!==true||!is_string($source['id']??null)){continue;}
            foreach($source['records']??[] as $row){
                if(!is_array($row)||($row['active']??false)!==true||!is_string($row['external_id']??null)){continue;}
                $id='dirp_'.substr(hash('sha256',$source['id']."\0".$row['external_id']),0,40);
                $people[$id]=['id'=>$id,'name'=>(string)($row['name']??$id),'phone_candidates'=>$row['phone_candidates']??[]];
            }
        }
        return $people;
    }
    public static function active(array $group,array $settings): bool
    {
        if(!array_key_exists('person_profile',$group)){return true;}
        try{$profile=self::normalize($group['person_profile']);}catch(\DomainException $error){return false;}
        return $profile['directory_person_id']===''||isset(self::people($settings)[$profile['directory_person_id']]);
    }
    public static function affected(array $settings,array $personIds): array
    {
        $out=[];$ids=array_fill_keys($personIds,true);
        foreach($settings['announcement_groups']??[] as $group){
            if(!isset($ids[$group['person_profile']['directory_person_id']??''])){continue;}
            $members=[];
            foreach(['extensions','desktop_clients','voice_recipient_ids','sms_recipient_ids','email_recipient_ids','webhook_ids','browser_recipient_ids','connector_ids'] as $key){$members[$key]=$group[$key]??[];}
            $out[]=['id'=>$group['id'],'name'=>$group['name'],'members'=>$members];
        }
        return $out;
    }
}

}
