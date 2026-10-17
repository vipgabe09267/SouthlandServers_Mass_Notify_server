<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** A historical username alone is not evidence that SLS owns an AMI account. */
final class AmiUserOwnership
{
    public static function matches(array $row, string $name, string $secretHash): bool
    {
        if (($row['name'] ?? null) !== $name || !preg_match('/^[a-f0-9]{64}$/D', $secretHash)
            || !is_string($row['secret'] ?? null) || $row['secret'] === ''
            || !hash_equals($secretHash, hash('sha256', $row['secret']))
            || !is_string($row['read'] ?? null) || ($row['write'] ?? null) !== $row['read']
            || !in_array($row['read'], ['system,call,originate','system,call,originate,reporting'], true)
            || !in_array($row['writetimeout'] ?? null, [1000, '1000'], true)
            || !is_string($row['permit'] ?? null) || !is_string($row['deny'] ?? null)) { return false; }
        $permit = explode('&', $row['permit']);
        $allowed = ['127.0.0.1/255.255.255.255', '127.0.0.1/32', '::1/128'];
        $deny = explode('&', $row['deny']);
        return $permit !== [] && !array_diff($permit, $allowed)
            && (in_array('0.0.0.0/0.0.0.0', $deny, true) || in_array('0.0.0.0/0', $deny, true))
            && (!in_array('::1/128', $permit, true) || in_array('::/0', $deny, true));
    }

    public static function remove($database, array $names, string $secretHash, string $pbxUser): array
    {
        $removed = [];
        $lookup = $database->prepare('SELECT * FROM manager WHERE name = ?');
        $delete = $database->prepare('DELETE FROM manager WHERE name = ? AND secret = ? AND permit = ? AND deny = ? AND `read` = ? AND `write` = ? AND writetimeout = ?');
        $unique = [];
        foreach ($names as $name) {
            if (is_string($name) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $name) && $name !== $pbxUser) { $unique[$name] = true; }
        }
        foreach (array_keys($unique) as $name) {
            $lookup->execute([$name]);
            $rows = $lookup->fetchAll(\PDO::FETCH_ASSOC);
            if (count($rows) > 1) { throw new \RuntimeException('AMI account identity is ambiguous; no account was adopted.'); }
            $row = $rows[0] ?? null;
            if (!$row || !self::matches($row, $name, $secretHash)) { continue; }
            $delete->execute([$name, $row['secret'], $row['permit'], $row['deny'], $row['read'], $row['write'], $row['writetimeout']]);
            if ($delete->rowCount() > 0) { $removed[] = $name; }
        }
        return $removed;
    }
}
