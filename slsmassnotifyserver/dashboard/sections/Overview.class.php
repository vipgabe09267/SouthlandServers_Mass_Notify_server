<?php
// vim: set ai ts=4 sw=4 ft=php:
//
// License for all code of this FreePBX module can be found in the license file inside the module directory
// Copyright 2006-2014 Schmooze Com Inc.

namespace FreePBX\modules\Dashboard\Sections;
#[\AllowDynamicProperties]
class Overview {
	public $rawname = 'Overview';

	public function getSections($order) {
		return [["title" => _("System Overview"), "group" => _("Overview"), "width" => "550px", "order" => $order['overview'] ?? '1', "section" => "overview"]];
	}

	public function getContent($section) {
		$getsi = [];
  if (!class_exists('TimeUtils')) {
			include dirname(__DIR__).'/classes/TimeUtils.class.php';
		}
		$brand = \FreePBX::Config()->get("DASHBOARD_FREEPBX_BRAND");

		if (\FreePBX::Config()->get("FREEPBX_SYSTEM_IDENT")) {
			$rem_help = \FreePBX::Config()->get("FREEPBX_SYSTEM_IDENT_REM_DASHBOARD_HELP");
			if (!empty($rem_help) && ($rem_help == 'yes')) {
				$idline = sprintf(_("<strong>'%s'</strong>"), \FreePBX::Config()->get("FREEPBX_SYSTEM_IDENT"));
			} else {
				$idline = sprintf(_("<strong>'%s'</strong><br><i>(You can change this name in Advanced Settings)</i>"), \FreePBX::Config()->get("FREEPBX_SYSTEM_IDENT"));
			}
		} else {
			$idline = "";
		}

		try {
			$getsi = \FreePBX::create()->Dashboard->getSysInfo();
		} catch (\Exception) {

		}

		$getsi['timestamp'] ??= time();
		$since = time() - $getsi['timestamp'];
		$notifications = $this->getNotifications((isset($_COOKIE['dashboardShowAll']) && $_COOKIE['dashboardShowAll'] == "true"));
		$nots = $notifications['nots'];
		$alerts = $this->getAlerts($nots);

		return load_view(dirname(__DIR__).'/views/sections/overview.php',["showAllMessage" => $notifications['showAllMessage'], "nots" => $nots, "alerts" => $alerts, "brand" => $brand, "idline" => $idline, "version" => get_framework_version(), "since" => $since, "services" => $this->getSummary()]);
	}

	private function getNotifications($showall = false) {
		$final = [];
  if (!class_exists('TimeUtils')) {
			include dirname(__DIR__).'/classes/TimeUtils.class.php';
		}
		$final['nots'] = [];
		$items = \FreePBX::create()->Notifications->list_all($showall);
		$allItems = \FreePBX::create()->Notifications->list_all(true);

		$final['showAllMessage'] = ((is_countable($items) ? count($items) : 0) != (is_countable($allItems) ? count($allItems) : 0));
		// This is where we map the Notifications priorities to Bootstrap priorities.
		// define("NOTIFICATION_TYPE_CRITICAL", 100) -> 'danger' (red)
		// define("NOTIFICATION_TYPE_SECURITY", 200) -> 'danger' (red)
		// define("NOTIFICATION_TYPE_UPDATE",   300) -> 'warning' (orange)
		// define("NOTIFICATION_TYPE_ERROR",    400) -> 'danger' (red)
		// define("NOTIFICATION_TYPE_WARNING" , 500) -> 'warning' -> (orange)
		// define("NOTIFICATION_TYPE_NOTICE",   600) -> 'success' -> (green)

		$alerts = [100 => "danger", 200 => "danger", 250 => 'warning', 300 => "warning", 400 => "danger", 500 => "warning", 600 => "success"];
		foreach ($items as $notification) {
			$final['nots'][] = ["id" => $notification['id'], "rawlevel" => $notification['level'], "level" => !isset($alerts[$notification['level']]) ? 'danger' : $alerts[$notification['level']], "candelete" => $notification['candelete'], "title" => $notification['display_text'], "time" => \TimeUtils::getReadable(time() - $notification['timestamp']), "text" => nl2br((string) $notification['extended_text']), "module" => $notification['module'], "link" => $notification['link'], "reset" => $notification['reset']];
		}
		return $final;
	}

	public function getAlerts($nots = false) {
		// Check notifications and decide what we want to do with them.
		// Start with everything happy
		$alerttitle = _("System Alerts");
		$state = "success";
		$text = "<div class='text-center'>"._("No critical issues found")."</div>";
		$foundalerts = [];
		// Go through our notifications now..
		foreach ($nots as $n) {
			// Firstly, check for a security issue. If that happens, we don't care about
			// anything else.
			if ($n['rawlevel'] == 200) {
				// Security vulnerability. This is bad.
				$state = "danger";
				$alerttitle = "<center><h4><i class='fa fa-exclamation-triangle'></i> "._("Security Issue")." <i class='fa fa-exclamation-triangle'></i></h4></center>";
				$text = "<p>".$n['title']."</p><p>" . _("This is a critical issue and should be resolved urgently") . "</p>";
				return ["alerttitle" => $alerttitle, "state" => $state, "text" => $text];
			}

			// Now lets find some alerts!
			if (!isset($foundalerts[$n['level']])) {
				$foundalerts[$n['level']] = 1;
			} else {
				$foundalerts[$n['level']]++;
			}
		}

		// Here is where we decide what the 10-word-box shall say.
		// If there's a Critical Issue, report that and a summary.
		if (isset($foundalerts['danger'])) {
			// There's a critical issue. That's what we're doing.
			$state = "danger";
			$text = _("Please check for errors in the notification section");
			$alerttitle = _("Critical Errors found");
		} elseif (isset($foundalerts['warning'])) {
			$state = "warning";
			$text = _("Please check for errors in the notification section");
			$alerttitle = _("Warnings Found");
		}
		return ["alerttitle" => $alerttitle, "state" => $state, "text" => $text];
	}

	public function getSummary() {
		$svcs = ["asterisk" => _("Asterisk"), "mysql" => _("MySQL"), "apache" => _("Web Server"), "mailq" => _("Mail Queue")];

		$sysinfo = \FreePBX::create()->Dashboard->getSysInfo();

		$final = [];
		$i = 0;
		foreach (array_keys($svcs) as $svc) {
			if (!method_exists($this, "check$svc")) {
				$final[$i]['type'] = 'unknown';
				$final[$i]['tooltip'] = "Function check$svc doesn't exist!";
			} else {
				$func = "check$svc";
				$final[$i] = $this->$func($sysinfo);
			}
			$final[$i]['title'] = $svcs[$svc];
			$i++;
		}

		$final[$i] = $this->checkSlsMassNotify();
		$final[$i]['title'] = _("Mass Notifications Plugin");
		$i++;

		$t = \FreePBX::Hooks()->processHooks($sysinfo);
		$f = $final;
		foreach($t as $d) {
			foreach($d as $d1) {
				$order = $d1['order'] ?? count($f);
				$module = \module_functions::create();
				$fw_module = $module->getinfo('firewall', MODULE_STATUS_ENABLED);
				if(!empty($fw_module["firewall"])){
					$fw_status = \FreePBX::Firewall()->isEnabled();
				}
				if($d1['title'] == "System Firewall" && \FreePBX::Config()->get('VIEW_FW_STATUS') == false && !$fw_status){
					unset($d1);
					continue;
				}
				if($order == 0) {
					array_unshift($f, $d1);
					continue;
				}
				$t1 = array_slice($f, 0, $order, true);
				$t2 = array_slice($f, $order, count($f) - 1, true);
				$f = [...$t1, $d1, ...$t2];
			}
		}
		return $f;
	}

	private function checkSlsMassNotify() {
		$config = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
		$shellConfig = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.conf';
		$statusFile = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json';
		$updateStatusFile = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/update-status.json';
		$runtimeDir = '/usr/local/bin/sls_mass_notify';
		$critical = [];
		$warnings = [];
		$settings = [];
		$status = [];

		if (!is_readable($config)) {
			$critical[] = _("central config is missing or unreadable");
		} else {
			$decoded = json_decode((string) file_get_contents($config), true);
			if (is_array($decoded)) {
				$settings = $decoded;
			} else {
				$critical[] = _("central config is invalid JSON");
			}
		}

		$setup = is_array($settings['setup'] ?? null) ? $settings['setup'] : [];
		if (($setup['completed'] ?? '0') !== '1') {
			return $this->genAlertGlyphicon('error', _("Setup wizard not configured"));
		}

		if (!is_readable($shellConfig)) {
			$critical[] = _("generated shell config is missing or unreadable");
		}

		foreach ([
			$runtimeDir . '/sls_notify.py' => _("SIP NOTIFY sender is missing or not executable"),
			$runtimeDir . '/sls_mass_notify_test.sh' => _("test alert script is missing or not executable"),
		] as $path => $message) {
			if (!is_executable($path)) {
				$critical[] = $message;
			}
		}

		if (!is_dir('/var/www/html/api/sipnotify')) {
			$critical[] = _("desktop/SIP NOTIFY API endpoint is missing");
		}
		if (!is_dir('/var/www/html/api/sls-mass-notify')) {
			$warnings[] = _("Control API endpoint is missing");
		}
		if (!is_writable('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')) {
			$critical[] = _("plugin data directory is not writable");
		}
		if (!is_writable('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify')) {
			$critical[] = _("SIP NOTIFY journal directory is not writable");
		}

		$nwsEnabled = ($settings['enabled'] ?? '0') === '1';
		if ($nwsEnabled) {
			if (!is_executable($runtimeDir . '/sls_mass_notify_nws_poll.sh')) {
				$critical[] = _("NWS polling script is missing or not executable");
			}
			if (!$this->slsMassNotifyCronInstalled()) {
				$critical[] = _("NWS polling cron job is not installed");
			}
			if (trim((string)($settings['nws_zone'] ?? '')) === '') {
				$warnings[] = _("NWS zone is not configured");
			}
			if (empty($settings['alert_recipients'] ?? [])) {
				$warnings[] = _("NWS alert recipients are not configured");
			}
		}

		if (!empty($settings)) {
			$piperBin = (string)($settings['piper_bin'] ?? '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/piper');
			$nwsVoice = (string)($settings['nws_piper_voice'] ?? $settings['piper_voice'] ?? '');
			$announcementVoice = (string)($settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? '');
			if ($piperBin !== '' && !is_executable($piperBin)) {
				$warnings[] = _("Piper TTS binary is missing or not executable");
			}
			if ($nwsVoice !== '' && !is_readable($nwsVoice)) {
				$warnings[] = _("NWS TTS voice is missing or unreadable");
			}
			if ($announcementVoice !== '' && !is_readable($announcementVoice)) {
				$warnings[] = _("announcement TTS voice is missing or unreadable");
			}
		}

		if (is_readable($statusFile)) {
			$decoded = json_decode((string) file_get_contents($statusFile), true);
			if (is_array($decoded)) {
				$status = $decoded;
			}
		}

		if (!empty($status['last_fault_at'])) {
			$warnings[] = sprintf(_("last fault: %s"), trim((string)($status['last_fault_message'] ?? $status['last_fault_stage'] ?? $status['last_fault_at'])));
		}

		if ($nwsEnabled) {
			$pollAt = $this->slsMassNotifyParseTime($status['last_poll_at'] ?? '');
			$pollState = strtolower(trim((string)($status['last_poll_status'] ?? '')));
			if ($pollAt === null) {
				$warnings[] = _("NWS polling has not reported status yet");
			} elseif ((time() - $pollAt) > 600) {
				$warnings[] = _("NWS polling status is stale");
			} elseif (in_array($pollState, ['already_running', 'skipped', 'warning', 'warn'], true)) {
				$warnings[] = trim((string)($status['last_poll_message'] ?? _("NWS polling reported a warning")));
			} elseif (in_array($pollState, ['fault', 'failed', 'error'], true)) {
				$warnings[] = trim((string)($status['last_poll_message'] ?? _("NWS polling reported a fault")));
			}
		}

		$updateStatus = [];
		foreach ([$status, $this->slsMassNotifyReadJson($updateStatusFile)] as $candidate) {
			if (is_array($candidate) && !empty($candidate)) {
				$updateStatus = array_merge($updateStatus, $candidate);
			}
		}
		if (!empty($updateStatus['update_available'])) {
			$latest = trim((string)($updateStatus['latest_version'] ?? $updateStatus['available_version'] ?? ''));
			$warnings[] = $latest !== '' ? sprintf(_("update available: %s"), $latest) : _("update available");
		}

		$critical = array_values(array_unique(array_filter(array_map('trim', $critical))));
		$warnings = array_values(array_unique(array_filter(array_map('trim', $warnings))));
		if (!empty($critical)) {
			return $this->genAlertGlyphicon('error', _("Critical fault: ") . implode(' | ', $critical));
		}
		if (!empty($warnings)) {
			return $this->genAlertGlyphicon('warning', implode(' | ', $warnings));
		}
		return $this->genAlertGlyphicon('ok', _("Mass Notifications core services are installed and writable"));
	}

	private function slsMassNotifyCronInstalled() {
		try {
			foreach (\FreePBX::create()->Cron()->getAll() as $line) {
				if (strpos((string)$line, '/usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh') !== false) {
					return true;
				}
			}
		} catch (\Throwable) {
		}
		return false;
	}

	private function slsMassNotifyReadJson($path) {
		if (!is_readable($path)) {
			return [];
		}
		$decoded = json_decode((string) file_get_contents($path), true);
		return is_array($decoded) ? $decoded : [];
	}

	private function slsMassNotifyParseTime($value) {
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}
		$timestamp = strtotime($value);
		return $timestamp === false ? null : $timestamp;
	}

	private function genAlertGlyphicon($res, $tt = null) {
		return \FreePBX::Dashboard()->genStatusIcon($res, $tt);
	}

	private function checkasterisk($sysinfo) {
		if (!isset($sysinfo['ast.uptime.system-seconds'])) {
			return $this->genAlertGlyphicon('critical', 'Unable to find Asterisk results');
		}
		$ast = $sysinfo['ast.uptime.system-seconds'];

		// Check to see if Asterisk is up and running.
		if ($ast == -1) {
			return $this->genAlertGlyphicon('error', 'Asterisk not running');
		}

		// Can we connect to asterisk?
		if ($ast == -2) {
			return $this->genAlertGlyphicon('critical', 'Asterisk Manager Interface (astman) failure');
		}

		$uptime = $sysinfo['ast.uptime.system'];
		// Up for less than 10 minutes? Is it crashing?
		if ($ast < 600) {
			return $this->genAlertGlyphicon('warning', "Asterisk running for less than 10 minutes ($uptime)");
		}

		return $this->genAlertGlyphicon('ok', "Asterisk uptime $uptime");
	}

	private function checkmysql() {
		return $this->genAlertGlyphicon('ok', "No Database checks written yet.");
	}

	private function checkmailq() {
		$lastline = null;
  $mailq = fpbx_which("mailq");
		if ($mailq) {
			$lastline = exec("$mailq 2>&1", $out, $ret);
		}
		// Postfix returns 'Mail queue is empty'; exim returns nothing; sendmail returns total
		if (empty($out) || // exim
			str_contains($out[0], "queue is empty") || // postfix status on first/only output line
			str_contains(end($out), "Total requests: 0") // sendmail status on last line
		) {
			return $this->genAlertGlyphicon('ok', "No outbound mail in queue");
		}

		if (preg_match('/(?:in (\d+) Request)|(?:Total requests: (\d+))/', $lastline, $regex)) { // exim/postfix|sendmail
			// We have mail.
			$messages = (int) $regex[1] ?: (int) $regex[2]; // take whichever one matched
			if ($messages > 5) {
				$err = "critical";
			} else {
				$err = "warning";
			}

			if ($messages == 1) {
				$msg = _("1 message is queued on this machine, and has not been delivered");
			} else {
				$msg = sprintf(_("%s messages are queued on this machine, and have not been delivered"), $messages);
			}
			return $this->genAlertGlyphicon($err, $msg);
		}
		// This signifies a bug and must not be translated.
		return $this->genAlertGlyphicon('critical', "Unknown output from mailq: ".json_encode([$out, $ret], JSON_THROW_ON_ERROR));
	}


	private function checkapache() {
		// This is here to allow us to fire up a small replacement httpd server if
		// something traumatic happens to apache. For the moment, however, we just
		// say yes.
		return $this->genAlertGlyphicon('ok', "Apache running");
	}

	private function delNotification() {
		// Triggered from above.
		$id = $_REQUEST['id'];
		$mod = $_REQUEST['mod'];
		return FreePBX::create()->Notifications->safe_delete($mod, $id);

	}
}
