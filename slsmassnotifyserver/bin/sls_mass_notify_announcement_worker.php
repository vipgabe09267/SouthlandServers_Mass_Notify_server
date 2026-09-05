#!/usr/bin/php
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
$id = $argv[1] ?? '';
if ($id !== '' && !preg_match('/^job_[a-f0-9]{32}$/', $id)) { exit(2); }
require '/etc/freepbx.conf';
try {
    \FreePBX::Slsmassnotifyserver()->processAnnouncementJobs($id);
    exit(0);
} catch (\Throwable $error) {
    error_log('SLS announcement worker: ' . $error->getMessage());
    exit(1);
}
