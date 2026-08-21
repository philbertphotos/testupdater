<?php
/*************************************************************************
 * User Manager 3.0 - Code Editor
 * File: code-editor.php
 *
 * Important request-handling change:
 * AJAX actions use POST field "editor_action" instead of query-string
 * actions. Action responses are processed before header.php is included,
 * ensuring JSON is not contaminated by page HTML or router query handling.
 *************************************************************************/
if (!defined('ROUTER_REQUEST')) {
	require_once(dirname(__DIR__) . '/env.php');
}
$m->requirePageAccess('Managers');

/*************************************************************************
 * Configuration
 *************************************************************************/
$editorResource = 'editor';
$editorMaxBytes = 1048576;
$editorAllowedExtensions = array('php', 'phtml', 'inc', 'js', 'css', 'html', 'htm', 'json', 'txt', 'md', 'sql', 'xml', 'yml', 'yaml');
$editorProtectedFiles = array('env.php', '.env', 'config.php', 'configuration.php', 'settings.php');
function editor_first_directory($paths)
{
	foreach ($paths as $path) {
		$path = rtrim((string)$path, DIRECTORY_SEPARATOR);
		if ($path === '') {
			continue;
		}
		$resolved = realpath($path);
		if ($resolved !== false && is_dir($resolved) && is_readable($resolved)) {
			return $resolved;
		}
	}
	return false;
}
function editor_base_directories()
{
	$bases = array();
	if (defined('DOCROOT') && DOCROOT !== '') {
		$bases[] = DOCROOT;
	}
	if (!empty($_SERVER['DOCUMENT_ROOT'])) {
		$bases[] = $_SERVER['DOCUMENT_ROOT'];
	}
	$bases[] = dirname(__DIR__);
	$resolvedBases = array();
	foreach ($bases as $base) {
		$resolved = realpath($base);
		if ($resolved !== false && is_dir($resolved) && !in_array($resolved, $resolvedBases, true)) {
			$resolvedBases[] = $resolved;
		}
	}
	return $resolvedBases;
}
function editor_root_candidates($subDirectories)
{
	$candidates = array();
	foreach (editor_base_directories() as $base) {
		foreach ((array)$subDirectories as $subDirectory) {
			$subDirectory = trim((string)$subDirectory, '/\\');
			$candidates[] = $subDirectory === '' ? $base : $base . DIRECTORY_SEPARATOR . $subDirectory;
		}
	}
	return $candidates;
}
function editor_build_roots()
{
	$roots = array(
		'custom' => array('label' => 'Custom', 'path' => editor_first_directory(editor_root_candidates(array('/admin/custom/')))),
		'workflows' => array('label' => 'Workflows', 'path' => editor_first_directory(editor_root_candidates(array('/admin/workflows/')))),
		//'admin' => array('label' => 'Admin', 'path' => editor_first_directory(editor_root_candidates(array('admin')))),
		//'classes' => array('label' => 'Classes', 'path' => editor_first_directory(editor_root_candidates(array('classes', 'class')))),
		//'templates' => array('label' => 'Templates', 'path' => editor_first_directory(editor_root_candidates(array('templates', 'template')))),
		//'javascript' => array('label' => 'JavaScript', 'path' => editor_first_directory(editor_root_candidates(array('js', 'javascript')))),
		//'styles' => array('label' => 'Styles', 'path' => editor_first_directory(editor_root_candidates(array('css', 'styles', 'assets/css', 'public/css'))))
	);
	foreach ($roots as $rootKey => $rootInfo) {
		if ($rootInfo['path'] === false) {
			unset($roots[$rootKey]);
		}
	}
	return $roots;
}
$editorRoots = editor_build_roots();

/*
 * Routed pages can be included from inside a Router callback or method scope.
 * Publish editor configuration to the global symbol table because helper
 * functions use global variables and execute outside that include scope.
 */
$GLOBALS['editorRoots'] = $editorRoots;
$GLOBALS['editorResource'] = $editorResource;
$GLOBALS['editorMaxBytes'] = $editorMaxBytes;
$GLOBALS['editorAllowedExtensions'] = $editorAllowedExtensions;
$GLOBALS['editorProtectedFiles'] = $editorProtectedFiles;

/*************************************************************************
 * Authorization
 *************************************************************************/
function editor_can($action)
{
	global $acl, $editorResource;
	try {
		return true;
		return $acl->isAllowed($acl->getRole(), $action, $editorResource);
	} catch (Throwable $e) {
		return false;
	}
}
$canView = editor_can('view') || editor_can('edit') || editor_can('admin');
$canEdit = editor_can('edit') || editor_can('admin');
$canEvaluate = editor_can('execute') || editor_can('edit') || editor_can('admin');
$canDownload = editor_can('download') || editor_can('view') || editor_can('admin');
if (!$canView) {
	http_response_code(403);
	die('Access denied. The editor view permission is required.');
}

/*************************************************************************
 * General Helpers
 *************************************************************************/
function editor_h($value)
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function editor_json_response($data, $status = 200)
{
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	http_response_code($status);
	header('Content-Type: application/json; charset=UTF-8');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	echo json_encode($data);
	exit;
}
function editor_csrf_token()
{
	if (empty($_SESSION['code_editor_csrf'])) {
		$_SESSION['code_editor_csrf'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['code_editor_csrf'];
}
function editor_validate_csrf()
{
	$token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
	return $token !== '' && !empty($_SESSION['code_editor_csrf']) && hash_equals($_SESSION['code_editor_csrf'], $token);
}
function editor_extension($path)
{
	return strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
}
function editor_language($path)
{
	$extension = editor_extension($path);
	$map = array('php' => 'php', 'phtml' => 'php', 'inc' => 'php', 'js' => 'javascript', 'css' => 'css', 'html' => 'html', 'htm' => 'html', 'json' => 'json', 'sql' => 'sql', 'xml' => 'xml', 'yml' => 'yaml', 'yaml' => 'yaml', 'md' => 'markdown');
	return isset($map[$extension]) ? $map[$extension] : 'text';
}
function editor_is_protected($path)
{
	global $editorProtectedFiles;
	return in_array(strtolower(basename((string)$path)), array_map('strtolower', $editorProtectedFiles), true);
}
function editor_normalize_relative($path)
{
	$path = str_replace('\\', '/', trim((string)$path));
	$path = ltrim($path, '/');
	if ($path === '' || strpos($path, "\0") !== false) {
		return '';
	}
	$clean = array();
	foreach (explode('/', $path) as $part) {
		if ($part === '' || $part === '.') {
			continue;
		}
		if ($part === '..') {
			return '';
		}
		$clean[] = $part;
	}
	return implode('/', $clean);
}
function editor_resolve_path($rootKey, $relative)
{
	global $editorRoots;
	if (!isset($editorRoots[$rootKey])) {
		return false;
	}
	$relative = editor_normalize_relative($relative);
	if ($relative === '') {
		return false;
	}
	$root = $editorRoots[$rootKey]['path'];
	$resolved = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
	if ($resolved === false) {
		return false;
	}
	$rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	if ($resolved !== $root && strpos($resolved, $rootPrefix) !== 0) {
		return false;
	}
	return $resolved;
}
function editor_allowed_file($path)
{
	global $editorAllowedExtensions;
	return is_file($path) && in_array(editor_extension($path), $editorAllowedExtensions, true);
}
function editor_relative_path($root, $path)
{
	$root = rtrim(str_replace('\\', '/', $root), '/');
	$path = str_replace('\\', '/', $path);
	return ltrim(substr($path, strlen($root)), '/');
}
function editor_file_meta($rootKey, $path)
{
	global $editorRoots;
	return array('root' => $rootKey, 'path' => editor_relative_path($editorRoots[$rootKey]['path'], $path), 'name' => basename($path), 'extension' => editor_extension($path), 'language' => editor_language($path), 'size' => filesize($path), 'modified' => date('Y-m-d H:i:s', filemtime($path)), 'writable' => is_writable($path) && !editor_is_protected($path), 'protected' => editor_is_protected($path));
}
function editor_scan_root($rootKey, $query = '')
{
	global $editorRoots, $editorMaxBytes;
	if (!isset($editorRoots[$rootKey])) {
		throw new RuntimeException('The requested editor root is unavailable: ' . $rootKey);
	}
	$root = $editorRoots[$rootKey]['path'];
	$query = strtolower(trim((string)$query));
	$files = array();
	try {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $item) {
			if (!$item->isFile() || !$item->isReadable()) {
				continue;
			}
			$path = $item->getRealPath();
			if ($path === false || !editor_allowed_file($path) || $item->getSize() > $editorMaxBytes) {
				continue;
			}
			$relative = editor_relative_path($root, $path);
			if ($query !== '' && strpos(strtolower($relative), $query) === false) {
				continue;
			}
			$files[] = editor_file_meta($rootKey, $path);
			if (count($files) >= 500) {
				break;
			}
		}
	} catch (UnexpectedValueException $e) {
		throw new RuntimeException('The editor cannot read the configured directory: ' . $root);
	}
	usort($files, function($a, $b) {
		return strcasecmp($a['path'], $b['path']);
	});
	return $files;
}
function editor_audit($action, $path, $status, $details = '')
{
	$message = 'Code editor ' . $action . ' [' . $status . '] ' . $path . ($details !== '' ? ' - ' . $details : '');
	if (function_exists('_save_log')) {
		_save_log('code-editor', $status === 'success' ? 'info' : 'error', $message);
	} else {
		error_log($message);
	}
}


/*************************************************************************
 * Validation Helpers
 *************************************************************************/
function editor_php_lint($code)
{
	if (!function_exists('exec')) {
		return array('ok' => false, 'message' => 'PHP CLI validation is unavailable because exec() is disabled.');
	}
	$temp = tempnam(sys_get_temp_dir(), 'um_editor_');
	if ($temp === false) {
		return array('ok' => false, 'message' => 'Unable to create a temporary validation file.');
	}
	$file = $temp . '.php';
	@rename($temp, $file);
	file_put_contents($file, $code, LOCK_EX);
	$binary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
	$output = array();
	$status = 1;
	exec(escapeshellarg($binary) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
	@unlink($file);
	return array('ok' => $status === 0, 'message' => trim(implode("\n", $output)));
}
function editor_evaluate($language, $code)
{
	$language = strtolower((string)$language);
	$result = array('ok' => true, 'language' => $language, 'lines' => substr_count($code, "\n") + 1, 'bytes' => strlen($code), 'checks' => array());
	if ($language === 'php') {
		$lint = editor_php_lint($code);
		$result['ok'] = $lint['ok'];
		$result['checks'][] = array('name' => 'PHP syntax', 'ok' => $lint['ok'], 'message' => $lint['message']);
	} elseif ($language === 'json') {
		json_decode($code, true);
		$valid = json_last_error() === JSON_ERROR_NONE;
		$result['ok'] = $valid;
		$result['checks'][] = array('name' => 'JSON syntax', 'ok' => $valid, 'message' => $valid ? 'Valid JSON document.' : json_last_error_msg());
	} elseif ($language === 'css') {
		$valid = substr_count($code, '{') === substr_count($code, '}');
		$result['ok'] = $valid;
		$result['checks'][] = array('name' => 'CSS block balance', 'ok' => $valid, 'message' => $valid ? 'CSS blocks are balanced.' : 'Unbalanced CSS blocks detected.');
	} elseif ($language === 'javascript') {
		$valid = substr_count($code, '{') === substr_count($code, '}') && substr_count($code, '(') === substr_count($code, ')') && substr_count($code, '[') === substr_count($code, ']');
		$result['ok'] = $valid;
		$result['checks'][] = array('name' => 'Delimiter balance', 'ok' => $valid, 'message' => $valid ? 'Braces, brackets and parentheses are balanced.' : 'Unbalanced delimiters detected.');
	} else {
		$result['checks'][] = array('name' => 'Text review', 'ok' => true, 'message' => 'No executable evaluation is configured for this file type.');
	}
	$result['checks'][] = array('name' => 'Non-execution policy', 'ok' => true, 'message' => 'Submitted source was validated but not executed by the web process.');
	return $result;
}

/*************************************************************************
 * Action Request Handling
 *************************************************************************/
$csrf = editor_csrf_token();
$action = isset($_POST['editor_action']) ? strtolower(trim((string)$_POST['editor_action'])) : '';

if ($action === 'list') {
	$rootKey = isset($_POST['root']) ? trim((string)$_POST['root']) : '';
	$query = isset($_POST['query']) ? trim((string)$_POST['query']) : '';
	if (!isset($editorRoots[$rootKey])) {
		editor_json_response(array('result' => 0, 'root' => $rootKey, 'path' => '', 'count' => 0, 'available' => false, 'available_roots' => array_keys($editorRoots), 'files' => array()));
	}
	try {
		$files = editor_scan_root($rootKey, $query);
		editor_json_response(array('result' => 0, 'root' => $rootKey, 'path' => $editorRoots[$rootKey]['path'], 'count' => count($files), 'available' => true, 'files' => $files));
	} catch (Throwable $e) {
		editor_json_response(array('result' => 1, 'root' => $rootKey, 'info' => $e->getMessage()), 400);
	}
}


/*************************************************************************
 * Delete File
 *************************************************************************/

if ($action === 'delete') {

	if (!$canEdit || !editor_validate_csrf()) {
		editor_json_response(array('result' => 1, 'info' => 'Delete access or CSRF validation failed.'), 403);
	}

	$rootKey = isset($_POST['root']) ? (string)$_POST['root'] : '';
	$relative = isset($_POST['path']) ? (string)$_POST['path'] : '';

	$path = editor_resolve_path($rootKey, $relative);

	if ($path === false || !editor_allowed_file($path) || editor_is_protected($path)) {
		editor_json_response(array('result' => 1, 'info' => 'The selected file cannot be deleted.'), 403);
	}

	if (!file_exists($path)) {
		editor_json_response(array('result' => 1, 'info' => 'File not found.'), 404);
	}

	$backupDir = DOCROOT . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'code-editor';

	if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true)) {
		editor_json_response(array('result' => 1, 'info' => 'Unable to create the backup directory.'), 500);
	}

	$backupName =
		date('Ymd_His') . '_DELETED_' .
		preg_replace(
			'/[^a-zA-Z0-9._-]/',
			'_',
			$rootKey . '_' . $relative
		);

	if (!copy($path, $backupDir . DIRECTORY_SEPARATOR . $backupName)) {
		editor_json_response(array('result' => 1, 'info' => 'Unable to create a backup.'), 500);
	}

	if (!unlink($path)) {
		editor_json_response(array('result' => 1, 'info' => 'Unable to delete file.'), 500);
	}

	editor_audit(
		'delete',
		$relative,
		'success',
		'Backup: ' . $backupName
	);

	editor_json_response(array(
		'result' => 0,
		'info' => 'File deleted successfully.',
		'backup' => $backupName
	));

}
/*************************************************************************
 * Create File
 *************************************************************************/

if ($action === 'newfile') {

	if (!$canEdit) {
		editor_json_response(array('result' => 1, 'info' => 'Create file access or CSRF validation failed.'), 403);
	}

	$rootKey = isset($_POST['root']) ? (string)$_POST['root'] : '';
	$relative = isset($_POST['path']) ? trim((string)$_POST['path']) : '';

	if ($relative === '') {
		editor_json_response(array('result' => 1, 'info' => 'File path is required.'), 400);
	}

	if (!isset($editorRoots[$rootKey])) {
		editor_json_response(array('result' => 1, 'info' => 'Invalid root.'), 400);
	}

	$path = $editorRoots[$rootKey]['path'] . DIRECTORY_SEPARATOR . str_replace(array('/', '\*'), DIRECTORY_SEPARATOR, $relative);

	$directory = dirname($path);

	if (!is_dir($directory) && !mkdir($directory, 0770, true)) {
		editor_json_response(array('result' => 1, 'info' => 'Unable to create directory.'), 500);
	}

	if (file_exists($path)) {
		editor_json_response(array('result' => 1, 'info' => 'File already exists.'), 409);
	}

	if (file_put_contents($path, '') === false) {
		editor_json_response(array('result' => 1, 'info' => 'Unable to create file.'), 500);
	}

	editor_audit(
		'create',
		$relative,
		'success'
	);

	editor_json_response(array(
		'result' => 0,
		'info' => 'File created successfully.',
		'file' => editor_file_meta($rootKey, $path)
	));

}
/*************************************************************************
 * List All Explorer Roots
 *************************************************************************/

if ($action === 'listall') {

	$query = isset($_POST['query'])
		? trim((string)$_POST['query'])
		: '';
	$roots = array();
	foreach ($editorRoots as $rootKey => $rootInfo) {
		try {
			$roots[$rootKey] = array(
				'root' => $rootKey,
				'label' => $rootInfo['label'],
				'path' => $rootInfo['path'],
				'files' => editor_scan_root(
					$rootKey,
					$query
				)
			);
		} catch (Throwable $e) {
			$roots[$rootKey] = array(
				'root' => $rootKey,
				'label' => $rootInfo['label'],
				'path' => '',
				'error' => $e->getMessage(),
				'files' => array()
			);
		}
	}
	editor_json_response(
		array(
			'result' => 0,
			'roots' => $roots
		)
	);
}
if ($action === 'open') {
	$rootKey = isset($_POST['root']) ? (string)$_POST['root'] : '';
	$relative = isset($_POST['path']) ? (string)$_POST['path'] : '';
	$path = editor_resolve_path($rootKey, $relative);
	if ($path === false || !editor_allowed_file($path)) {
		editor_json_response(array('result' => 1, 'info' => 'Invalid or unsupported file.'), 400);
	}
	if (filesize($path) > $editorMaxBytes) {
		editor_json_response(array('result' => 1, 'info' => 'File exceeds the editor size limit.'), 400);
	}
	$content = file_get_contents($path);
	if ($content === false) {
		editor_json_response(array('result' => 1, 'info' => 'Unable to read the selected file.'), 500);
	}
	editor_json_response(array('result' => 0, 'file' => editor_file_meta($rootKey, $path), 'content' => $content));
}
if ($action === 'evaluate') {
	if (!$canEvaluate || !editor_validate_csrf()) {
		editor_json_response(array('result' => 1, 'info' => 'Evaluation access or CSRF validation failed.'), 403);
	}
	$code = isset($_POST['code']) ? (string)$_POST['code'] : '';
	if (strlen($code) > $editorMaxBytes) {
		editor_json_response(array('result' => 1, 'info' => 'Submitted code exceeds the editor size limit.'), 400);
	}
	$evaluation = editor_evaluate(isset($_POST['language']) ? (string)$_POST['language'] : 'text', $code);
	editor_audit('evaluate', isset($_POST['path']) ? (string)$_POST['path'] : 'buffer', $evaluation['ok'] ? 'success' : 'failed');
	editor_json_response(array('result' => 0, 'evaluation' => $evaluation));
}
if ($action === 'save') {
	if (!$canEdit || !editor_validate_csrf()) {
		editor_json_response(array('result' => 1, 'info' => 'Save access or CSRF validation failed.'), 403);
	}
	$rootKey = isset($_POST['root']) ? (string)$_POST['root'] : '';
	$relative = isset($_POST['path']) ? (string)$_POST['path'] : '';
	$code = isset($_POST['code']) ? (string)$_POST['code'] : '';
	$path = editor_resolve_path($rootKey, $relative);
	if ($path === false || !editor_allowed_file($path) || editor_is_protected($path) || !is_writable($path)) {
		editor_json_response(array('result' => 1, 'info' => 'The selected file cannot be saved.'), 403);
	}
	if (strlen($code) > $editorMaxBytes) {
		editor_json_response(array('result' => 1, 'info' => 'Submitted code exceeds the editor size limit.'), 400);
	}
	$evaluation = editor_evaluate(editor_language($path), $code);
	if (editor_language($path) === 'php' && !$evaluation['ok']) {
		//editor_json_response(array('result' => 1, 'info' => 'Save blocked because PHP validation failed.', 'evaluation' => $evaluation), 422);
	}
	$backupDir = DOCROOT . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'code-editor';
	if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true)) {
		editor_json_response(array('result' => 1, 'info' => 'Unable to create the backup directory.'), 500);
	}
	$backupName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $rootKey . '_' . $relative) . '.bak';
	if (!copy($path, $backupDir . DIRECTORY_SEPARATOR . $backupName)) {
		editor_json_response(array('result' => 1, 'info' => 'Unable to create a backup.'), 500);
	}
	$tempPath = $path . '.um-editor-' . bin2hex(random_bytes(6)) . '.tmp';
	if (file_put_contents($tempPath, $code, LOCK_EX) === false || !rename($tempPath, $path)) {
		@unlink($tempPath);
		editor_json_response(array('result' => 1, 'info' => 'Unable to replace the source file.'), 500);
	}
	editor_audit('save', $relative, 'success', 'Backup: ' . $backupName);
	editor_json_response(array('result' => 0, 'info' => 'File saved successfully.', 'file' => editor_file_meta($rootKey, $path), 'backup' => $backupName, 'evaluation' => $evaluation));
}
if ($action === 'download') {
	if (!$canDownload || !editor_validate_csrf()) {
		http_response_code(403);
		die('Download access or CSRF validation failed.');
	}
	$rootKey = isset($_POST['root']) ? (string)$_POST['root'] : '';
	$relative = isset($_POST['path']) ? (string)$_POST['path'] : '';
	$path = editor_resolve_path($rootKey, $relative);
	if ($path === false || !editor_allowed_file($path)) {
		http_response_code(404);
		die('File not found.');
	}
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	header('Content-Type: text/plain; charset=UTF-8');
	header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($path)) . '"');
	header('Content-Length: ' . filesize($path));
	readfile($path);
	exit;
}

/*************************************************************************
 * Normal Page Output
 *************************************************************************/
include($_SERVER['DOCUMENT_ROOT'] . '/header.php');
?>
<style>
/*************************************************************************
 * Page Layout
 *************************************************************************/
.code-editor-page { margin-top: 15px; }
.code-editor-page .page-header { margin-top: 0; border-bottom: 0; }
.code-editor-title { margin: 0 0 5px; font-weight: 600; }
.code-editor-subtitle { color: #777; margin: 0; }
.editor-security-alert { margin-bottom: 12px; border-radius: 6px; }
.editor-shell { border: 1px solid #263241; border-radius: 7px; overflow: hidden; background: #111827; box-shadow: 0 4px 16px rgba(15, 23, 42, .12); }
.editor-toolbar { padding: 9px 10px; background: #f7f7f7; border-bottom: 1px solid #ddd; }
.editor-toolbar .btn { margin-right: 4px; margin-bottom: 3px; }
.editor-toolbar .input-group { max-width: 420px; float: right; }
.editor-workspace { display: flex; height: 680px; min-height: 520px; background: #111827; }


/*************************************************************************
 * Explorer Toggle
 *************************************************************************/

.editor-explorer {
	width: 300px;
	flex: 0 0 300px;

	overflow: hidden;

	transition:
		width .25s ease,
		flex-basis .25s ease,
		opaci*y .20s ease;
}

.editor-shell.explorer-hidden .editor-explorer {
	wid*h: 0;
	flex-basis: 0;
	opacity: 0;
	border-right: 0;
}

.editor-content {
	transition: all .25s ease;
}

.editor-shell.explorer-hidden .editor-activitybar {
	width: 48px;
}

.editor-shell.explorer-hidden .editor-content {
	width: 100%;
}
/*************************************************************************
 * Explorer Toggle Button
 *************************************************************************/

.editor-toggle-button {
	width: 38px;
	height: 38px;

	border: 0;

	background: #111827;

	color: #cbd5e1;

	transition:
		background .2s ease,
		color .2s ease;
}

.editor-toggle-button:hover {
	background: #1e293b;
	color: #ffffff;
}

.editor-toggle-button.active {
	background: #2563ebc
	color: #ffffff;
}	
/*************************************************************************
 * Activity Bar and Project Explorer
 *************************************************************************/
.editor-activitybar { width: 48px; flex: 0 0 48px; background: #0f172a; border-right: 1px solid #263241; color: #94a3b8; text-align: center; }
.editor-activity-button { display: block; width: 100%; padding: 14px 0; border: 0; border-left: 2px solid transparent; background: transparent; color: #94a3b8; font-size: 18px; }
.editor-activity-button:hover, .editor-activity-button.active { color: #fff; background: #172033; border-left-color: #3b82f6; }
.editor-explorer { width: 300px; flex: 0 0 300px; background: #172033; color: #cbd5e1; border-right: 1px solid #263241; overflow: hidden; display: flex; flex-direction: column; }
.editor-explorer-header { flex: 0 0 auto; padding: 12px 14px; color: #f8fafc; background: #111827; border-bottom: 1px solid #263241; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.editor-explorer-search { flex: 0 0 auto; padding: 9px; border-bottom: 1px solid #263241; }
.editor-explorer-search .form-control { color: #e2e8f0; background: #0f172a; border-color: #334155; box-shadow: none; }
.editor-explorer-search .input-group-addon { color: #94a3b8; background: #0f172a; border-color: #334155; }
.editor-root-list { flex: 1 1 auto; overflow: auto; padding-bottom: 12px; }
.editor-root { border-bottom: 1px solid rgba(148, 163, 184, .12); }
.editor-root-title { display: flex; align-items: center; padding: 9px 10px; color: #cbd5e1; background: #172033; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; cursor: pointer; user-select: none; }
.editor-root-title:hover { background: #1e293b; }
.editor-root-toggle { width: 16px; margin-right: 4px; color: #94a3b8; }
.editor-root-label { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.editor-root-count { min-width: 24px; padding: 1px 7px; border-radius: 10px; color: #cbd5e1; background: #334155; text-align: center; font-size: 10px; }
.editor-file-list { list-style: none; padding: 0; margin: 0; }
.editor-file-item { display: flex; align-items: center; width: 100%; min-height: 31px; padding: 5px 9px 5px 20px; border: 0; background: transparent; color: #cbd5e1; text-align: left; font: 12px/1.4 Consolas, Monaco, 'Courier New', monospace; }
.editor-file-item:hover { color: #fff; background: #243147; }
.editor-file-item.active { color: #fff; background: #1d4ed8; }
.editor-file-icon { width: 19px; margin-right: 5px; text-align: center; }
.editor-file-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.editor-file-badge { margin-left: 7px; color: #94a3b8; font-size: 9px; text-transform: uppercase; }
.editor-file-item.active .editor-file-badge { color: #dbeafe; }
.editor-file-loading, .editor-file-empty, .editor-file-error { padding: 8px 12px 8px 25px; color: #94a3b8; font-size: 11px; }
.editor-file-error { color: #fca5a5; }
.file-icon-php { color: #818cf8; }
.file-icon-js { color: #facc15; }
.file-icon-css { color: #38bdf8; }
.file-icon-json { color: #fb923c; }
.file-icon-html { color: #f87171; }
.file-icon-text { color: #cbd5e1; }
.file-icon-data { color: #34d399; }

/*************************************************************************
 * Editor Area
 *************************************************************************/
.editor-content { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; background: #0f172a; }
.editor-tabbar { flex: 0 0 39px; display: flex; align-items: stretch; background: #111827; border-bottom: 1px solid #263241; overflow: hidden; }
.editor-tab { display: flex; align-items: center; min-width: 180px; max-width: 360px; padding: 0 13px; color: #cbd5e1; background: #172033; border-right: 1px solid #263241; font-size: 12px; }
.editor-tab-icon { margin-right: 7px; }
.editor-tab-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.editor-tab .dirty { display: none; margin-left: 7px; color: #fbbf24; }
.editor-tab.is-dirty .dirty { display: inline; }
.editor-breadcrumb { flex: 0 0 31px; padding: 7px 12px; color: #94a3b8; background: #0f172a; border-bottom: 1px solid #1e293b; font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.editor-breadcrumb .separator { padding: 0 6px; color: #475569; }
.editor-stage { position: relative; flex: 1 1 auto; min-height: 0; background: #0b1220; }
.editor-empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #64748b; text-align: center; }
.editor-empty .glyphicon { display: block; margin-bottom: 14px; font-size: 46px; opacity: .45; }
.editor-textarea, .editor-textarea:focus { width: 100%; height: 100%; min-height: 0; padding: 16px 18px; border: 0 !important; outline: 0 !important; resize: none; background: #0b1220 !important; color: #e2e8f0 !important; font: 13px/1.58 Consolas, Monaco, 'Courier New', monospace; tab-size: 4; box-shadow: none !important; caret-color: #fff; }
.editor-textarea::selection, .fullscreen-editor-textarea::selection { color: #fff; background: #264f78; }
.editor-statusbar { flex: 0 0 25px; display: flex; align-items: center; justify-content: space-between; padding: 0 10px; color: #dbeafe; background: #1d4ed8; font-size: 10px; }
.editor-status-left, .editor-status-right { display: flex; align-items: center; gap: 14px; }
.editor-status-item { white-space: nowrap; }

/*************************************************************************
 * Bottom Panel
 *************************************************************************/
.editor-bottom-panel { background: #fff; border-top: 1px solid #263241; }
.editor-bottom-panel .nav-tabs { padding-left: 8px; background: #f8fafc; border-bottom-color: #dbe1e8; }
.editor-bottom-panel .nav-tabs > li > a { margin: 0; padding: 9px 13px; border: 0; border-bottom: 2px solid transparent; color: #64748b; background: transparent; font-size: 11px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; }
.editor-bottom-panel .nav-tabs > li.active > a, .editor-bottom-panel .nav-tabs > li.active > a:hover { color: #1d4ed8; background: transparent; border: 0; border-bottom: 2px solid #2563eb; }
.editor-bottom-content { min-height: 150px; max-height: 280px; overflow: auto; padding: 13px 15px; }
.editor-info-grid { display: grid; grid-template-columns: repeat(6, minmax(120px, 1fr)); gap: 12px; }
.editor-info-card { min-width: 0; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 5px; background: #f8fafc; }
.editor-info-label { margin-bottom: 4px; color: #64748b; font-size: 9px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.editor-info-value { color: #1e293b; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.editor-check { padding: 9px 11px; margin-bottom: 8px; border: 1px solid #dbe1e8; border-left: 4px solid #22c55e; border-radius: 4px; background: #fff; }
.editor-check.failed { border-left-color: #ef4444; }
.editor-console { min-height: 115px; max-height: 210px; overflow: auto; padding: 10px 12px; border-radius: 4px; background: #0f172a; color: #cbd5e1; font: 11px/1.5 Consolas, Monaco, monospace; white-space: pre-wrap; }
.editor-access-label { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 10px; }
.editor-access-write { color: #166534; background: #dcfce7; }
.editor-access-read { color: #92400e; background: #fef3c7; }

/*************************************************************************
 * Full-Screen Modal
 *************************************************************************/
.code-editor-modal { padding: 0 !important; }
.code-editor-modal .modal-dialog { width: 100%; height: 100%; margin: 0; }
.code-editor-modal .modal-content { height: 100%; border: 0; border-radius: 0; display: flex; flex-direction: column; background: #0b1220; }
.code-editor-modal .modal-header, .code-editor-modal .modal-footer { flex: 0 0 auto; color: #e2e8f0; background: #111827; border-color: #263241; }
.code-editor-modal .modal-header .close { color: #fff; opacity: .8; }
.code-editor-modal .modal-body { flex: 1 1 auto; min-height: 0; padding: 0; background: #0b1220; }
.fullscreen-editor-textarea, .fullscreen-editor-textarea:focus { width: 100%; height: 100%; padding: 18px; border: 0 !important; outline: 0 !important; resize: none; background: #0b1220 !important; color: #e2e8f0 !important; font: 14px/1.58 Consolas, Monaco, 'Courier New', monospace; tab-size: 4; box-shadow: none !important; }
.fullscreen-editor-status { float: left; padding-top: 7px; color: #94a3b8; }

/*************************************************************************
 * Responsive Layout
 *************************************************************************/
@media (max-width: 1100px) {
	.editor-explorer { width: 260px; flex-basis: 260px; }
	.editor-info-grid { grid-template-columns: repeat(3, minmax(120px, 1fr)); }
}
@media (max-width: 800px) {
	.editor-workspace { height: auto; min-height: 720px; }
	.editor-activitybar { display: none; }
	.editor-explorer { width: 220px; flex-basis: 220px; }
	.editor-toolbar .input-group { max-width: none; float: none; margin-top: 8px; }
	.editor-info-grid { grid-template-columns: repeat(2, minmax(120px, 1fr)); }
}
@media (max-width: 600px) {
	.editor-workspace { display: block; height: auto; }
	.editor-explorer { width: 100%; max-height: 260px; border-right: 0; border-bottom: 1px solid #263241; }
	.editor-content { min-height: 520px; }
	.editor-info-grid { grid-template-columns: 1fr; }
}
</style>
<div class="container-fluid myaccount code-editor-page">
	<div class="page-header">
		<div class="row">
			<div class="col-sm-8">
				<h2 class="code-editor-title"><span class="glyphicon glyphicon-console"></span> Code Editor</h2>
				<p class="code-editor-subtitle">Review, validate and update approved User Manager source files.</p>
			</div>
			<div class="col-sm-4 text-right">
				<span class="label label-default">Role: <?php echo editor_h($acl->getRole()); ?></span>
				<span class="label label-<?php echo $canEdit ? 'success' : 'warning'; ?>"><?php echo $canEdit ? 'Edit enabled' : 'Read only'; ?></span>
			</div>
		</div>
	</div>
	<div class="alert alert-warning editor-security-alert"><strong><span class="glyphicon glyphicon-warning-sign"></span> Security:</strong> PHP is linted only and is never executed by this page. A backup is created before each save.</div>
	<div class="editor-shell">
		<div class="editor-toolbar">
			<div class="row">
				<div class="col-sm-8">
					<!--<button type="button" class="btn btn-primary btn-sm" id="evaluateCode" <?php echo !$canEvaluate ? 'disabled' : ''; ?>><span class="glyphicon glyphicon-ok-circle"></span> Evaluate</button>-->
					<button type="button" class="btn btn-default btn-sm" id="downloadCode" <?php echo !$canDownload ? 'disabled' : ''; ?>><span class="glyphicon glyphicon-download-alt"></span> Download</button>
					<button type="button" class="btn btn-default btn-sm" id="reloadCode"><span class="glyphicon glyphicon-refresh"></span> Reload</button>
					<button type="button" class="btn btn-default btn-sm" id="openFullscreenEditor"><span class="glyphicon glyphicon-fullscreen"></span> Full Screen</button>
				</div>
				<div class="col-sm-4">			
					<div class="input-group input-group-sm">
					<button type="button" class="btn btn-success" id="saveCode" <?php echo !$canEdit ? 'disabled' : ''; ?>><span class="glyphicon glyphicon-floppy-disk"></span> Save</button>
						<button type="button" class="btn btn-primary" id="editorNewFile">
							<span class="glyphicon glyphicon-file"></span>
							New File
						</button>
						<button
							type="button"
							class="btn btn-danger"
							id="editorDelete">
							<span class="glyphicon glyphicon-trash"></span>
							Delete
						</button>
					</div>
				</div>
			</div>
		</div>
		<div class="editor-workspace">
			<div class="editor-activitybar">
				<button type="button" class="editor-toggle-button" id="toggleExplorer" title="Toggle Explorer"><span class="glyphicon glyphicon-menu-hamburger"></span></button>			
				<button type="button" class="editor-activity-button active" title="Explorer"><span class="glyphicon glyphicon-duplicate"></span></button>
				<!--<button type="button" class="editor-activity-button" id="activityValidate" title="Validation"><span class="glyphicon glyphicon-ok-circle"></span></button>-->
				<button type="button" class="editor-activity-button" id="activityConsole" title="Activity"><span class="glyphicon glyphicon-list-alt"></span></button>
			</div>
			<div class="editor-explorer">
				<div class="editor-explorer-search">
					<div class="input-group input-group-sm">
						<span class="input-group-addon"><span class="glyphicon glyphicon-filter"></span></span>
						<input type="text" id="explorerFilter" class="form-control" placeholder="Filter explorer">
					</div>
				</div>
				<div class="editor-root-list" id="projectExplorer">
					<?php foreach ($editorRoots as $rootKey => $rootInfo) { ?>
						<div class="editor-root" data-root="<?php echo editor_h($rootKey); ?>">
							<div class="editor-root-title">
								<span class="glyphicon glyphicon-chevron-down editor-root-toggle"></span>
								<span class="editor-root-label"><?php echo editor_h($rootInfo['label']); ?></span>
								<span class="editor-root-count">0</span>
							</div>
							<ul class="editor-file-list"><li class="editor-file-loading">Loading...</li></ul>
						</div>
					<?php } ?>
				</div>
			</div>
			<div class="editor-content">
					<div class="editor-tabbar">
						<div class="editor-tab" id="editorTab">
						</div>

					</div>
				<div class="editor-breadcrumb" id="editorBreadcrumb"><span>Workspace</span><span class="separator">›</span><span>No file selected</span></div>
				<div class="editor-stage">
					<div class="editor-empty" id="editorEmpty"><div><span class="glyphicon glyphicon-console"></span><strong>Select a source file</strong><br>Choose an approved file from the Explorer to begin.</div></div>
					<textarea id="codeArea" class="editor-textarea" spellcheck="false" style="display:none;"></textarea>
				</div>
				<div class="editor-statusbar">
					<div class="editor-status-left"><span class="editor-status-item" id="editorState">Ready</span><span class="editor-status-item" id="editorAccess">No file</span></div>
					<div class="editor-status-right"><span class="editor-status-item" id="editorPosition">Ln 1, Col 1</span><span class="editor-status-item" id="editorEncoding">UTF-8</span><span class="editor-status-item" id="editorLanguage">Plain Text</span></div>
				</div>
			</div>
		</div>
		<div class="editor-bottom-panel">
			<ul class="nav nav-tabs" role="tablist">
				<li class="active"><a href="#inspectorPanel" data-toggle="tab"><span class="glyphicon glyphicon-info-sign"></span> Inspector</a></li>
				<!--<li><a href="#validationPanel" data-toggle="tab"><span class="glyphicon glyphicon-ok-circle"></span> Validation</a></li>-->
				<li><a href="#activityPanel" data-toggle="tab"><span class="glyphicon glyphicon-list-alt"></span> Activity</a></li>
			</ul>
			<div class="tab-content editor-bottom-content">
				<div class="tab-pane active" id="inspectorPanel">
					<div class="editor-info-grid">
						<div class="editor-info-card"><div class="editor-info-label">Name</div><div class="editor-info-value" id="metaName">No file selected</div></div>
						<div class="editor-info-card"><div class="editor-info-label">Path</div><div class="editor-info-value" id="metaPath">-</div></div>
						<div class="editor-info-card"><div class="editor-info-label">Language</div><div class="editor-info-value" id="metaLanguage">-</div></div>
						<div class="editor-info-card"><div class="editor-info-label">Size</div><div class="editor-info-value" id="metaSize">-</div></div>
						<div class="editor-info-card"><div class="editor-info-label">Modified</div><div class="editor-info-value" id="metaModified">-</div></div>
						<div class="editor-info-card"><div class="editor-info-label">Access</div><div class="editor-info-value" id="metaAccess">-</div></div>
					</div>
				</div>
				<!--<div class="tab-pane" id="validationPanel"><div id="validationResults" class="text-muted">Run Evaluate to review the current buffer.</div></div>-->
				<div class="tab-pane" id="activityPanel"><div class="editor-console" id="activityConsoleOutput">Code Editor ready.</div></div>
			</div>
		</div>
	</div>
</div>
<div class="modal fade code-editor-modal" id="fullscreenEditorModal" tabindex="-1" role="dialog" aria-labelledby="fullscreenEditorTitle">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title" id="fullscreenEditorTitle">Full-Screen Code Editor</h4></div>
			<div class="modal-body"><textarea id="fullscreenCodeArea" class="fullscreen-editor-textarea" spellcheck="false"></textarea></div>
			<div class="modal-footer"><span class="fullscreen-editor-status" id="fullscreenEditorStatus">No file selected</span><!--<button type="button" class="btn btn-primary" id="fullscreenEvaluate" <?php echo !$canEvaluate ? 'disabled' : ''; ?>>Evaluate</button>--><button type="button" class="btn btn-success" id="fullscreenSave" <?php echo !$canEdit ? 'disabled' : ''; ?>>Save</button><button type="button" class="btn btn-default" data-dismiss="modal">Close</button></div>
		</div>
	</div>
</div>
<script>
$(document).ready(function() {
	var csrf = <?php echo json_encode($csrf); ?>;
	var editorEndpoint = window.location.pathname;
	var currentFile = null;
	var originalCode = '';
	var searchTimer = null;
	/*************************************************************************
	 * Helpers
	 *************************************************************************/
	function escapeHtml(value) {
		return $('<div>').text(value === null || typeof value === 'undefined' ? '' : value).html();
	}
	function formatBytes(bytes) {
		bytes = parseInt(bytes, 10) || 0;
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
		return (bytes / 1048576).toFixed(1) + ' MB';
	}
	function log(message) {
		var $console = $('#activityConsoleOutput');
		$console.append('\n[' + new Date().toLocaleTimeString() + '] ' + message).scrollTop($console[0].scrollHeight);
	}
	function request(action, data) {
		data = data || {};
		data.editor_action = action;
		return $.ajax({ url: editorEndpoint, type: 'POST', dataType: 'json', data: data });
	}
	function requestError(xhr) {
		var response = xhr.responseJSON || {};
		return response.info || xhr.responseText || ('HTTP ' + xhr.status);
	}
	function iconFor(extension) {
		extension = (extension || '').toLowerCase();
		if ($.inArray(extension, ['php', 'phtml', 'inc']) !== -1) return { icon: 'glyphicon-file', css: 'file-icon-php' };
		if (extension === 'js') return { icon: 'glyphicon-flash', css: 'file-icon-js' };
		if (extension === 'css') return { icon: 'glyphicon-tint', css: 'file-icon-css' };
		if (extension === 'json') return { icon: 'glyphicon-cog', css: 'file-icon-json' };
		if ($.inArray(extension, ['html', 'htm']) !== -1) return { icon: 'glyphicon-globe', css: 'file-icon-html' };
		if ($.inArray(extension, ['sql', 'xml', 'yml', 'yaml']) !== -1) return { icon: 'glyphicon-list-alt', css: 'file-icon-data' };
		return { icon: 'glyphicon-file', css: 'file-icon-text' };
	}
	function updatePosition() {
		var area = $('#codeArea')[0];
		if (!area || !currentFile) return;
		var before = area.value.substring(0, area.selectionStart);
		var lines = before.split('\n');
		$('#editorPosition').text('Ln ' + lines.length + ', Col ' + (lines[lines.length - 1].length + 1));
	}
	/*************************************************************************
	 * Project Explorer
	 *************************************************************************/
	function loadRoot(rootKey, query) {
		var $root = $('.editor-root[data-root="' + rootKey + '"]');
		var $list = $root.find('.editor-file-list');
		$list.html('<li class="editor-file-loading">Loading...</li>');
		request('list', { root: rootKey, query: query || '' }).done(function(response) {
			$list.empty();
			if (response.available === false) {
				$root.hide();
				return;
			}
			$root.show().find('.editor-root-count').text(response.files.length);
			if (!response.files.length) {
				$list.html('<li class="editor-file-empty">No matching files.</li>');
				return;
			}
			$.each(response.files, function(index, file) {
				var icon = iconFor(file.extension);
				var $button = $('<button type="button" class="editor-file-item"></button>');
				$button.attr('data-root', file.root).attr('data-path', file.path).attr('title', file.path);
				$button.html('<span class="glyphicon ' + icon.icon + ' editor-file-icon ' + icon.css + '"></span><span class="editor-file-name">' + escapeHtml(file.path) + '</span><span class="editor-file-badge">' + escapeHtml(file.extension || 'txt') + '</span>');
				$list.append($('<li></li>').append($button));
			});
		}).fail(function(xhr) {
			$list.html('<li class="editor-file-error">' + escapeHtml(requestError(xhr)) + '</li>');
			log('Unable to load ' + rootKey + ': ' + requestError(xhr));
		});
	}
	/*************************************************************************
	 * Load All Explorer Roots
	 *************************************************************************/
	function loadAllRoots(query){
		request(
			'listall',
			{
			query : query || ''
			}
		)
		.done(function(response) {

			$.each(
			response.roots,
				function(rootKey, rootData) {

					renderRoot(
					rootKey,
						rootData
					);
				}
			);
		})
		.fail(function(xhr) {
			log(
				'Unable to load explorer: ' +
				requestError(xhr)
			);
	 });
	}
	/*************************************************************************
	 * Render Root
	 *************************************************************************/

	function renderRoot(rootKey, rootData)
	{
		var $root =
			$('.editor-root[data-root="' +
			rootKey +
			'"]');

		var $list =
			$root.find(
				'.editor-file-list'
			);

		$list.empty();

		$root.show();

		$root.find(
			'.editor-root-count'
		)
		.text(
			rootData.files.length
		);

		if (!rootData.files.length) {

			$list.html(
				'<li class="editor-file-empty">' +
				'No matching files.' +
				'</li>'
			);

			return;
		}

		$.each(
			rootData.files,
			function(index, file) {

				var icon =
					iconFor(
						file.extension
					);

				var $button =
					$(
						'<button type="button" class="editor-file-item"></button>'
					);

				$button
					.attr(
						'data-root',
						file.root
					)
					.attr(
						'data-path',
						file.path
					)
					.attr(
						'title',
						file.path
					);

				$button.html(
					'<span class="glyphicon ' +
					icon.icon +
					' editor-file-icon ' +
					icon.css +
					'"></span>' +

					'<span class="editor-file-name">' +
					escapeHtml(
						file.path
					) +
					'</span>' +

					'<span class="editor-file-badge">' +
					escapeHtml(
						file.extension || 'txt'
					) +
					'</span>'
				);

				$list.append(
					$('<li></li>')
						.append(
							$button
						)
				);

			}
		);
	}
	/*function loadAllRoots(query) {
		$('.editor-root').each(function() {
			loadRoot($(this).data('root'), query);
		});
	}*/
	function filterExplorer(value) {
		value = $.trim(value || '').toLowerCase();
		$('.editor-file-item').each(function() {
			$(this).closest('li').toggle(value === '' || String($(this).data('path')).toLowerCase().indexOf(value) !== -1);
		});
	}
	/*************************************************************************
	 * File Operations
	 *************************************************************************/
	function displayMeta(file) {
		var accessClass = file.writable && !file.protected ? 'editor-access-write' : 'editor-access-read';
		var accessLabel = file.protected ? 'Protected' : (file.writable ? 'Writable' : 'Read only');
		$('#metaName').text(file.name);
		$('#metaPath').text(file.root + '/' + file.path).attr('title', file.root + '/' + file.path);
		$('#metaLanguage').text(String(file.language || 'text').toUpperCase());
		$('#metaSize').text(formatBytes(file.size));
		$('#metaModified').text(file.modified);
		$('#metaAccess').html('<span class="editor-access-label ' + accessClass + '">' + escapeHtml(accessLabel) + '</span>');
		$('#editorAccess').text(accessLabel);
	}
	function selectFileItem(root, path) {
		$('.editor-file-item').removeClass('active').filter(function() {
			return $(this).data('root') === root && $(this).data('path') === path;
		}).addClass('active');
	}
	function openFile(root, path) {
		$('#editorState').text('Opening...');
		request('open', { root: root, path: path }).done(function(response) {
			var icon = iconFor(response.file.extension);
			currentFile = response.file;
			originalCode = response.content;
			$('#codeArea').val(response.content).show().focus();
			var textarea = $('#codeArea')[0];

			textarea.selectionStart = 0;
			textarea.selectionEnd   = 0;
			textarea.scrollTop      = 0;
			textarea.scrollLeft     = 0;			
			$('#editorEmpty').hide();
			$('#editorTab').removeClass('is-dirty').find('.editor-tab-icon').attr('class', 'glyphicon ' + icon.icon + ' editor-tab-icon ' + icon.css);
			$('#editorTab .editor-tab-name').text(response.file.name);
			$('#editorBreadcrumb').html('<span>' + escapeHtml(response.file.root) + '</span><span class="separator">›</span><span>' + escapeHtml(response.file.path) + '</span>');
			$('#editorLanguage').text(String(response.file.language || 'text').toUpperCase());
			$('#editorState').text('Ready');
			displayMeta(response.file);
			selectFileItem(root, path);
			updatePosition();
			if (!$('.editor-shell').hasClass('explorer-hidden')) {			 
				$('.editor-shell')
				.addClass('explorer-hidden');				 
				$('#toggleExplorer')
				.addClass('active');
			}		
			log('Opened ' + response.file.root + '/' + response.file.path);
		}).fail(function(xhr) {
			$('#editorState').text('Error');
			log('Open failed: ' + requestError(xhr));
		});
		
	}
	function renderEvaluation(evaluation) {
		var html = '<div class="alert alert-' + (evaluation.ok ? 'success' : 'danger') + '"><strong>' + (evaluation.ok ? 'Evaluation passed' : 'Evaluation needs attention') + '</strong><br>' + evaluation.lines + ' lines, ' + formatBytes(evaluation.bytes) + '</div>';
		$.each(evaluation.checks, function(index, check) {
			html += '<div class="editor-check ' + (check.ok ? '' : 'failed') + '"><strong>' + escapeHtml(check.name) + '</strong><br><span class="text-muted">' + escapeHtml(check.message) + '</span></div>';
		});
		$('#validationResults').html(html);
		$('a[href="#validationPanel"]').tab('show');
	}
	function evaluateCode() {
		if (!currentFile) {
			log('Select a file before evaluating.');
			return;
		}
		$('#editorState').text('Evaluating...');
		request('evaluate', { csrf: csrf, root: currentFile.root, path: currentFile.path, language: currentFile.language, code: $('#codeArea').val() }).done(function(response) {
			renderEvaluation(response.evaluation);
			$('#editorState').text(response.evaluation.ok ? 'Passed' : 'Review');
			log('Evaluation ' + (response.evaluation.ok ? 'passed' : 'reported issues') + ' for ' + currentFile.path);
		}).fail(function(xhr) {
			$('#editorState').text('Error');
			log('Evaluation failed: ' + requestError(xhr));
		});
	}
	function saveCode() {
		if (!currentFile) {
			log('Select a file before saving.');
			return;
		}
		if (!currentFile.writable || currentFile.protected) {
			log('The selected file is protected or read only.');
			return;
		}
		if (!window.confirm('Save changes to ' + currentFile.path + '? A backup will be created first.')) return;
		$('#editorState').text('Saving...');
		request('save', { csrf: csrf, root: currentFile.root, path: currentFile.path, code: $('#codeArea').val() }).done(function(response) {
			currentFile = response.file;
			originalCode = $('#codeArea').val();
			$('#editorTab').removeClass('is-dirty');
			$('#editorState').text('Saved');
			displayMeta(response.file);
			if (response.evaluation) renderEvaluation(response.evaluation);
			log(response.info + ' Backup: ' + response.backup);
		}).fail(function(xhr) {
			$('#editorState').text('Error');
			var response = xhr.responseJSON || {};
			if (response.evaluation) renderEvaluation(response.evaluation);
			log('Save failed: ' + requestError(xhr));
		});
	}

	/*************************************************************************
	 * New File
	 *************************************************************************/

	$('#editorNewFile').on('click', function() {

		var root = prompt(
			'Root (custom or workflows)',
			'custom'
		);

		if (!root) {
			return;
		}

		var path = prompt(
			'File path',
			'actions/newfile.php'
		);

		if (!path) {
			return;
		}

		request(
			'newfile',
			{
				root : root,
				path : path
			}
		)
		.done(function(response) {

			loadAllRoots();

			openFile(
				root,
				path
			);

		})
		.fail(function(xhr) {

			alert(
				requestError(xhr)
			);

		});

	});
	
/*************************************************************************
 * Delete File
 *************************************************************************/

$('#editorDelete').on('click', function() {

		if (!currentFile) {
			return;
		}

		if (!confirm('Delete "' +
			currentFile.path +
			'" ?'
		)) {
			return;
		}
		request(
			'delete',
			{
				r0ot : currentFile.root,
				path : currentFile.path
			}
		)
		.done(function(response) {

			log(
				response.info
			);

			editor.setValue(
				''
			);

			currentFile = null;

			loadAllRoots(
				$('#editorSearch')
					.val()
			);

		}).fail(function(xhr) {

			alert(requestError(xhr));

		});
	}
);
	/*************************************************************************
	 * Explorer Toggle
	 *************************************************************************/

	$('#toggleExplorer').on('click', function() {

		$('.editor-shell')
			.toggleClass('explorer-hidden');

		$(this).toggleClass('active');

		localStorage.setItem(
			'um-editor-explorer',
			$('.editor-shell').hasClass('explorer-hidden')
				? 'hidden'
				: 'visible'
		);
	});	

	/**************************************************************************
	 * Restore Explorer State
	 **************************************************************************/

	if (localStorage.getItem('um-editor-explorer') === 'hidden') {
		$('.editor-shell')
			.addClass('explorer-hidden');
		$('#toggleExplorer')
			.addClass('active');
	}
	
	/*************************************************************************
	 * Events
	 *************************************************************************/
	$('#projectExplorer').on('click', '.editor-file-item', function() {
		openFile($(this).data('root'), $(this).data('path'));
	});
	$('#projectExplorer').on('click', '.editor-root-title', function() {
		var $root = $(this).closest('.editor-root');
		var $list = $root.find('.editor-file-list');
		$list.slideToggle(120);
		$(this).find('.editor-root-toggle').toggleClass('glyphicon-chevron-down glyphicon-chevron-right');
	});
	//$('#evaluateCode, #fullscreenEvaluate').on('click', function() {
		//if ($('#fullscreenEditorModal').hasClass('in')) $('#codeArea').val($('#fullscreenCodeArea').val()).trigger('input');
		//evaluateCode();
	//});
	$('#saveCode, #fullscreenSave').on('click', function() {
		if ($('#fullscreenEditorModal').hasClass('in')) $('#codeArea').val($('#fullscreenCodeArea').val()).trigger('input');
		saveCode();
	});
	$('#reloadCode').on('click', function() {
		if (currentFile) openFile(currentFile.root, currentFile.path);
	});
	$('#downloadCode').on('click', function() {
		if (!currentFile) return;
		var $form = $('<form method="post" style="display:none;"></form>').attr('action', editorEndpoint);
		$form.append($('<input type="hidden" name="editor_action">').val('download'));
		$form.append($('<input type="hidden" name="csrf">').val(csrf));
		$form.append($('<input type="hidden" name="root">').val(currentFile.root));
		$form.append($('<input type="hidden" name="path">').val(currentFile.path));
		$('body').append($form);
		$form.submit();
		setTimeout(function() { $form.remove(); }, 1000);
	});
	$('#openFullscreenEditor').on('click', function() {
		if (!currentFile) {
			log('Select a file before opening full screen.');
			return;
		}
		$('#fullscreenCodeArea').val($('#codeArea').val());
		$('#fullscreenEditorTitle').text(currentFile.name);
		$('#fullscreenEditorStatus').text(currentFile.root + '/' + currentFile.path);
		$('#fullscreenEditorModal').modal({ backdrop: 'static', keyboard: true, show: true });
	});
	$('#fullscreenEditorModal').on('hide.bs.modal', function() {
		$('#codeArea').val($('#fullscreenCodeArea').val()).trigger('input');
	});
	$('#codeArea, #fullscreenCodeArea').on('keydown', function(event) {
		if (event.keyCode === 9) {
			event.preventDefault();
			var start = this.selectionStart;
			var end = this.selectionEnd;
			this.value = this.value.substring(0, start) + '\t' + this.value.substring(end);
			this.selectionStart = this.selectionEnd = start + 1;
			$(this).trigger('input');
		}
		if ((event.ctrlKey || event.metaKey) && event.keyCode === 83) {
			event.preventDefault();
			if (this.id === 'fullscreenCodeArea') $('#codeArea').val($(this).val()).trigger('input');
			saveCode();
		}
	});
	$('#codeArea').on('input', function() {
		var dirty = $(this).val() !== originalCode;
		$('#editorTab').toggleClass('is-dirty', dirty);
		$('#editorState').text(dirty ? 'Modified' : 'Ready');
	}).on('keyup click', updatePosition);
	$('#fileSearch').on('input', function() {
		clearTimeout(searchTimer);
		var query = $(this).val();
		$('#explorerFilter').val(query);
		searchTimer = setTimeout(function() { loadAllRoots(query); }, 300);
	});
	$('#explorerFilter').on('input', function() {
		filterExplorer($(this).val());
	});
	$('#clearFileSearch').on('click', function() {
		$('#fileSearch, #explorerFilter').val('');
		loadAllRoots('');
	});
	$('#activityValidate').on('click', function() {
		$('a[href="#validationPanel"]').tab('show');
	});
	$('#activityConsole').on('click', function() {
		$('a[href="#activityPanel"]').tab('show');
	});
	$(window).on('beforeunload', function() {
		if ($('#editorTab').hasClass('is-dirty')) return 'You have unsaved code changes.';
	});
	log('Available roots: ' + <?php echo json_encode(implode(', ', array_keys($editorRoots))); ?>);
	loadAllRoots('');
});
</script>
<?php include($_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'); ?>
