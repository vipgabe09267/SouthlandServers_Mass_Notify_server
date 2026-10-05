<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/Operators.php';

class ampuser
{
    public static array $rows = [], $constructed = [], $lookups = [];
    public static $beforeLookup = null;
    public function __construct(public string $username, public string $source = 'database')
    {
        self::$constructed[] = $source.':'.$username;
        $this->getAmpUser($username);
    }
    public function getAmpUser($username)
    {
        self::$lookups[] = $this->source.':'.$username;
        if (self::$beforeLookup) { (self::$beforeLookup)($username); }
        return self::$rows[$this->source.':'.$username] ?? false;
    }
}

class DirectoryUserman
{
    public array $users = [], $login = [], $admins = [], $inherited = [];
    public bool $unavailable = false;
    public function getAllUsers(): array
    {
        if ($this->unavailable) { throw new RuntimeException('Unavailable fixture'); }
        return $this->users;
    }
    public function getCombinedGlobalSettingByID($id, $name): bool
    {
        if ($this->unavailable) { throw new RuntimeException('Unavailable fixture'); }
        return $name === 'pbx_login' ? ($this->login[$id] ?? $this->inherited[$id] ?? false) : ($this->admins[$id] ?? false);
    }
}

class DirectoryPbx
{
    public array $local = [];
    public function __construct(public DirectoryUserman $Userman) {}
    public function Database(): object
    {
        return new class($this->local) {
            public function __construct(private array $rows) {}
            public function prepare($sql): object { return new class($this->rows) {
                public function __construct(private array $rows) {}
                public function execute(): void {}
                public function fetchAll($mode): array { return $this->rows; }
            }; }
        };
    }
}

class DirectoryFixture
{
    use \FreePBX\modules\SlsOperators;
    public string $role = 'administrator';
    public function __construct(public DirectoryPbx $FreePBX) {}
    public function currentOperator(): array { return ['operator_role'=>$this->role]; }
}

function verifyDirectory(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function directoryUser(DirectoryUserman $directory, int $id, bool $login, array $sections = []): void
{
    $name = 'person_'.$id;
    $directory->users[] = ['id'=>$id, 'username'=>$name];
    $directory->login[$id] = $login;
    ampuser::$rows['usermanager:'.$name] = ['id'=>$id, 'username'=>$name, 'mode'=>'usermanager',
        'password_sha1'=>str_repeat('a',40), 'sections'=>$sections];
}

$directory = new DirectoryUserman();
$pbx = new DirectoryPbx($directory);
$fixture = new DirectoryFixture($pbx);
for ($id=1; $id<=441; $id++) { directoryUser($directory,$id,$id<=18,['slsmassnotifyserver_operations']); }
$directory->admins[1] = true; // inherited administrator without wildcard sections
unset($directory->login[2]); $directory->inherited[2] = true;
ampuser::$rows['usermanager:person_3']['sections'] = []; // visible, ineligible
ampuser::$rows['usermanager:person_4']['mode'] = 'database'; // mode mismatch
ampuser::$beforeLookup = static function($name) use ($directory): void {
    if ($name==='person_5') { $directory->login[5]=false; } // revoke after early eligibility check
};
$result = $fixture->operatorDirectory();
verifyDirectory(count($result['accounts'])===16,'Directory authorization results changed.');
verifyDirectory(count(ampuser::$constructed)===18 && count(ampuser::$lookups)===36,'Ineligible accounts caused expensive ampuser construction.');
$people = array_column($result['accounts'],null,'username');
verifyDirectory($people['person_1']['administrator'] && $people['person_1']['eligible'],'Inherited administrator lost access.');
verifyDirectory($people['person_2']['eligible'] && !$people['person_2']['administrator'],'Inherited login permission was ignored.');
verifyDirectory(!$people['person_3']['eligible'],'Restricted PBX account gained SLS access.');
verifyDirectory(!isset($people['person_4'],$people['person_5'],$people['person_441']),'Revoked/mismatched account entered the directory.');
$directory->login[2]=false; ampuser::$beforeLookup=null;
verifyDirectory(!in_array('person_2',array_column($fixture->operatorDirectory()['accounts'],'username'),true),'A cached login grant survived revocation.');
$fixture->role='sender';
try { $fixture->operatorDirectory(); throw new RuntimeException('Sender read the administrative directory.'); }
catch (DomainException $expected) {}
$fixture->role='administrator';
$pbx->local=[['username'=>'owner']];
ampuser::$rows['database:owner']=['username'=>'owner','mode'=>'database','password_sha1'=>str_repeat('b',40),'sections'=>['*']];
$directory->unavailable=true;
$result=$fixture->operatorDirectory();
verifyDirectory(count($result['accounts'])===1 && $result['accounts'][0]['administrator'],'Unavailable User Management disabled local recovery administration.');
verifyDirectory(str_contains(implode(' ',$result['warnings']),'User Management is unavailable'),'Directory outage lacked an actionable warning.');
$directory=new DirectoryUserman(); $pbx->Userman=$directory; $pbx->local=[];
for ($id=1; $id<=700; $id++) { directoryUser($directory,$id,false); }
for ($id=701; $id<=1301; $id++) { directoryUser($directory,$id,true,['slsmassnotifyserver_operations']); }
ampuser::$constructed=ampuser::$lookups=[];
$result=$fixture->operatorDirectory();
verifyDirectory(count($result['accounts'])===500 && count(ampuser::$constructed)===500,'The 500-account preview boundary counted disabled accounts or exceeded its limit.');
verifyDirectory(count($result['warnings'])===1 && str_contains($result['warnings'][0],'500'),'Truncated preview lacked its boundary warning.');
echo "Operator directory: early effective-login filtering, downstream revocation, modes, inherited administration, restricted accounts, outages and the 500-account boundary passed.\n";
