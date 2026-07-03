<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

$freepbx = \FreePBX::Create();
$freepbx->Slsmassnotifyserver->install();
