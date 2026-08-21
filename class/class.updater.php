<?php
/**
 * User Manager 3.0 - GitHub Core Updater
 * File: class.updater.php
 *
 * Cleaned version based on the provided Updater class.
 * Cleanup applied:
 * - Removed copied HTML anchor/link artifacts from URL strings.
 * - Kept the original check/update/clear flow.
 * - Kept admin access hook through $m->requirePageAccess('Administrators').
 * - Kept optional usage password support.
 * - Kept output logging through updatestep.info or configured output file.
 * - Added safer target file handling for downloaded files.
 * - Added directory creation before replacing individual files.
 * - Added basic HTTP status handling for cURL requests.
 * - Avoided PHP 8-only union return type on gitFileHash for wider PHP 7.4 compatibility.
 *
 * Basic usage:
 *
 * require_once(DOCROOT . '/classes/class.updater.php');
 *
 * $updater = new Updater([
 *     'user' => 'your-github-user',
 *     'repo' => 'your-repo',
 *     'branch' => 'main',
 *     'do_update' => true,
 *     'target_dir' => DOCROOT,
 *     'output' => true,
 *     'output_file' => DOCROOT . DIRECTORY_SEPARATOR . 'updatestep.info',
 *     'capture_requests' => true,
 *     'use_own_gui' => false,
 *     'usage_password' => '',
 *     'github_account' => [
 *         'user' => '',
 *         'pass' => ''
 *     ]
 * ]);
 *
 * $updater->run();
 */

class Updater
{
    private string $user = '';
    private string $repo = '';
    private string $branch = '';
    private bool $doUpdate = true;
    private string $targetDir;
    private bool $output = true;
	private string $statusFile;	
    private string $outputFile;
    private string $usagePassword = '';
	private array $removedFiles = [];
	private array $renamedFiles = [];
	private array $outdatedFiles = [];	
	private array $newFiles = [];
	private array $updatedFiles = [];
	private $protectedFolders = [];

    private bool $captureRequests = true;
    private bool $useOwnGui = true;
    private bool $commitCompleted = false;

    private array $githubAccount = [
        'user' => '',
        'pass' => ''
    ];

    public function __construct(array $settings = [])
    {
        $this->targetDir = __DIR__;
		$this->outputFile =	DOCROOT . DIRECTORY_SEPARATOR . 'updatestep.info';
		$this->statusFile = DOCROOT . DIRECTORY_SEPARATOR . 'update-status.json';
		$this->initializeUpdaterFiles();
        $this->user = (string)($settings['user'] ?? $this->user);
        $this->repo = (string)($settings['repo'] ?? $this->repo);
        $this->branch = (string)($settings['branch'] ?? $this->branch);
        $this->doUpdate = (bool)($settings['do_update'] ?? $this->doUpdate);
        $this->targetDir = rtrim((string)($settings['target_dir'] ?? $this->targetDir), DIRECTORY_SEPARATOR);
        $this->output = (bool)($settings['output'] ?? $this->output);
        $this->outputFile = (string)($settings['output_file'] ?? $this->outputFile);
        $this->usagePassword = (string)($settings['usage_password'] ?? $this->usagePassword);
        $this->captureRequests = (bool)($settings['capture_requests'] ?? $this->captureRequests);
        $this->useOwnGui = (bool)($settings['use_own_gui'] ?? $this->useOwnGui);
        $this->githubAccount = (array)($settings['github_account'] ?? $this->githubAccount);
		$this->protectedFolders = (array)($settings['protected_folders'] ?? $this->protectedFolders);
        if ($this->branch === '' && $this->user !== '' && $this->repo !== '') {
            $this->branch = $this->getBranch();
        }

        if ($this->branch === '') {
            $this->branch = 'main';
        }
    }

    /**
     * Run updater request handler, optional GUI, or maintenance check.
     */
    public function run(): void
    {
        $hasAction = false;
		$this->initializeUpdaterFiles();
        if ($this->captureRequests && isset($_REQUEST['updateaction'])) {
            $this->checkAccess();
            $hasAction = true;
            $this->handleAction((string)$_REQUEST['updateaction']);
        }

        if ($this->useOwnGui && !$hasAction) {
            $this->showGui();
            return;
        }

        if (!$hasAction && file_exists($this->outputFile) && filemtime($this->outputFile) + 60 < time()) {
            $this->checkCommits($this->doUpdate, $this->targetDir);
        }
    }

    /**
     * Require administrator access before updater actions run.
     */
    private function checkAccess(): void
    {
        global $m;

        if (isset($m) && method_exists($m, 'requirePageAccess')) {
            $m->requirePageAccess('Managers');
        }

        if ($this->usagePassword === '') {
            return;
        }

        if (!isset($_REQUEST['pw'])) {
            $this->directOut('Password missing', true, true);
            http_response_code(403);
            die('No password');
        }

        if (!hash_equals($this->usagePassword, (string)$_REQUEST['pw'])) {
            $this->directOut('Wrong Password', true, true);
            http_response_code(403);
            die('Wrong password');
        }
    }

    /**
     * Handle updater actions.
     */
    private function handleAction(string $action): void
    {
        if ($action === 'check') {
            $this->checkCommits(false, $this->targetDir);
            return;
        }

        if ($action === 'update') {
            $this->checkCommits(true, $this->targetDir);
            return;
        }

        if ($action === 'clear') {
            $this->directOut('', true, true);
            return;
        }

        $this->directOut('Unknown updater action: ' . $action, true, true);
    }

    /**
     * Quick direct use without GUI.
     */
    public function quickStart(): array
    {
        $files = $this->checkCommits($this->doUpdate, $this->targetDir);

        if (count($files) > 0) {
            if ($this->doUpdate) {
                echo $this->commitCompleted
                    ? '<h1>Updated ' . count($files) . ' files</h1>'
                    : '<h1>Complete update performed</h1>';
            } else {
                echo $this->commitCompleted
                    ? '<h1>Update for ' . count($files) . ' files available</h1>'
                    : '<h1>Full package update required</h1>';
            }
        }

        return $files;
    }

	/*************************************************************************
	 * Configuration Functions
	 *************************************************************************/

	/**
	 * Set protected folders.
	 *
	 * These folders will not be upda*ed unless
	 * override protection is enabled.
	 */
	public function setProtectedFolders(array $folders): void {
		$this->protectedFolders = array();
		foreach ($folders as $folder) {
			$folder = trim(
				str_rrplace(
					'\\',
					'/',
					(string)$folder
				),
				'/'
			);
			if ($folder !== '') {
				$this->protectedFolders[] =
					$folder;
			}
		}
	}

	/**
	 * Check the current GitHub repository tree and return files requiring update.
	 *
	 * getGitTreeFiles() must return:
	 * array(
	 *     'path/to/file.php' => array(
	 *         'sha' => 'git-blob-sha',
	 *         'type' => 'blob',
	 *         'size' => 123
	 *     )
	 * );
	 */
	public function checkCommits(bool $downloadIfNotMatching = false, ?string $dir = null): array
	{
		$this->newFiles = array();
		$this->updatedFiles = array();
		$this->outdatedFiles = array();
		$this->removedFiles = array();
		$this->renamedFiles = array();
		$this->commitCompleted = false;

		$currentFileMap = array();
		$checkedFileMap = array();
		$notMatching = array();

		$this->writeStatus(array(
			'status' => 'running',
			'message' => 'Reading current repository tree',
			'last_check' => date('Y-m-d H:i:s'),
			'files_checked' => 0,
			'file_checks' => 0,
			'current_files' => 0,
			'outdated_files' => 0,
			'new_files' => 0,
			'updated_files' => 0,
			'removed_files' => 0,
			'renamed_files' => 0,
			'new_file_list' => array(),
			'updated_file_list' => array(),
			'removed_file_list' => array(),
			'renamed_file_list' => array(),
			'outdated_file_list' => array()
		));

		$dir = rtrim(
			$dir !== null ? $dir : $this->targetDir,
			DIRECTORY_SEPARATOR
		);

		if ($this->user === '' || $this->repo === '') {
			$this->writeStatus(array(
				'status' => 'error',
				'message' => 'GitHub user or repository is not configured.',
				'last_check' => date('Y-m-d H:i:s')
			));

			$this->directOut(
				'GitHub user or repository is not configured.',
				true,
				true
			);

			return array();
		}

		$this->directOut('##############################', true, true);
		$this->directOut('Starting update process from', true);
		$this->directOut('https://github.com/' . $this->user . '/' . $this->repo);
		$this->directOut('Step 1: Get current repository tree');

		$repositoryFiles = $this->getGitTreeFiles(true);
		$repositoryCount = count($repositoryFiles);

		if ($repositoryCount === 0) {
			$this->writeStatus(array(
				'status' => 'error',
				'message' => 'GitHub returned no repository files.',
				'last_check' => date('Y-m-d H:i:s')
			));

			$this->directOut('GitHub returned no repository files. Update check stopped.');

			return array();
		}

		$this->directOut('-Github returned ' . $repositoryCount . ' files from the current ' . $this->branch . ' tree');
		$this->directOut('Step 2: Compare repository files with local files');
		$this->directOut('   Progress: ');

		$fcnt = 0;

		foreach ($repositoryFiles as $filename => $fileInfo) {
			$this->directOut('*', false);
			$fcnt++;

			if (!$this->isSafeRelativePath($filename)) {
				$this->directOut('Skipped unsafe path from repository: ' . $filename);
				continue;
			}

			$filesha = isset($fileInfo['sha']) ? (string)$fileInfo['sha'] : '';
			$filetype = isset($fileInfo['type']) ? (string)$fileInfo['type'] : 'blob';

			if ($filetype !== 'blob' || $filesha === '') {
				continue;
			}
			/*************************************************************************
			 * Protected Path Check
			 *************************************************************************/
			if ($this->isProtectedPath($filename)) {

				$this->protectedFiles[$filename] = array('file' => $filename, 'reason' => 'Protected Path');

				if ($this->output) {

					$this->directOut('Protected Path: ' . $filename);
				}
				continue;
			}
			
			$checkedFileMap[$filename] = true;
			$localFile = $dir . DIRECTORY_SEPARATOR . $filename;
			$localExists = file_exists($localFile);

			if (!$localExists) {
				$this->newFiles[$filename] = array(
					'file' => $filename,
					'sha' => $filesha,
					'status' => 'New File'
				);

				$notMatching[$filename] = $filename;
				continue;
			}

			$localHash = $this->gitFileHash($localFile);

			if ($localHash !== false && $filesha === $localHash) {
				$currentFileMap[$filename] = true;
				continue;
			}

			$this->updatedFiles[$filename] = array(
				'file' => $filename,
				'sha' => $filesha,
				'status' => 'Update Existing'
			);

			$notMatching[$filename] = $filename;
		}

		$this->directOut('-Update check completed for ' . count($checkedFileMap) . ' repository files');
		$this->directOut('##############################');

		$newFileList = array_values($this->newFiles);
		$updatedFileList = array_values($this->updatedFiles);

		$this->outdatedFiles = array_merge(
			$newFileList,
			$updatedFileList
		);

		$newCount = count($newFileList);
		$updatedCount = count($updatedFileList);
		$missfiles = $newCount + $updatedCount;
		$currentCount = count($currentFileMap);
		$removedCount = 0;
		$renamedCount = 0;

		/*
		 * The Tree API returns the current repository state only.
		 * Removed and renamed history is intentionally not derived from commits.
		 */
		$this->removedFiles = array();
		$this->renamedFiles = array();

		/*
		 * Tree comparisons support individual raw-file updates.
		 * Keep the existing full ZIP fallback for very large update sets.
		 */
		$this->commitCompleted = ($missfiles <= 299);

		$this->writeStatus(array(
			'status' => ($missfiles > 0 ? 'updates_available' : 'current'),
			'repository' => $this->repo,
			'branch' => $this->branch,
			'last_check' => date('Y-m-d H:i:s'),
			'files_checked' => count($checkedFileMap),
			'file_checks' => $fcnt,
			'current_files' => $currentCount,
			'outdated_files' => $missfiles,
			'new_files' => $newCount,
			'updated_files' => $updatedCount,
			'removed_files' => $removedCount,
			'renamed_files' => $renamedCount,
			'new_file_list' => $newFileList,
			'updated_file_list' => $updatedFileList,
			'removed_file_list' => array(),
			'renamed_file_list' => array(),
			'outdated_file_list' => $this->outdatedFiles,
			'message' => ($missfiles > 0 ? 'Updates available' : 'System up to date')
		));

		if ($missfiles > 299) {
			$this->directOut('More than 300 files require update. Full package update will be used.');
		} elseif ($missfiles > 0) {
			$this->directOut('Updates available: ' . $missfiles);
			$this->directOut('New files to install: ' . $newCount);

			foreach ($newFileList as $file) {
				$this->directOut('New File: ' . $file['file']);
			}

			$this->directOut('Existing files to update: ' . $updatedCount);

			foreach ($updatedFileList as $file) {
				$this->directOut('Update Existing: ' . $file['file']);
			}
		} else {
			$this->directOut('Your installation is up to date');
		}

		if ($downloadIfNotMatching && count($notMatching) > 0) {
			$this->downloadMissingFiles(
				$notMatching,
				$this->commitCompleted,
				$dir
			);
		}

		return $notMatching;
	}

    /**
     * Download changed files or full branch zip.
     */
    private function downloadMissingFiles(array $notMatching, bool $commitCompleted, ?string $dir = null): void
    {
        $dir = rtrim($dir ?? $this->targetDir, DIRECTORY_SEPARATOR);

        $this->directOut('Step 3: Perform the update');
        $this->directOut('by ');

        if ($commitCompleted) {
            $branch = $this->getBranch();
            $toUpdate = count($notMatching);

            $this->directOut('replacing individually ' . $toUpdate . ' files', false);
            $this->directOut('   Progress: ');

            foreach ($notMatching as $sha => $filename) {
                $this->directOut('*', false);

                if (!$this->isSafeRelativePath($filename)) {
                    $this->directOut('Skipped unsafe update path: ' . $filename);
                    continue;
                }

                $remoteurl = 'https://raw.githubusercontent.com/' . $this->user . '/' . $this->repo . '/' . rawurlencode($branch) . '/' . $this->encodePathForUrl($filename);
                $filetmp = $this->getSslPage($remoteurl);

                if ($filetmp === '') {
                    $this->directOut('Skipped empty download for ' . $filename);
                    continue;
                }

                $targetFile = $dir . DIRECTORY_SEPARATOR . $filename;
                $targetPath = dirname($targetFile);

                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0775, true);
                }

                file_put_contents($targetFile, $filetmp, LOCK_EX);
            }

            $this->directOut('Files copied');
            $this->directOut('Update completed');
            return;
        }

        $this->directOut('replacing all files from zip', false);
        $this->downloadMasterZipAndUnpack($dir);
    }

    /**
     * Get recent commits from GitHub.
     */
    private function getGitCommits(bool $renew = true): array
    {
        $tmpfile = $this->targetDir . DIRECTORY_SEPARATOR . 'git.commits.tmp';
        $commits = [];

        if ($renew && file_exists($tmpfile)) {
            unlink($tmpfile);
        }

        if (file_exists($tmpfile)) {
            $tmp = file_get_contents($tmpfile);
        } else {
            $url = 'https://api.github.com/repos/' . $this->user . '/' . $this->repo . '/commits?sha=' . rawurlencode($this->getBranch());
            $tmp = $this->getSslPage($url);
            file_put_contents($tmpfile, $tmp, LOCK_EX);
        }

        $data = json_decode((string)$tmp, true);

        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $comm) {
            if (!isset($comm['sha'])) {
                continue;
            }

            $commits[$comm['sha']]['url'] = $comm['commit']['url'] ?? '';
            $commits[$comm['sha']]['date'] = $comm['commit']['author']['date'] ?? '';
        }

        if (file_exists($tmpfile)) {
            unlink($tmpfile);
        }

        return $commits;
    }


	/**
	 * Get every file that exists in the current repository branch.
	 */
	private function getGitTreeFiles(bool $renew = true): array
	{
		$tmpfile =
			$this->targetDir .
			DIRECTORY_SEPARATOR .
			'git.tree.tmp';

		$files = array();

		if ($renew && file_exists($tmpfile)) {
			unlink($tmpfile);
		}

		if (file_exists($tmpfile)) {
			$tmp = file_get_contents($tmpfile);
		} else {
			$branchUrl =
				'https://api.github.com/repos/' .
				$this->user .
				'/' .
				$this->repo .
				'/commits/' .
				rawurlencode($this->getBranch());

			$branchResponse =
				$this->getSslPage($branchUrl);

			$branchData =
				json_decode(
					(string)$branchResponse,
					true
				);

			if (
				!is_array($branchData) ||
				!isset($branchData['commit']['tree']['sha'])
			) {
				$this->directOut(
					'Unable to determine the current repository tree.'
				);

				return array();
			}

			$treeSha =
				$branchData['commit']['tree']['sha'];

			$treeUrl =
				'https://api.github.com/repos/' .
				$this->user .
				'/' .
				$this->repo .
				'/git/trees/' .
				rawurlencode($treeSha) .
				'?recursive=1';

			$tmp = $this->getSslPage($treeUrl);

			if ($tmp !== '') {
				file_put_contents(
					$tmpfile,
					$tmp,
					LOCK_EX
				);
			}
		}

		$data =
			json_decode(
				(string)$tmp,
				true
			);

		if (
			!is_array($data) ||
			!isset($data['tree']) ||
			!is_array($data['tree'])
		) {
			$this->directOut(
				'GitHub returned invalid repository tree data.'
			);

			if (file_exists($tmpfile)) {
				unlink($tmpfile);
			}

			return array();
		}

		if (
			isset($data['truncated']) &&
			$data['truncated']
		) {
			$this->directOut(
				'GitHub repository tree response was truncated.'
			);

			if (file_exists($tmpfile)) {
				unlink($tmpfile);
			}

			return array();
		}

		foreach ($data['tree'] as $item) {
			if (
				!isset(
					$item['path'],
					$item['type'],
					$item['sha']
				)
			) {
				continue;
			}

			if ($item['type'] !== 'blob') {
				continue;
			}

			$filename = $item['path'];

			if (!$this->isSafeRelativePath($filename)) {
				$this->directOut(
					'Skipped unsafe repository path: ' .
					$filename
				);

				continue;
			}

			/*if ($this->isExcludedPath($filename)) {
				continue;
			}*/

			$files[$filename] = array(
				'sha' => $item['sha'],
				'type' => $item['type'],
				'size' => isset($item['size'])
					? (int)$item['size']
					: 0
			);
		}

		if (file_exists($tmpfile)) {
			unlink($tmpfile);
		}

		return $files;
	}


    /**
     * Get files changed in a specific commit.
     */
    private function getGitCommitFiles(string $commit, bool $renew = true): array
    {
        $tmpfile = $this->targetDir . DIRECTORY_SEPARATOR . 'git.commitfiles.tmp';
        $commits = [];

        if ($renew && file_exists($tmpfile)) {
            unlink($tmpfile);
        }

        if (file_exists($tmpfile)) {
            $tmp = file_get_contents($tmpfile);
        } else {
            $url = 'https://api.github.com/repos/' . $this->user . '/' . $this->repo . '/commits/' . rawurlencode($commit);
            $tmp = $this->getSslPage($url);
            file_put_contents($tmpfile, $tmp, LOCK_EX);
        }

        $data = json_decode((string)$tmp, true);

        if (!isset($data['files']) || !is_array($data['files'])) {
            return [];
        }

		foreach ($data['files'] as $comm) {

			if (
				!isset($comm['filename']) ||
				!$this->isSafeRelativePath($comm['filename'])
			) {
				continue;
			}

			$commits[$comm['filename']] = array(
				'sha' => $comm['sha'] ?? '',
				'status' => strtolower($comm['status'] ?? 'modified'),
				'previous_filename' => $comm['previous_filename'] ?? ''
			);
		}

        if (file_exists($tmpfile)) {
            unlink($tmpfile);
        }

        return $commits;
    }

    /**
     * Download branch zip and replace local files.
     */
    private function downloadMasterZipAndUnpack(?string $dir = null): void
    {
        $dir = rtrim($dir ?? $this->targetDir, DIRECTORY_SEPARATOR);
        $branch = $this->getBranch();
        $zipFile = $this->targetDir . DIRECTORY_SEPARATOR . $branch . '.zip';
        $tempDir = $this->targetDir . DIRECTORY_SEPARATOR . 'unpack_temp_dir';

        if (!class_exists('ZipArchive')) {
            $this->directOut('ZipArchive is not available on this server.');
            return;
        }

        if (!file_exists($zipFile)) {
            $remoteurl = 'https://github.com/' . $this->user . '/' . $this->repo . '/archive/' . rawurlencode($branch) . '.zip';
            $this->directOut('-Downloading ' . $remoteurl);
            file_put_contents($zipFile, $this->getSslPage($remoteurl, true), LOCK_EX);
        }

        if (is_dir($tempDir)) {
            $this->removeDirectory($tempDir);
        }

        mkdir($tempDir, 0775, true);

        $zip = new ZipArchive;

        if ($zip->open($zipFile) !== true) {
            $this->directOut('Error opening update zip');
            return;
        }

        $this->directOut('-Create temporary directory and unpack the archive');
        $zip->extractTo($tempDir);
        $this->directOut($zip->numFiles . ' files unpacked, start to copy them');

        $len = strlen($this->repo . '-' . $branch . '/');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $relative = substr($filename, $len);

            if ($relative === false || $relative === '' || !$this->isSafeRelativePath($relative)) {
                continue;
            }

            $currfile = $tempDir . DIRECTORY_SEPARATOR . $filename;
            $newfile = $dir . DIRECTORY_SEPARATOR . $relative;
            $lastchar = substr($currfile, -1);

            if ($lastchar !== '/' && $lastchar !== '\\') {
                @mkdir(dirname($newfile), 0775, true);
                copy($currfile, $newfile);
            } else {
                @mkdir($newfile, 0775, true);
            }
        }

        $zip->close();
        unlink($zipFile);
        $this->removeDirectory($tempDir);

        $this->directOut('Files copied');
        $this->directOut('Update completed');
    }

    /**
     * Get default branch from GitHub.
     */
    private function getBranch(): string
    {
        if ($this->branch !== '') {
            return $this->branch;
        }

        if ($this->user === '' || $this->repo === '') {
            $this->branch = 'main';
            return $this->branch;
        }

        $tmp = $this->getSslPage('https://api.github.com/repos/' . $this->user . '/' . $this->repo);
        $data = json_decode((string)$tmp, true);

        $this->branch = $data['default_branch'] ?? 'main';

        return $this->branch;
    }

	/**
	 * Calculate the exact Git blob hash for a local file.
	 *
	 * Do not normalize line endings here. The GitHub Tree API returns the SHA
	 * for the exact repository bytes. Removing carriage returns would cause a
	 * downloaded CRLF file to remain permanently different from GitHub.
	 * Return false when local file does not exist.
	 */
	private function gitFileHash(string $file2check)
	{
		if (!is_file($file2check) || !is_readable($file2check)) {
			return false;
		}

		$cont = file_get_contents($file2check);

		if ($cont === false) {
			return false;
		}

		$len = strlen($cont);
		$toc = 'blob ' . $len . chr(0) . $cont;

		return sha1($toc);
	}

    /**
     * Download URL using cURL with SSL verification enabled.
     */
    private function getSslPage(string $url, bool $nologin = false): string
    {
        if (!function_exists('curl_init')) {
            $this->directOut('cURL is not available on this server.');
            return '';
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_REFERER, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'UM-Manager-Updater');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        if (!$nologin && !empty($this->githubAccount['user']) && !empty($this->githubAccount['pass'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->githubAccount['user'] . ':' . $this->githubAccount['pass']);
        }

        $result = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($result === false) {
            $this->directOut('cURL error: ' . curl_error($ch));
            $result = '';
        } elseif ($httpCode >= 400) {
            $this->directOut('HTTP error ' . $httpCode . ' while requesting: ' . $url);
            $result = '';
        }

        curl_close($ch);

        return (string)$result;
    }

    /**
     * Write updater output to file.
     */
    private function directOut(string $output, bool $inNewLine = true, bool $emptyBefore = false): bool
    {
        if (!$this->output) {
            return true;
        }

        $tx = '';
        $outputDir = dirname($this->outputFile);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        if (!$emptyBefore && file_exists($this->outputFile)) {
            $tx = (string)file_get_contents($this->outputFile);

            if (strlen($tx) > 0 && $inNewLine) {
                $tx .= "\n";
            }
        }

        file_put_contents($this->outputFile, $tx . $output, LOCK_EX);

        return true;
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $object;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

	/**
	 * Ensure updater files exist.
	 */
	private function initializeUpdaterFiles(): void
	{
		if (!file_exists($this->outputFile)) {

			file_put_contents(
				$this->outputFile,
				'No update activity has been recorded yet.',
				LOCK_EX
			);
		}

		if (!file_exists($this->statusFile)) {

			$defaultStatus = array(
				'status'          => 'idle',
				'repository'      => $this->repo,
				'branch'          => $this->branch,
				'last_check'      => '',
				'outdated_files'  => 0,
				'removed_files'   => 0,
				'renamed_files'   => 0,
				'files_checked'   => 0,
				'message'         => 'Ready',
				'progress'        => 0
			);

			file_put_contents(
				$this->statusFile,
				json_encode(
					$defaultStatus,
					JSON_PRETTY_PRINT
				),
				LOCK_EX
			);
		}
	}
    /**
     * Wriste json Status.
     */
	private function writeStatus(array $data): void
	{
		$status = array_merge(
			[
				'status' => 'idle',
				'repository' => $this->repo,
				'branch' => $this->branch,
				'last_check' => '',
				'outdated_files' => 0,
				'removed_files' => 0,
				'renamed_files' => 0,
				'files_checked' => 0,
				'message' => ''
			],
			$data
		);

		if (!file_exists($this->statusFile)) {
			$this->initializeUpdaterFiles();
		}

		file_put_contents(
			$this->statusFile,
			json_encode(
				$status,
				JSON_PRETTY_PRINT
			),
			LOCK_EX
		);
	}
    /**
     * Keep repository paths from escaping the target directory.
     */
    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (preg_match('#(^|[\\/])\.\.([\\/]|$)#', $path)) {
            return false;
        }

        if (preg_match('#^[a-zA-Z]:[\\/]#', $path)) {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return false;
        }

        return true;
    }

	/*************************************************************************
	 * Update Protoction Functions
	 *************************************************************************/
	private function isProtectedPath(string $path): bool {
		$path = trim(str_replace('\\','/',$path		),'/');
		foreach ($this->protectedFolders as $folder) {
			$folder = trim(str_replace('\\','/',(string)$folder),'/');
			if ($folder === '') {
				continue;
			}
			if ($path === $folder || strpos($path, $folder . '/') === 0) {
				return true;
			}
		}
		return false;
	}

	/*************************************************************************
	 * Updater Exclusions
	 *************************************************************************/

	private function isExcludedPath(string $path): bool
	{
		$path = str_replace(
			'\\',
			'/',
			ltrim($path, '/')
		);

		$excluded = array(
		'uploads',
			'cache',
			'logs',
		'config/local',
			'custom'
		);

		foreach ($excluded as $exclude) {

		$exclude = trim(
				str_replace('\*', '/', $exclude),
				'/'
			);

			if (
				$path === $exclude ||
				strpos(
					$path,
					$exclude . '/') === 0
			) {
			return true;
			}
		}

		return false;
	}

    /**
     * Encode each path segment for raw GitHub URLs without destroying slashes.
     */
    private function encodePathForUrl(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $parts = array_map('rawurlencode', $parts);
        return implode('/', $parts);
    }

    /**
     * Simple admin GUI kept for backward compatibility.
     * In UM, prefer the separate UM Core Updater Console page and set use_own_gui to false.
     */
    private function showGui(): void
    {
        $port = '';

        if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443) {
            $port = ':' . $_SERVER['SERVER_PORT'];
        }

        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $scripturl = $scheme . '://' . $host . $port . $requestUri;
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GitHub Updater - <?php echo htmlspecialchars($this->user . '/' . $this->repo, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="jumbotron text-center">
                <h2>
                    GitHub Updater<br>
                    <a href="https://github.com/<?php echo htmlspecialchars($this->user . '/' . $this->repo . '/tree/' . $this->branch, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                        github.com/<?php echo htmlspecialchars($this->user . '/' . $this->repo, ENT_QUOTES, 'UTF-8'); ?> branch: <?php echo htmlspecialchars($this->branch, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </h2>

                <?php if ($this->usagePassword !== '') { ?>
                    <div class="form-group" style="max-width:300px;margin:15px auto;">
                        <label for="pw">Update password</label>
                        <input name="pw" id="pw" type="password" class="form-control" required>
                    </div>
                <?php } ?>

                <p>
                    <button class="btn btn-primary btn-large btnaction" id="btn1" onclick="UpdateFlow('0')">Check for updates</button>
                    <button class="btn btn-success btn-large btnaction" id="btn2" onclick="UpdateFlow('1')">Check for and perform updates</button>
                </p>
                <p id="update_result"></p>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script>
let RunUpdate = 0;
let WasRun = 0;
let Btn1 = '';
let Btn2 = '';
let WaitMsg = 'In progress...please wait';
let IsAuthorized = 1;
let GivenPW = '';

function CheckForPW(){
<?php if ($this->usagePassword !== '') { ?>
    GivenPW = $('#pw').val();
    if (GivenPW == '') {
        alert('Password required');
        IsAuthorized = 0;
    } else {
        GivenPW = '&pw=' + encodeURIComponent(GivenPW);
        IsAuthorized = 1;
    }
<?php } else { ?>
    IsAuthorized = 1;
<?php } ?>
}

function UpdateFlow(DoUpdate){
    CheckForPW();

    if (IsAuthorized != 1) {
        return;
    }

    Btn1 = $('#btn1').html();
    Btn2 = $('#btn2').html();
    $('#btn1').html(WaitMsg);
    $('#btn2').html(WaitMsg);
    $('.btnaction').prop('disabled', true);

    $.get('<?php echo htmlspecialchars($scripturl, ENT_QUOTES, 'UTF-8'); ?>?updateaction=clear' + GivenPW, function() {
        PrepareUpdate(DoUpdate);
    });
}

setInterval(function() {
    if (RunUpdate == 1) {
        WasRun = 1;
        GetState(RunUpdate);
    } else if (WasRun == 1) {
        GetState(0);
        WasRun = 0;
    }
}, 1000);

function PrepareUpdate(DoUpdate){
    let DoAction = DoUpdate == 1 ? 'update' : 'check';
    RunUpdate = 1;

    $.get('<?php echo htmlspecialchars($scripturl, ENT_QUOTES, 'UTF-8'); ?>?updateaction=' + DoAction + GivenPW, function(response) {
<?php if (!$this->output) { ?>
        $('#update_result').html(response);
<?php } ?>
        RunUpdate = 0;
        GetState(0);
        $('.btnaction').prop('disabled', false);
        $('#btn1').html(Btn1);
        $('#btn2').html(Btn2);
    });

    GetState(1);
}

<?php if ($this->output) { ?>
function GetState(StatVal){
    RunUpdate = StatVal;
    $.get('<?php echo htmlspecialchars(basename($this->outputFile), ENT_QUOTES, 'UTF-8'); ?>', function(response) {
        $('#update_result').html(String(response).replace(/\n/g, '<br>'));
    });
}
<?php } else { ?>
function GetState(StatVal){}
<?php } ?>
</script>
</body>
</html>
        <?php
    }
}
?>