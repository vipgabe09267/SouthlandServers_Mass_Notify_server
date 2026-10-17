<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityStore.php';

/** A durable claim precedes submission; an interrupted claim never sends again. */
final class ControlIdempotency
{
    public const RETENTION_SECONDS=2592000;
    private EnterpriseIdentityStore $store;
    public function __construct(string $directory) { $this->store=new EnterpriseIdentityStore($directory); }
    private static function canonicalValue($value, int $depth, int &$nodes)
    {
        if (++$nodes > 4096 || $depth > 32) {
            throw new \InvalidArgumentException('Announcement idempotency input exceeds its structural limit.');
        }
        if (is_string($value)) {
            if (strlen($value) > 65536 || preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException('Announcement idempotency input contains invalid text.');
            }
            return $value;
        }
        if (is_int($value) || is_bool($value) || $value === null) { return $value; }
        if (is_float($value) && is_finite($value)) { return $value; }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Announcement idempotency input contains an unsupported value.');
        }
        foreach (array_keys($value) as $field) {
            if (is_string($field) && (strlen($field) > 128 || preg_match('//u', $field) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $field))) {
                throw new \InvalidArgumentException('Announcement idempotency input contains an invalid field name.');
            }
        }
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as &$child) { $child = self::canonicalValue($child, $depth + 1, $nodes); }
        unset($child);
        return $value;
    }
    private static function canonical($value)
    {
        $nodes = 0;
        return self::canonicalValue($value, 0, $nodes);
    }
    private static function storedResultValue($value, int $depth, int &$nodes): void
    {
        if (++$nodes>4096 || $depth>16) { throw new \RuntimeException('Announcement idempotency result exceeds its structural limit. Preserve it for recovery.'); }
        if (is_string($value)) {
            if (strlen($value)>16384 || preg_match('//u',$value)!==1) { throw new \RuntimeException('Announcement idempotency result contains invalid text. Preserve it for recovery.'); }
            return;
        }
        if (is_int($value) || is_bool($value) || $value===null) { return; }
        if (is_float($value) && is_finite($value)) { return; }
        if (!is_array($value)) { throw new \RuntimeException('Announcement idempotency result contains an unsupported value. Preserve it for recovery.'); }
        foreach ($value as $key=>$child) {
            if (is_string($key) && (strlen($key)>128 || preg_match('/[\x00-\x1f\x7f]/',$key))) {
                throw new \RuntimeException('Announcement idempotency result contains an invalid field name. Preserve it for recovery.');
            }
            self::storedResultValue($child,$depth+1,$nodes);
        }
    }
    private static function storedResult($value): array
    {
        if (!is_array($value)) { throw new \RuntimeException('Announcement idempotency result is invalid. Preserve it for recovery.'); }
        $nodes=0; self::storedResultValue($value,0,$nodes);
        $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($json)>65536) { throw new \RuntimeException('Announcement idempotency result exceeds 64 KiB. Preserve it for recovery.'); }
        return $value;
    }
    private static function checkedRow($row): array
    {
        if (!is_array($row) || array_is_list($row) || !in_array($row['state']??null,['claimed','complete'],true)) {
            throw new \RuntimeException('Announcement idempotency history is invalid. Preserve it for recovery.');
        }
        $complete=$row['state']==='complete';
        $keys=$complete?['state','fingerprint','created_at','expires_at','result']:['state','fingerprint','created_at','expires_at'];
        if (count($row)!==count($keys) || array_diff(array_keys($row),$keys)
            || !is_string($row['fingerprint']??null) || !preg_match('/^[a-f0-9]{64}$/D',$row['fingerprint'])
            || !is_int($row['created_at']??null) || $row['created_at']<1 || $row['created_at']>253402300799
            || !is_int($row['expires_at']??null) || $row['expires_at']<=$row['created_at']
            || $row['expires_at']>$row['created_at']+self::RETENTION_SECONDS) {
            throw new \RuntimeException('Announcement idempotency history is invalid. Preserve it for recovery.');
        }
        if ($complete) { self::storedResult($row['result']??null); }
        return $row;
    }
    public function submit(string $key,array $principal,array $body,callable $send,int $now): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$key)) { throw new \InvalidArgumentException('Idempotency-Key must contain 16–128 letters, digits, underscores or hyphens.'); }
        $identity = $principal['id'] ?? null;
        if (!is_string($identity) || $identity === '' || strlen($identity) > 128
            || preg_match('//u', $identity) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $identity)) {
            throw new \DomainException('A valid authenticated credential is required.');
        }
        if ($now < 1 || $now > 253402300799 - self::RETENTION_SECONDS) {
            throw new \InvalidArgumentException('The idempotency timestamp is outside the supported range.');
        }
        $id=hash('sha256',$identity."\0".$key);
        $canonical = json_encode(
            self::canonical([$body, $principal['audience'] ?? [], $principal['scopes'] ?? []]),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (strlen($canonical) > 262144) {
            throw new \InvalidArgumentException('Announcement idempotency input exceeds its encoded limit.');
        }
        $fingerprint = hash('sha256', $canonical);
        $claim=$this->store->transaction('announcement_claims',static function(array &$rows) use($id,$fingerprint,$now): array {
            foreach ($rows as $savedId=>$row) {
                if (!is_string($savedId)||!preg_match('/^[a-f0-9]{64}$/D',$savedId)) { throw new \RuntimeException('Announcement idempotency history is invalid. Preserve it for recovery.'); }
                $row=self::checkedRow($row); $rows[$savedId]=$row;
                if ($row['expires_at']<=$now) { unset($rows[$savedId]); }
            }
            if (isset($rows[$id])) {
                if (!hash_equals($rows[$id]['fingerprint'],$fingerprint)) { throw new \DomainException('Idempotency-Key was already used with different announcement details or permissions.'); }
                return ['new'=>false,'row'=>$rows[$id]];
            }
            if (count($rows)>=10000) { throw new \RuntimeException('Announcement idempotency capacity reached. No announcement was submitted.'); }
            $row=['state'=>'claimed','fingerprint'=>$fingerprint,'created_at'=>$now,'expires_at'=>$now+self::RETENTION_SECONDS];
            $rows[$id]=$row; return ['new'=>true,'row'=>$row];
        });
        if (!$claim['new']) {
            if ($claim['row']['state']==='complete') { return $claim['row']['result']; }
            return ['success'=>false,'error_code'=>'announcement_submission_uncertain','retryable'=>false,
                'message'=>'This request was reserved but its submission result is unconfirmed. Review delivery history; repeating this key will not send another announcement.'];
        }
        $result=$send();
        try { $result=self::storedResult($result); }
        catch (\Throwable $error) { throw new \RuntimeException('Announcement submission result could not be stored. Do not resend using a new key before checking delivery history.',0,$error); }
        return $this->store->transaction('announcement_claims',static function(array &$rows) use($id,$fingerprint,$result): array {
            if (!isset($rows[$id])) { throw new \RuntimeException('Announcement claim changed during submission. Preserve delivery history.'); }
            $row = self::checkedRow($rows[$id]);
            if (!hash_equals($row['fingerprint'], $fingerprint) || $row['state'] !== 'claimed') {
                throw new \RuntimeException('Announcement claim changed during submission. Preserve delivery history.');
            }
            $rows[$id]['state']='complete'; $rows[$id]['result']=$result; return $result;
        });
    }
}
