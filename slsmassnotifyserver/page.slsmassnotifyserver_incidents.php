<?php
$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_incidents');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    if (!$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
        http_response_code(403); echo json_encode(['success' => false, 'message' => _('The security token expired. Reload this page before continuing.')]); exit;
    }
    try {
        $raw = $_POST['payload'] ?? '';
        if (!is_string($raw) || strlen($raw) > 2500000) { throw new \InvalidArgumentException('Incident form exceeds its supported size.'); }
        $payload = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) { throw new \InvalidArgumentException('Incident form is incomplete. Reload and try again.'); }
        $id = $payload['incident_id'] ?? ''; unset($payload['incident_id']);
        if (!is_string($id)) { throw new \InvalidArgumentException('Incident identifier must be text.'); }
        switch ($_POST['slsmassnotifyserver_action'] ?? '') {
            case 'save_retention': $result = $slsmassnotifyserver->saveIncidentRetention($payload); break;
            case 'save_template': $result = $slsmassnotifyserver->saveIncidentTemplate($payload); break;
            case 'delete_template':
                if (!is_string($payload['template_id'] ?? null)) { throw new \InvalidArgumentException('Template identifier must be text.'); }
                $result = $slsmassnotifyserver->deleteIncidentTemplate($payload['template_id']); break;
            case 'start_incident': $result = $slsmassnotifyserver->startIncident($payload); break;
            case 'send_update': $result = $slsmassnotifyserver->sendIncidentUpdate($id, $payload); break;
            case 'roll_call': $result = $slsmassnotifyserver->recordIncidentRollCall($id, $payload); break;
            case 'observer_result': $result = $slsmassnotifyserver->recordIncidentChecklist($id, $payload); break;
            case 'get_incident': $result = $slsmassnotifyserver->getIncident($id); break;
            case 'export_report':
                if (!is_int($payload['job_offset'] ?? 0) || !is_string($payload['revision'] ?? '')) { throw new \InvalidArgumentException('Report position or revision is invalid.'); }
                $result = $slsmassnotifyserver->exportIncidentReport($id, $payload['job_offset'] ?? 0, $payload['revision'] ?? ''); break;
            default: throw new \InvalidArgumentException('Unsupported incident action.');
        }
    } catch (\JsonException | \InvalidArgumentException $error) {
        $result = ['success' => false, 'message' => $error->getMessage()];
    } catch (\Throwable $error) {
        $result = ['success' => false, 'message' => 'Incident request could not confirm completion. Refresh the record before sending another request.'];
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
$id = $_GET['incident_id'] ?? '';
echo $slsmassnotifyserver->renderIncidentsPage(['incident_id' => is_string($id) ? $id : '', 'cursor' => is_string($_GET['cursor'] ?? null) ? $_GET['cursor'] : '', 'archived' => ($_GET['archived'] ?? '') === '1']);
