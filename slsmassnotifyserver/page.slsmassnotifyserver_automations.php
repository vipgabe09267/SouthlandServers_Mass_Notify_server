<?php
$module=\FreePBX::create()->Slsmassnotifyserver;
$module->enforceOperatorPageAccess('slsmassnotifyserver_automations');
header('Cache-Control: private, no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    while (ob_get_level()>0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff');
    try {
        if (!$module->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) { http_response_code(403); throw new \DomainException('Security token expired. Reload Triggers and Actions.'); }
        $raw=$_POST['payload'] ?? null;
        if (!is_string($raw) || strlen($raw)>32768) { throw new \InvalidArgumentException('Trigger editor request exceeds 32 KiB or is missing.'); }
        $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) { throw new \InvalidArgumentException('Trigger editor request must be an object.'); }
        $result=$module->saveAutomationItem($input);
    } catch (\JsonException $error) { http_response_code(400); $result=['success'=>false,'message'=>'Trigger settings contain invalid JSON. Reload the editor and try again.'];
    } catch (\InvalidArgumentException | \DomainException $error) { $result=['success'=>false,'message'=>$error->getMessage()]; }
    catch (\Throwable $error) { http_response_code(503); $result=['success'=>false,'message'=>'Trigger settings could not confirm a durable save. Reload before retrying; check configuration permissions, storage and concurrent edits.']; }
    echo json_encode($result,JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
echo $module->renderAutomationsPage();
