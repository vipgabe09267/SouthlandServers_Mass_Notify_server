<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
use SLS\MassNotify\SpeechRules;
function check($ok,$reason) { if (!$ok) { throw new RuntimeException($reason); } }
$rules=SpeechRules::fromText(" PBX = P B X\r\nSouthland Servers = South land servers\nSouthland = south land\nÉtage = ay tahj\nA+B = A plus B\n");
check(SpeechRules::apply('PBX and pbx at Southland Servers, ÉTAGE 2, A+B. PBX123 stays.', $rules)
    === 'P B X and P B X at South land servers, ay tahj 2, A plus B. PBX123 stays.', 'Literal, Unicode, whole-phrase or longest-first match failed');
check(SpeechRules::apply('A B', [['phrase'=>'A','spoken'=>'B'],['phrase'=>'B','spoken'=>'C']]) === 'B C', 'Substitutions cascaded');
check(SpeechRules::apply('Keep this unchanged.',[])==='Keep this unchanged.', 'Empty dictionary changes speech');
foreach ([null, [['phrase'=>'x','spoken'=>false]], [['phrase'=>'x','spoken'=>'<audio>']],
    [['phrase'=>'x','spoken'=>'ok'],['phrase'=>'X','spoken'=>'other']], array_fill(0,51,['phrase'=>'x','spoken'=>'y'])] as $value) {
    try { SpeechRules::normalize($value); throw new RuntimeException('Invalid pronunciation accepted'); } catch (DomainException $error) {}
}
foreach (["missing separator", "a=b=c", str_repeat('x',40001)] as $text) {
    try { SpeechRules::fromText($text); throw new RuntimeException('Invalid editor input accepted'); } catch (DomainException $error) {}
}
try { SpeechRules::apply(str_repeat('A ',249),[['phrase'=>'A','spoken'=>str_repeat('B',128)]]); throw new RuntimeException('Unbounded expansion'); } catch (DomainException $error) {}
$class=new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class); $module=$class->newInstanceWithoutConstructor();
$validate=$class->getMethod('validateAndNormalizeControlConfigPatch');
$result=$validate->invoke($module,['announcement_pronunciation'=>$rules]);
check(empty($result['errors']), 'Valid API pronunciation patch rejected');
$result=$validate->invoke($module,['announcement_pronunciation'=>[['phrase'=>'same','spoken'=>[]]]]);
check(!empty($result['errors']), 'Invalid API pronunciation patch accepted');
echo "Speech rules: Unicode and literal matching, longest-first single pass, bounds, editor parsing and configuration validation passed.\n";
