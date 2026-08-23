<?php

namespace FreePBX\modules\Slsmassnotifyserver;

use FreePBX\modules\Backup as Base;

/** Native FreePBX 17 restore adapter for Mass Notifications. */
class Restore extends Base\RestoreBase
{
	/**
	 * Keep the currently installed module available until the protected backup
	 * payload has been validated. The normal module reset would install against
	 * a temporary/default config before the real config is restored.
	 */
	public function reset()
	{
	}

	public function runRestore()
	{
		$configs = $this->getConfigs();
		$manifest = is_array($configs['native_backup'] ?? null) ? $configs['native_backup'] : [];
		$this->FreePBX->Slsmassnotifyserver->restoreFreePbxBackupSnapshot(
			$manifest,
			$this->getFiles(),
			$this->tmpdir,
			$this->transactionId
		);
	}
}
