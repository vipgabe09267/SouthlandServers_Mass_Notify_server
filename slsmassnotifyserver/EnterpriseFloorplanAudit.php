<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityStore.php';
require_once __DIR__.'/EnterpriseFloorplanStore.php';

/** Append-only, hash-chained evidence for deliberate floor-plan retirement. */
final class EnterpriseFloorplanAudit
{
    private const LEDGER='floorplan_retirements';
    private const LIMIT=5000;
    private EnterpriseIdentityStore $store;

    public function __construct(string $directory='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-operations/archive')
    {
        $this->store=new EnterpriseIdentityStore($directory);
    }

    private static function eventHash(array $event): string
    {
        unset($event['entry_hash']);
        return hash('sha256',json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    private static function validate(array $events): array
    {
        if(count($events)>self::LIMIT){
            throw new \RuntimeException('Floor-plan retirement audit exceeds its allocation. Preserve it before retiring another image.');
        }
        $previous=str_repeat('0',64);
        $sequence=0;
        $requests=[];
        $outcomes=[];
        foreach($events as $key=>$event){
            $sequence++;
            if(!is_string($key)||!preg_match('/^fpa_[a-f0-9]{32}$/D',$key)||!is_array($event)
                ||array_diff(array_keys($event),['schema','sequence','event_id','kind','at','actor','image_id','bytes','reference_hash','request_id','outcome','previous_hash','entry_hash'])
                ||count($event)!==13||($event['schema']??null)!==1||($event['sequence']??null)!==$sequence
                ||($event['event_id']??null)!==$key||!in_array($event['kind']??null,['retirement_requested','retirement_completed','retirement_failed','retirement_uncertain'],true)
                ||!is_string($event['at']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D',$event['at'])
                ||!is_string($event['actor']??null)||!preg_match('/^[\x20-\x7e]{1,160}$/D',$event['actor'])
                ||!is_string($event['image_id']??null)||!preg_match('/^img_[a-f0-9]{64}$/D',$event['image_id'])
                ||!is_int($event['bytes']??null)||$event['bytes']<8||$event['bytes']>EnterpriseFloorplanStore::MAX_BYTES
                ||!is_string($event['reference_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$event['reference_hash'])
                ||!is_string($event['request_id']??null)||($event['kind']==='retirement_requested'?$event['request_id']!=='':!preg_match('/^fpa_[a-f0-9]{32}$/D',$event['request_id']))
                ||!is_string($event['outcome']??null)
                ||(['retirement_requested'=>'pending','retirement_completed'=>'retired','retirement_failed'=>'not_retired','retirement_uncertain'=>'uncertain'][$event['kind']]??null)!==$event['outcome']
                ||!is_string($event['previous_hash']??null)||!hash_equals($previous,$event['previous_hash'])
                ||!is_string($event['entry_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$event['entry_hash'])
                ||!hash_equals(self::eventHash($event),$event['entry_hash'])){
                throw new \RuntimeException('Floor-plan retirement audit is damaged. Preserve it before retiring another image.');
            }
            if($event['kind']==='retirement_requested'){$requests[$key]=$event;}
            else{
                $request=$requests[$event['request_id']]??null;
                if(!$request||isset($outcomes[$event['request_id']])||$request['image_id']!==$event['image_id']
                    ||$request['bytes']!==$event['bytes']||!hash_equals($request['reference_hash'],$event['reference_hash'])){
                    throw new \RuntimeException('Floor-plan retirement audit contains an invalid outcome link.');
                }
                $outcomes[$event['request_id']]=$event;
            }
            $previous=$event['entry_hash'];
        }
        return ['sequence'=>$sequence,'previous_hash'=>$previous,'requests'=>$requests,'outcomes'=>$outcomes];
    }

    private function append(string $kind,array $image,string $actor,string $referenceHash,string $requestId=''): array
    {
        return $this->store->transaction(self::LEDGER,function(array &$events)use($kind,$image,$actor,$referenceHash,$requestId):array{
            $state=self::validate($events);
            if(count($events)>=self::LIMIT){throw new \DomainException('Floor-plan retirement audit reached its allocation. Export the protected audit before retiring another image.');}
            if($kind==='retirement_requested'&&count($events)>self::LIMIT-2){throw new \DomainException('Floor-plan retirement audit needs room for both request and outcome records. Export it before retiring another image.');}
            if($kind!=='retirement_requested'){
                $request=$state['requests'][$requestId]??null;
                if(!$request){throw new \RuntimeException('Floor-plan retirement request audit is missing.');}
                if(isset($state['outcomes'][$requestId])){
                    $existing=$state['outcomes'][$requestId];
                    if($existing['kind']!==$kind){throw new \RuntimeException('Floor-plan retirement already has a different audited outcome.');}
                    return $existing;
                }
                $image=['image_id'=>$request['image_id'],'bytes'=>$request['bytes']];
                $actor=$request['actor'];$referenceHash=$request['reference_hash'];
            }
            if(!is_string($image['image_id']??null)||!preg_match('/^img_[a-f0-9]{64}$/D',$image['image_id'])||!is_int($image['bytes']??null)
                ||$image['bytes']<8||$image['bytes']>EnterpriseFloorplanStore::MAX_BYTES
                ||!preg_match('/^[\x20-\x7e]{1,160}$/D',$actor)||!preg_match('/^[a-f0-9]{64}$/D',$referenceHash)){
                throw new \RuntimeException('Floor-plan retirement audit input is invalid.');
            }
            $id='fpa_'.bin2hex(random_bytes(16));
            $event=['schema'=>1,'sequence'=>$state['sequence']+1,'event_id'=>$id,'kind'=>$kind,'at'=>gmdate('c'),
                'actor'=>$actor,'image_id'=>$image['image_id'],'bytes'=>$image['bytes'],'reference_hash'=>$referenceHash,
                'request_id'=>$kind==='retirement_requested'?'':$requestId,
                'outcome'=>['retirement_requested'=>'pending','retirement_completed'=>'retired','retirement_failed'=>'not_retired','retirement_uncertain'=>'uncertain'][$kind]??'',
                'previous_hash'=>$state['previous_hash'],'entry_hash'=>''];
            $event['entry_hash']=self::eventHash($event);$events[$id]=$event;
            return $event;
        });
    }

    public function requested(array $image,string $actor,string $referenceHash): array
    {
        return $this->append('retirement_requested',$image,$actor,$referenceHash);
    }

    public function completed(string $requestId): array
    {
        return $this->append('retirement_completed',[],'',str_repeat('0',64),$requestId);
    }

    public function failed(string $requestId): array
    {
        return $this->append('retirement_failed',[],'',str_repeat('0',64),$requestId);
    }

    public function uncertain(string $requestId): array
    {
        return $this->append('retirement_uncertain',[],'',str_repeat('0',64),$requestId);
    }

    public function recent(): array
    {
        $events=$this->store->read(self::LEDGER);self::validate($events);
        return array_values(array_reverse(array_slice($events,-20,null,true)));
    }
}
