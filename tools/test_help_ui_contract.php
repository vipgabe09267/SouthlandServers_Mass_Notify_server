<?php

$view = file_get_contents(__DIR__ . '/../slsmassnotifyserver/views/help.php');
if (!is_string($view) || $view === '') {
	fwrite(STDERR, "Unable to read the Help view.\n");
	exit(1);
}

$required = [
	'sls-help-endpoint-table',
	'min-width: 660px',
	'white-space: nowrap',
	"<?php echo _('Extension'); ?>",
	"last_acknowledged_at",
	"Live stream active",
	"do not prove the user read the message",
];
foreach ($required as $needle) {
	if (strpos($view, $needle) === false) {
		fwrite(STDERR, "Help endpoint layout contract is missing: {$needle}\n");
		exit(1);
	}
}

if (strpos($view, "<?php echo _('Ext'); ?>") !== false) {
	fwrite(STDERR, "The abbreviated endpoint heading is still present.\n");
	exit(1);
}

echo "Help endpoint layout contract passed.\n";
