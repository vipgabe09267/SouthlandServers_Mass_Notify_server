<?php
$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_paging');
$saveResult = $_SESSION['slsmassnotifyserver_paging_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_paging_result']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $validToken = $slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '');
    $action = (string)($_POST['slsmassnotifyserver_action'] ?? '');
    if ($action === 'generate_paging_pin') {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if (!$validToken) { http_response_code(403); }
        echo json_encode($validToken ? $slsmassnotifyserver->generateLivePagingPin($_POST['pin_length'] ?? 4)
            : ['success' => false, 'message' => _('The security token expired. Reload the page.')]);
        exit;
    }
    if (!$validToken) {
        $result = ['success' => false, 'message' => _('The security token expired. Reload the page.'), 'errors' => []];
    } elseif ($action === 'save_live_paging' && ($_POST['sls_paging_form_complete'] ?? '') !== '1') {
        $result = ['success' => false, 'message' => _('The paging form was incomplete. Reload the page and try again.'), 'errors' => []];
    } elseif ($action === 'save_live_paging' && is_array($_POST['live_paging'] ?? null)) {
        $revision = $_POST['paging_revision'] ?? null;
        $result = is_string($revision) && preg_match('/^[a-f0-9]{64}$/D', $revision)
            ? $slsmassnotifyserver->saveLivePagingSettings($_POST['live_paging'], $revision)
            : ['success' => false, 'message' => _('Paging form version is missing. Reload the page before saving.'), 'errors' => []];
    } else {
        $result = ['success' => false, 'message' => _('Unsupported live paging action.'), 'errors' => []];
    }
    if (($_POST['ajax'] ?? '') === '1') {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        if (!$validToken) { http_response_code(403); }
        if (!empty($result['success'])) { $_SESSION['slsmassnotifyserver_paging_result'] = $result; }
        echo json_encode($result); exit;
    }
    $_SESSION['slsmassnotifyserver_paging_result'] = $result;
    header('Location: config.php?display=slsmassnotifyserver_paging');
    exit;
}

echo $slsmassnotifyserver->showPage('paging', ['save_result' => $saveResult]);
