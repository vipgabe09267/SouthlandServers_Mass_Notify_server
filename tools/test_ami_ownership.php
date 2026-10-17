<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/AmiUserOwnership.php';
use SLS\MassNotify\AmiUserOwnership;

$db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE manager (name TEXT, secret TEXT, deny TEXT, permit TEXT, `read` TEXT, `write` TEXT, writetimeout INTEGER)');
$insert = $db->prepare('INSERT INTO manager VALUES (?, ?, ?, ?, ?, ?, ?)');
$base = ['name'=>'slsmassnotify', 'secret'=>'private-fixture-only', 'deny'=>'0.0.0.0/0.0.0.0&::/0',
    'permit'=>'127.0.0.1/255.255.255.255&::1/128', 'read'=>'system,call,originate,reporting',
    'write'=>'system,call,originate,reporting', 'writetimeout'=>1000];
$proof = hash('sha256', $base['secret']); $checks = 0;
function check($condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
check(AmiUserOwnership::matches($base, $base['name'], $proof), 'Dedicated SLS account not recognized.');
$legacy=array_replace($base,['deny'=>'0.0.0.0/0.0.0.0','permit'=>'127.0.0.1/255.255.255.255',
    'read'=>'system,call,originate','write'=>'system,call,originate']);
check(AmiUserOwnership::matches($legacy,$legacy['name'],$proof),'Authenticated pre-0.1.5 SLS AMI profile was not recognized for migration.');
foreach ([['secret'=>'unrelated'], ['permit'=>'0.0.0.0/0'], ['permit'=>'127.0.0.1/24'], ['permit'=>[]], ['permit'=>'::1/128', 'deny'=>'0.0.0.0/0.0.0.0'], ['deny'=>''], ['deny'=>[]],
    ['read'=>'system,call'], ['write'=>'system,call,originate,reporting,command'], ['writetimeout'=>2000], ['name'=>'other']] as $change) {
    check(!AmiUserOwnership::matches(array_replace($base, $change), $base['name'], $proof), 'Unproven or broadly permitted account was adopted.');
}
foreach (['slsmassnotify','sls_mass_notify','nws_push','admin','remote'] as $name) {
    $row = $base; $row['name'] = $name;
    if ($name === 'nws_push') { $row['secret'] = 'another-integration'; }
    if ($name === 'remote') { $row['permit'] = '192.0.2.0/24'; }
    $insert->execute(array_values($row));
}
$removed = AmiUserOwnership::remove($db, ['slsmassnotify',[],'sls_mass_notify','slsmassnotify','nws_push','admin','remote','absent'], $proof, 'admin');
check($removed === ['slsmassnotify','sls_mass_notify'], 'Removal did not preserve unrelated and administrative accounts.');
check($db->query('SELECT name FROM manager ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) === ['admin','nws_push','remote'], 'Unrelated manager accounts changed.');
check(AmiUserOwnership::remove($db, ['slsmassnotify','sls_mass_notify'], $proof, 'admin') === [], 'Repeated removal was not harmless.');

// A concurrent credential change after lookup must defeat the conditional delete.
$row = $base; $row['name'] = 'changed'; $insert->execute(array_values($row));
$adapter = new class($db) {
    public function __construct(private PDO $db) {}
    public function prepare(string $sql) {
        if (!str_starts_with($sql, 'DELETE')) return $this->db->prepare($sql);
        return new class($this->db, $sql) {
            private PDOStatement $statement;
            public function __construct(private PDO $db, string $sql) { $this->statement = $db->prepare($sql); }
            public function execute(array $arguments) { $this->db->exec("UPDATE manager SET secret='changed-by-administrator' WHERE name='changed'"); return $this->statement->execute($arguments); }
            public function rowCount(): int { return $this->statement->rowCount(); }
        };
    }
};
check(AmiUserOwnership::remove($adapter, ['changed'], $proof, 'admin') === [], 'Concurrent manager edit was deleted.');
check($db->query("SELECT secret FROM manager WHERE name='changed'")->fetchColumn() === 'changed-by-administrator', 'Concurrent credential change was lost.');
echo "AMI ownership: {$checks} credential, loopback, administrator, legacy, idempotency and concurrent-edit checks passed; no PBX database used.\n";
