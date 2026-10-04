<?php
$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_locations');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if (!$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'The security token expired. Reload Locations.']); exit;
    }
    try {
        $raw = $_POST['payload'] ?? null;
        if (!is_string($raw) || strlen($raw) > 786432) { throw new \InvalidArgumentException('The location request is missing or exceeds 768 KiB.'); }
        $payload = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)) { throw new \InvalidArgumentException('Location request must be an object.'); }
        switch ($_POST['slsmassnotifyserver_action'] ?? '') {
            case 'save_location': $result = $slsmassnotifyserver->saveLocation($payload); break;
            case 'delete_location': $result = $slsmassnotifyserver->deleteLocation($payload); break;
            case 'set_default_site': $result = $slsmassnotifyserver->setDefaultSite($payload); break;
            case 'save_audience': $result = $slsmassnotifyserver->saveLocationAudience($payload); break;
            case 'delete_audience': $result = $slsmassnotifyserver->deleteLocationAudience($payload); break;
            case 'save_recipients': $result = $slsmassnotifyserver->saveLocationRecipients($payload); break;
            case 'preview_location_audience': $result = $slsmassnotifyserver->previewLocationAudience($payload); break;
            case 'create_location_audience': $result = $slsmassnotifyserver->createLocationAudienceGroup($payload); break;
            case 'preview_geographic_audience': $result = $slsmassnotifyserver->previewGeographicAudience($payload); break;
            case 'create_geographic_audience': $result = $slsmassnotifyserver->createGeographicAudienceGroup($payload); break;
            default: throw new \InvalidArgumentException('Unsupported location action.');
        }
    } catch (\JsonException | \InvalidArgumentException $error) {
        http_response_code(400);
        $result = ['success' => false, 'message' => $error instanceof \JsonException ? 'Location request contains invalid JSON.' : $error->getMessage()];
    } catch (\Throwable $error) {
        http_response_code(503);
        $result = ['success' => false, 'message' => 'Location storage could not confirm completion. Reload Locations and check protected configuration health before retrying.'];
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
header('Cache-Control: private, no-store');
echo $slsmassnotifyserver->renderLocationsPage();
