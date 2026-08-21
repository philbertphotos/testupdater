<?php
/*************************************************************************
 * Action_AutoUpdate
 *************************************************************************
 * Workflow action key:
 * system.update.auto
 *
 * Purpose:
 * Check the configured GitHub repository for User Manager updates and apply
 * all outdated files automatically when updates are available.
 *
 * Expected location:
 * /admin/workflows/actions/autoupdate.php
 *
 * Expected registry entry:
 * 'system.update.auto' => 'Action_AutoUpdate'
 *
 * Expected step parameters:
 * {
 *     "user":"github-user",
 *     "repo":"github-repository",
 *     "branch":"main",
 *     "target_dir":"",
 *     "class_file":"",
 *     "lock_seconds":3600
 * }
 *************************************************************************/
class Action_AutoUpdate implements WorkflowActionInterface
{
	/**
	 * Run action.
	 *
	 * @param array  $context
	 * @param array  $params
	 * @param object $engine
	 *
	 * @return array
	 */
	public function run($context, $params, $engine)
	{
		$user = isset($params['user']) ? trim((string)$params['user']) : '';
		$repo = isset($params['repo']) ? trim((string)$params['repo']) : '';
		$branch = isset($params['branch']) ? trim((string)$params['branch']) : 'main';
		$targetDir = isset($params['target_dir']) ? trim((string)$params['target_dir']) : '';
		$classFile = isset($params['class_file']) ? trim((string)$params['class_file']) : '';
		$lockSeconds = isset($params['lock_seconds']) ? (int)$params['lock_seconds'] : 3600;

		if ($user === '') {
			throw new Exception('Action_AutoUpdate requires a GitHub user.');
		}

		if ($repo === '') {
			throw new Exception('Action_AutoUpdate requires a GitHub repository.');
		}

		if ($branch === '') {
			$branch = 'main';
		}

		if ($lockSeconds <= 0) {
			$lockSeconds = 3600;
		}

		if ($targetDir === '') {
			$targetDir = defined('DOCROOT') ? DOCROOT : dirname(dirname(dirname(__DIR__)));
		}

		if ($classFile === '') {
			$classFile = $targetDir . '/class/class.updater.php';
		}

		$targetDir = rtrim($targetDir, '/\\');
		$classFile = $this->normalisePath($classFile);

		if (!is_dir($targetDir) || !is_writable($targetDir)) {
			throw new Exception('Updater target directory is missing or not writable: ' . $targetDir);
		}

		if (!is_file($classFile) || !is_readable($classFile)) {
			throw new Exception('Updater class file was not found: ' . $classFile);
		}

		$lockFile = $targetDir . '/storage/workflow-auto-update.lock';
		$lockDir = dirname($lockFile);

		if (!is_dir($lockDir) && !mkdir($lockDir, 0750, true)) {
			throw new Exception('Unable to create updater lock directory: ' . $lockDir);
		}

		$this->checkLock($lockFile, $lockSeconds);
		file_put_contents($lockFile, (string)time(), LOCK_EX);

		try {
			require_once($classFile);

			if (!class_exists('Updater')) {
				throw new Exception('Updater class was not loaded: ' . $classFile);
			}

			$checkOptions = $this->getOptions($user, $repo, $branch, $targetDir, false);
			$updater = new Updater($checkOptions);
			$outdated = $updater->checkCommits(false, $targetDir);
			$updateCount = is_array($outdated) ? count($outdated) : 0;

			$context['system_update'] = array(
				'checked' => true,
				'updates_available' => ($updateCount > 0),
				'update_count' => $updateCount,
				'updated' => false,
				'user' => $user,
				'repo' => $repo,
				'branch' => $branch,
				'files' => is_array($outdated) ? $outdated : array()
			);

			if ($updateCount == 0) {
				return $context;
			}

			$updateOptions = $this->getOptions($user, $repo, $branch, $targetDir, true);
			$updater = new Updater($updateOptions);
			$result = $updater->checkCommits(true, $targetDir);

			$context['system_update']['updated'] = true;
			$context['system_update']['result'] = $result;
			$context['system_update']['completed'] = date('Y-m-d H:i:s');

			if (!isset($context['stats']) || !is_array($context['stats'])) {
				$context['stats'] = array();
			}

			$context['stats']['updated_files'] = $updateCount;

			return $context;
		} catch (Throwable $e) {
			throw $e;
		} finally {
			if (is_file($lockFile)) {
				unlink($lockFile);
			}
		}
	}

	/*************************************************************************
	 * Updater Helpers
	 *************************************************************************/

	/**
	 * Build Updater options.
	 *
	 * @param string $user
	 * @param string $repo
	 * @param string $branch
	 * @param string $targetDir
	 * @param bool   $doUpdate
	 *
	 * @return array
	 */
	protected function getOptions($user, $repo, $branch, $targetDir, $doUpdate)
	{
		return array(
			'user' => $user,
			'repo' => $repo,
			'branch' => $branch,
			'do_update' => $doUpdate,
			'target_dir' => $targetDir,
			'output' => false,
			'output_file' => $targetDir . '/storage/workflow-auto-update.log',
			'capture_requests' => false,
			'use_own_gui' => false
		);
	}

	/**
	 * Prevent overlapping update executions.
	 *
	 * @param string $lockFile
	 * @param int    $lockSeconds
	 */
	protected function checkLock($lockFile, $lockSeconds)
	{
		if (!is_file($lockFile)) {
			return;
		}

		$started = (int)file_get_contents($lockFile);

		if ($started > 0 && (time() - $started) < $lockSeconds) {
			throw new Exception('Automatic update is already running.');
		}

		unlink($lockFile);
	}

	/**
	 * Normalize local path.
	 *
	 * @param string $path
	 *
	 * @return string
	 */
	protected function normalisePath($path)
	{
		return str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, trim((string)$path));
	}
}
?>
