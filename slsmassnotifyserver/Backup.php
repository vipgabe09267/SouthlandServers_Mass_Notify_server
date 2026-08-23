<?php

namespace FreePBX\modules\Slsmassnotifyserver;

use FreePBX\modules\Backup as Base;

/** Native FreePBX 17 backup adapter for Mass Notifications. */
class Backup extends Base\BackupBase
{
	public function runBackup($id, $transaction)
	{
		$snapshot = $this->FreePBX->Slsmassnotifyserver->createFreePbxBackupSnapshot($transaction);
		if (!empty($snapshot['garbage'])) {
			// Register cleanup first so a later adapter error cannot strand the
			// private snapshot in the system temporary directory.
			$this->addGarbage((string)$snapshot['garbage']);
		}
		$this->addDependency('recordings');
		$this->addConfigs([
			'native_backup' => $snapshot['manifest'],
		]);
		foreach ((array)($snapshot['files'] ?? []) as $file) {
			$this->addFile(
				(string)($file['filename'] ?? ''),
				(string)($file['path'] ?? ''),
				'',
				(string)($file['type'] ?? 'slsmassnotify-data')
			);
		}
	}
}
