<?php
declare(strict_types=1);
require_once __DIR__.'/AdminResponse.php';
require_once __DIR__.'/api/sls-mass-notify/security.php';
$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_incidents');
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if (!is_string($requestMethod)) { $requestMethod = ''; }
if ($requestMethod === 'POST') {
    \SLS\MassNotify\AdminResponse::requireClearBuffers();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    $csrf = $_POST['slsmassnotifyserver_csrf'] ?? null;
    if (!is_string($csrf) || !$slsmassnotifyserver->validateCsrfToken($csrf)) {
        http_response_code(403); echo json_encode(['success' => false, 'message' => _('The security token expired. Reload this page before continuing.')], JSON_INVALID_UTF8_SUBSTITUTE); exit;
    }
    try {
        $raw = $_POST['payload'] ?? '';
        if (!is_string($raw) || strlen($raw) > 2500000) { throw new \InvalidArgumentException('Incident form exceeds its supported size.'); }
        $payload = \SLS\MassNotify\ApiSecurity::jsonObject($raw, false, 24);
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) { throw new \InvalidArgumentException('Incident form is incomplete. Reload and try again.'); }
        $id = $payload['incident_id'] ?? ''; unset($payload['incident_id']);
        if (!is_string($id)) { throw new \InvalidArgumentException('Incident identifier must be text.'); }
        $action = $_POST['slsmassnotifyserver_action'] ?? null;
        if (!is_string($action)) { throw new \InvalidArgumentException('Unsupported incident action.'); }
        switch ($action) {
            case 'save_languages': $result = $slsmassnotifyserver->saveIncidentRecipientLanguages($payload); break;
            case 'save_retention': $result = $slsmassnotifyserver->saveIncidentRetention($payload); break;
            case 'save_template': $result = $slsmassnotifyserver->saveIncidentTemplate($payload); break;
            case 'delete_template':
                if (array_diff(array_keys($payload), ['template_id']) || count($payload) !== 1 || !is_string($payload['template_id'] ?? null)) { throw new \InvalidArgumentException('Template deletion requires one text identifier.'); }
                $result = $slsmassnotifyserver->deleteIncidentTemplate($payload['template_id']); break;
            case 'start_incident': $result = $slsmassnotifyserver->startIncident($payload); break;
            case 'send_update': $result = $slsmassnotifyserver->sendIncidentUpdate($id, $payload); break;
            case 'roll_call': $result = $slsmassnotifyserver->recordIncidentRollCall($id, $payload); break;
            case 'observer_result': $result = $slsmassnotifyserver->recordIncidentChecklist($id, $payload); break;
            case 'get_incident':
                if ($payload !== []) { throw new \InvalidArgumentException('Incident lookup contains unexpected fields.'); }
                $result = $slsmassnotifyserver->getIncident($id); break;
            case 'export_report':
                if (array_diff(array_keys($payload), ['job_offset','revision']) || count($payload) !== 2
                    || !is_int($payload['job_offset'] ?? null) || !is_string($payload['revision'] ?? null)) { throw new \InvalidArgumentException('Report position or revision is invalid.'); }
                $result = $slsmassnotifyserver->exportIncidentReport($id, $payload['job_offset'], $payload['revision']); break;
            default: throw new \InvalidArgumentException('Unsupported incident action.');
        }
    } catch (\JsonException | \InvalidArgumentException $error) {
        http_response_code(400);
        $result = ['success' => false, 'message' => $error->getMessage()];
    } catch (\DomainException $error) {
        http_response_code(422);
        $result = ['success' => false, 'message' => $error->getMessage()];
    } catch (\Throwable $error) {
        http_response_code(503); header('Retry-After: 5');
        $result = ['success' => false, 'message' => 'Incident request could not confirm completion. Refresh the record before sending another request.'];
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
if ($requestMethod !== 'GET') { header('Allow: GET, POST'); http_response_code(405); return; }
header('Cache-Control: private, no-store');
$id = $_GET['incident_id'] ?? '';
echo $slsmassnotifyserver->renderIncidentsPage(['incident_id' => is_string($id) ? $id : '', 'cursor' => is_string($_GET['cursor'] ?? null) ? $_GET['cursor'] : '', 'archived' => ($_GET['archived'] ?? '') === '1']);
