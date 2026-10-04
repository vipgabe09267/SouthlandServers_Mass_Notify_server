<?php
declare(strict_types=1);
$module=\FreePBX::create()->Slsmassnotifyserver;
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
try { $module->assertEnterprisePageAdministrator(); }
catch (\Throwable $error) {
    while(ob_get_level()>0){@ob_end_clean();}http_response_code(403);
    echo '<div class="alert alert-warning">Enterprise Labs is available only in the FreePBX administrator panel.</div>';return;
}
if (($_SERVER['REQUEST_METHOD']??'')==='GET' && isset($_GET['image_id'])) {
    while(ob_get_level()>0){@ob_end_clean();}
    try {
        if(!is_string($_GET['image_id'])){throw new \InvalidArgumentException('Invalid image identifier.');}
        $image=$module->getEnterpriseFloorplanImage($_GET['image_id']);
        header('Content-Type: '.$image['type']);header('Content-Security-Policy: sandbox; default-src \'none\'');
        header('Content-Disposition: inline; filename="floorplan.'.($image['type']==='image/png'?'png':'jpg').'"');
        echo $image['bytes'];
    }catch(\Throwable $error){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Private image is unavailable for this account.';}
    exit;
}
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    while(ob_get_level()>0){@ob_end_clean();}header('Content-Type: application/json; charset=utf-8');
    try {
        if(!$module->validateCsrfToken($_POST['slsmassnotifyserver_csrf']??'')){http_response_code(403);throw new \DomainException('Security token expired. Reload Enterprise Labs before submitting.');}
        if(($_POST['action']??'')==='floorplan_upload'){
            $result=$module->uploadEnterpriseFloorplan($_FILES['floorplan']??[]);
        }else{
            $raw=$_POST['payload']??null;
            if(!is_string($raw)||strlen($raw)>2097152){http_response_code(413);throw new \InvalidArgumentException('Enterprise Labs requests must be structured data of at most 2 MiB.');}
            if(!(json_decode($raw,false,32,JSON_THROW_ON_ERROR) instanceof \stdClass)){throw new \InvalidArgumentException('Enterprise Labs request must be an object.');}
            $payload=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(isset($payload['action'])){
                if(array_diff(array_keys($payload),['action','input'])||!is_array($payload['input']??null)){throw new \InvalidArgumentException('Submit an action and its structured input.');}
                $action=$payload['action'];$input=$payload['input'];
            }else{$action=$_POST['action']??'';$input=$payload;}
            if(!is_string($action)){throw new \InvalidArgumentException('Enterprise Labs action must be a string.');}
            $result=$module->enterpriseAdministratorAction($action,$input);
        }
    }catch(\JsonException $error){http_response_code(400);$result=['success'=>false,'message'=>'The request contains invalid JSON. Reload the editor and try again.'];}
    catch(\DomainException|\InvalidArgumentException $error){if(http_response_code()<400){http_response_code(422);}$result=['success'=>false,'message'=>$error->getMessage()];}
    catch(\Throwable $error){http_response_code(503);header('Retry-After: 5');$result=['success'=>false,'message'=>'This operation could not confirm completion. Reload its saved state and activity history before retrying. Check protected storage, runtime dependencies and concurrent edits.'];}
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET, POST');http_response_code(405);return;}
echo $module->renderEnterpriseLabsDashboard();
