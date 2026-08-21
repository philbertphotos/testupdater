<?php
/**
 * User Manager 3.0 / 4.0 - Refactored Admin Dashboard
 * File: dashboard.php
 * Purpose: Compact command-center dashboard using existing ACL-style role checks.
 *
 * Security considerations:
 * - Dashboard links are visibility/navigation controls only.
 * - Destination pages must retain their own page-level ACL checks.
 * - All dynamic output is escaped before rendering.
 * - Custom modules are filtered through the existing custom module security loader when available.
 * - Core updater status is read from update-status.json only. This page does not execute update actions.
 *
 * UX changes:
 * - Replaces stacked dashboard sections with a compact health ribbon, attention panel,
 *   admin tools, applications, and operations.
 * - Reduces card height and removes large vertical gaps.
 * - Uses left-aligned compact cards for faster scanning.
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/env.php');
include(DOCROOT . '/header.php');

/*************************************************************************
 * Authorization Setup
 *************************************************************************/

$isEditor = $acl->isAllowed($acl->getRole(), 'Editors');
$isManager = $acl->isAllowed($acl->getRole(), 'Managers');
$isAdmin = $acl->isAllowed($acl->getRole(), 'Administrators');

/*
 * Dashboard access controls.
 * These mirror the Admin Menu security principles.
 * The dashboard should not show links the user should not be able to open.
 * Page-level ACL checks still need to remain on destination pages.
 */
$canUseAdminMenu = ($isEditor || $isManager || $isAdmin);
$canViewDashboard = $canUseAdminMenu;
$canViewUsers = $canUseAdminMenu;
$canViewDirectoryAccess = ($isManager || $isAdmin);
$canViewMonitoring = ($isManager || $isAdmin);
$canViewSessions = ($isManager || $isAdmin);
$canViewLogs = ($isManager || $isAdmin);
$canViewEvents = ($isManager || $isAdmin);
$canViewConfiguration = ($isManager || $isAdmin);
$canViewSecurity = ($isManager || $isAdmin);
$canViewLDAP = $isAdmin;
$canViewCustomModules = $canUseAdminMenu;
$canViewStatusPage = ($isManager || $isAdmin);
$canViewUpdater = ($isManager || $isAdmin);

if (!$canViewDashboard) {
    $m->requirePageAccess('Managers');
}

/*************************************************************************
 * Utility Functions
 *************************************************************************/

if (!function_exists('dashboardH')) {
    function dashboardH($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function sessionVal($row,$field,$default = '')
{
	if (is_object($row) && isset($row->{$field})) {
		return $row->{$field};
	}

	if (is_array($row) && isset($row[$field])) {
		return $row[$field];
	}

	return $default;
}

if (!function_exists('dashboardSafeCount')) {
    function dashboardSafeCount($sql)
    {
        global $d;

        try {
            $value = $d->field($sql);
            return is_numeric($value) ? (int)$value : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('dashboardSafeRows')) {
    function dashboardSafeRows($sql)
    {
        global $d;

        try {
            $rows = $d->rows($sql);
            return is_array($rows) ? $rows : array();
        } catch (Throwable $e) {
            return array();
        }
    }
}

/*************************************************************************
 * Custom Module Loader
 *************************************************************************/

if (!function_exists('dashboardCustomModules')) {
    function dashboardCustomModules()
    {
        $availableCustomModules = array();

        if (
            defined('DOCROOT') &&
            file_exists(DOCROOT . '/admin/custom/includes/security.php') &&
            file_exists(DOCROOT . '/admin/custom/config/modules.php')
        ) {
            require_once DOCROOT . '/admin/custom/includes/security.php';
            $customModules = require DOCROOT . '/admin/custom/config/modules.php';
            $customModules = is_array($customModules) ? $customModules : array();

            usort($customModules, function($a, $b) {
                $aSort = isset($a['sortorder']) ? (int)$a['sortorder'] : 999;
                $bSort = isset($b['sortorder']) ? (int)$b['sortorder'] : 999;
                return $aSort - $bSort;
            });

            foreach ($customModules as $module) {
                if (empty($module['active'])) {
                    continue;
                }

                if (!function_exists('customCanAccess')) {
                    continue;
                }

                if (
                    empty($module['resource']) ||
                    empty($module['permission']) ||
                    !customCanAccess($module['resource'], $module['permission'])
                ) {
                    continue;
                }

                if (empty($module['url']) || empty($module['title'])) {
                    continue;
                }

                $availableCustomModules[] = $module;
            }
        }

        return $availableCustomModules;
    }
}

/*************************************************************************
 * Core Updater Snapshot
 *************************************************************************/

if (!function_exists('dashboardUpdaterStatus')) {
    function dashboardUpdaterStatus()
    {
        $statusFile = DOCROOT . DIRECTORY_SEPARATOR . 'update-status.json';
        $default = array('status' => 'idle', 'outdated_files' => 0, 'new_files' => 0, 'updated_files' => 0, 'current_files' => 0, 'last_check' => '', 'message' => 'Not checked yet');

        if (!file_exists($statusFile)) {
            return $default;
        }

        $json = json_decode((string)file_get_contents($statusFile), true);

        if (!is_array($json)) {
            $default['status'] = 'error';
            $default['message'] = 'Updater status file could not be read.';
            return $default;
        }

        return array_merge($default, $json);
    }
}

/*************************************************************************
 * Render Functions
 *************************************************************************/

if (!function_exists('dashboardHealthPill')) {
    function dashboardHealthPill($title, $value, $description, $icon, $url, $state)
    {
        ?>
        <div class="col-xs-12 col-sm-6 col-md-3">
            <a href="<?php echo dashboardH($url); ?>" class="dashboard-health-pill dashboard-state-<?php echo dashboardH($state); ?>">
                <i class="fa <?php echo dashboardH($icon); ?>"></i>
                <strong><?php echo dashboardH($title); ?></strong>
                <span class="dashboard-health-value"><?php echo dashboardH($value); ?></span>
                <span class="dashboard-health-desc"><?php echo dashboardH($description); ?></span>
            </a>
        </div>
        <?php
    }
}

if (!function_exists('dashboardAttentionItem')) {
    function dashboardAttentionItem($title, $description, $icon, $url, $state)
    {
        ?>
        <a href="<?php echo dashboardH($url); ?>" class="dashboard-attention-item dashboard-attention-<?php echo dashboardH($state); ?>">
            <i class="fa <?php echo dashboardH($icon); ?>"></i>
            <strong><?php echo dashboardH($title); ?></strong>
            <span><?php echo dashboardH($description); ?></span>
        </a>
        <?php
    }
}

if (!function_exists('dashboardToolCard')) {
    function dashboardToolCard($card)
    {
        $title = isset($card['title']) ? $card['title'] : '';
        $description = isset($card['description']) ? $card['description'] : '';
        $icon = isset($card['icon']) ? $card['icon'] : 'fa-square-o';
        $url = isset($card['url']) ? $card['url'] : '#';
        ?>
        <div class="col-xs-12 col-sm-6 col-md-3">
            <a href="<?php echo dashboardH($url); ?>" class="dashboard-tool-card">
                <i class="fa <?php echo dashboardH($icon); ?>"></i>
                <strong><?php echo dashboardH($title); ?></strong>
                <span><?php echo dashboardH($description); ?></span>
            </a>
        </div>
        <?php
    }
}

if (!function_exists('dashboardToolSection')) {
    function dashboardToolSection($title, $subtitle, $cards)
    {
        if (empty($cards)) {
            return;
        }
        ?>
        <div class="dashboard-command-section">
            <div class="dashboard-section-header">
                <h2><?php echo dashboardH($title); ?></h2>
                <?php if ($subtitle !== '') { ?>
                    <p><?php echo dashboardH($subtitle); ?></p>
                <?php } ?>
            </div>
            <div class="row dashboard-card-row">
                <?php foreach ($cards as $card) { dashboardToolCard($card); } ?>
            </div>
        </div>
        <?php
    }
}

function dashboardSessionStatistics()
{
    global $d;

    $sessions = $d->rows(
        'SELECT *
         FROM sessions
         ORDER BY status DESC,lastactivity DESC,username ASC;'
    );

    if (!is_array($sessions)) {
        $sessions = array();
    }

    $stats = array(
        'total'    => 0,
        'online'   => 0,
        'idle'     => 0,
        'locked'   => 0,
        'expired'  => 0,
        'inactive' => 0
    );

    foreach ($sessions as $sessionRow) {

        $sessionStatus = getSessionStatus($sessionRow);

        $stats['total']++;

        if (isset($stats[$sessionStatus['key']])) {
            $stats[$sessionStatus['key']]++;
        }
    }

    return $stats;
}

function getSessionStatus($row)
{
	$expire = (int)sessionVal($row,'expire',0);
	$status = (int)sessionVal($row,'status',0);
	$flag = (int)sessionVal($row,'flag',0);
	$lastactivity = (int)sessionVal($row,'lastactivity',0);
	$onlinewindow = time() - 300;

	if ($flag == 1 && $status == 0) {

		return array(
			'label' => 'Locked',
			'class' => 'danger',
			'icon' => 'glyphicon-lock',
			'key' => 'locked'
		);
	}

	if ($expire > 0 && $expire <= time()) {

		return array(
			'label' => 'Expired',
			'class' => 'default',
			'icon' => 'glyphicon-time',
			'key' => 'expired'
		);
	}

	if ($status == 1 && $lastactivity >= $onlinewindow) {

		return array(
			'label' => 'Online',
			'class' => 'success',
			'icon' => 'glyphicon-ok-circle',
			'key' => 'online'
		);
	}

	if ($status == 1) {

		return array(
			'label' => 'Idle',
			'class' => 'warning',
			'icon' => 'glyphicon-pause',
			'key' => 'idle'
		);
	}

	return array(
		'label' => 'Inactive',
		'class' => 'default',
		'icon' => 'glyphicon-minus-sign',
		'key' => 'inactive'
	);
}
/*************************************************************************
 * Session Statistics
 * Use the same logic as admin/online.
 *************************************************************************/

$sessionStats = dashboardSessionStatistics();

$activeSessions = $sessionStats['online'];
$idleSessions = $sessionStats['idle'];
$lockedSessions = $sessionStats['locked'];
$expiredSessions = $sessionStats['expired'];

$sessionsNeedReview = (
    $lockedSessions > 0 ||
    $expiredSessions > 0
);

/*************************************************************************
 * System Health Data
 *************************************************************************/

$errorLogs = dashboardSafeCount("SELECT COUNT(*) FROM syslog WHERE log_type = 'error' AND created >= NOW() - INTERVAL 24 HOUR");
$events = dashboardSafeRows('SELECT id,title,name,nextrun,state,debug FROM events ORDER BY state DESC,title ASC');
$dueEvents = 0;
$enabledEvents = 0;
$disabledEvents = 0;
$debugEvents = 0;
$dueSoonEvents = 0;
$now = time();

foreach ($events as $event) {
    $state = isset($event->state) ? (int)$event->state : 0;
    $nextrun = isset($event->nextrun) ? (int)$event->nextrun : 0;
    $debug = isset($event->debug) ? (int)$event->debug : 0;
    $isActive = ($state === 1);

    if ($state) {
        $enabledEvents++;
    }    
	
	if (!$isActive) {
        $disabledEvents++;
    }

    if ($isActive && $nextrun > 0 && $nextrun <= $now) {
        $dueEvents++;
    }

    if ($isActive && $nextrun > $now && ($nextrun - $now) <= 3600) {
        $dueSoonEvents++;
    }

    if ($debug === 1) {
        $debugEvents++;
    }
}

$jobsNeedReview = ($dueEvents + $dueSoonEvents + $disabledEvents + $debugEvents) > 0;
$logsNeedReview = $errorLogs > 0;
$updaterStatus = dashboardUpdaterStatus();
$updaterStatusName = (string)$updaterStatus['status'];
$updaterUpdates = isset($updaterStatus['outdated_files']) ? (int)$updaterStatus['outdated_files'] : 0;
$updaterNewFiles = isset($updaterStatus['new_files']) ? (int)$updaterStatus['new_files'] : 0;
$updaterUpdatedFiles = isset($updaterStatus['updated_files']) ? (int)$updaterStatus['updated_files'] : 0;
$updaterNeedsReview = ($updaterUpdates > 0 || $updaterStatusName === 'updates_available' || $updaterStatusName === 'error');

/*************************************************************************
 * Dashboard Links
 *************************************************************************/

$adminToolCards = array();
$systemCards = array();
$customModuleCards = array();

if ($canViewUsers) {
    $adminToolCards[] = array('title' => 'Search Users', 'description' => 'User lookup', 'icon' => 'fa-search', 'url' => WEBROOT . 'admin/searchusers');
}

if ($canViewSessions) {
    $adminToolCards[] = array('title' => 'Sessions', 'description' => 'Online users', 'icon' => 'fa-globe', 'url' => WEBROOT . 'admin/online');
}

if ($canViewEvents) {
    $adminToolCards[] = array('title' => 'Scheduler', 'description' => 'Event jobs', 'icon' => 'fa-calendar', 'url' => WEBROOT . 'admin/events');
}

if ($canViewLogs) {
    $adminToolCards[] = array('title' => 'Logs', 'description' => 'Audit logs', 'icon' => 'fa-file-text-o', 'url' => WEBROOT . 'admin/logs');
}

if ($canViewStatusPage) {
    $adminToolCards[] = array('title' => 'Status Hub', 'description' => 'System view', 'icon' => 'fa-dashboard', 'url' => WEBROOT . 'admin/status');
}

if ($canViewDirectoryAccess) {
    $systemCards[] = array('title' => 'Attributes', 'description' => 'Profile fields', 'icon' => 'fa-list-alt', 'url' => WEBROOT . 'admin/attributes');
    $systemCards[] = array('title' => 'Permissions', 'description' => 'ACL access', 'icon' => 'fa-user-secret', 'url' => WEBROOT . 'admin/permissions');
}

if ($canViewLDAP) {
    $systemCards[] = array('title' => 'LDAP Settings', 'description' => 'Directory config', 'icon' => 'fa-database', 'url' => WEBROOT . 'admin/configuration?config=ldap');
}

if ($canViewConfiguration) {
    $systemCards[] = array('title' => 'Global Settings', 'description' => 'Application config', 'icon' => 'fa-cog', 'url' => WEBROOT . 'admin/configuration?config=pssmadmin');

    if ($canViewSecurity) {
        $systemCards[] = array('title' => 'Security Settings', 'description' => 'Security controls', 'icon' => 'fa-lock', 'url' => WEBROOT . 'admin/configuration?config=security');
    }

    $systemCards[] = array('title' => 'Self Service', 'description' => 'User settings', 'icon' => 'fa-user-circle', 'url' => WEBROOT . 'admin/configuration?config=selfservice');
    $systemCards[] = array('title' => 'Email Settings', 'description' => 'Mail config', 'icon' => 'fa-envelope', 'url' => WEBROOT . 'admin/configuration?config=email');
}

if ($canViewUpdater) {
    $systemCards[] = array('title' => 'System Update', 'description' => 'For system file updates', 'icon' => 'fa-refresh', 'url' => WEBROOT . 'admin/core-updater');
}
if ($canViewUpdater) {
    $systemCards[] = array('title' => 'Workflows', 'description' => 'Create Workflow Tasks', 'icon' => 'fa-tasks', 'url' => WEBROOT . 'admin/workflows');
}
if ($canViewUpdater) {
    $systemCards[] = array('title' => 'Code Editor', 'description' => 'Simple Editor for PHP/TXT files', 'icon' => 'fa-edit', 'url' => WEBROOT . 'admin/editor');
}

if ($canViewCustomModules) {
    $availableCustomModules = dashboardCustomModules();

    foreach ($availableCustomModules as $module) {
        $customModuleCards[] = array(
            'title' => $module['title'],
            'description' => !empty($module['description']) ? $module['description'] : 'Application',
            'icon' => !empty($module['icon']) ? $module['icon'] : 'fa-puzzle-piece',
            'url' => $module['url']
        );
    }
}

?>

<style>
/*************************************************************************
 * Dashboard Layout
 *************************************************************************/
.dashboard-command-page {
    margin-top: 12px;
}

.dashboard-command-header {
    margin-bottom: 12px;
    text-align: center;
}

.dashboard-command-header h1 {
    margin: 0;
    font-size: 26px;
    font-weight: 600;
    color: #444;
}

.dashboard-command-header p {
    margin: 4px 0 0 0;
    color: #777;
    font-size: 12px;
}

.dashboard-command-section {
    margin-bottom: 18px;
}

.dashboard-section-header {
    margin-bottom: 8px;
    border-bottom: 1px solid #eeeeee;
}

.dashboard-section-header h2 {
    margin: 0 0 6px 0;
    font-size: 16px;
    font-weight: 700;
    color: #444;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.dashboard-section-header p {
    margin: -3px 0 7px 0;
    color: #777;
    font-size: 12px;
}

.dashboard-card-row {
    margin-left: -7px;
    margin-right: -7px;
}

.dashboard-card-row > div {
    padding-left: 7px;
    padding-right: 7px;
}

/*************************************************************************
 * Health Ribbon
 *************************************************************************/
.dashboard-health-ribbon {
    margin-bottom: 14px;
}

.dashboard-health-pill {
    display: block;
    min-height: 70px;
    padding: 11px 12px 10px 48px;
    margin-bottom: 10px;
    position: relative;
    color: #333;
    background: #fff;
    border: 1px solid #ddd;
    border-left: 4px solid #337ab7;
    border-radius: 6px;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
    transition: all .15s ease-in-out;
}

.dashboard-health-pill:hover,
.dashboard-health-pill:focus,
.dashboard-tool-card:hover,
.dashboard-tool-card:focus,
.dashboard-attention-item:hover,
.dashboard-attention-item:focus {
    color: #23527c;
    border-color: #337ab7;
    box-shadow: 0 3px 10px rgba(51,122,183,.14);
    text-decoration: none;
    transform: translateY(-1px);
    outline: none;
}

.dashboard-health-pill i {
    position: absolute;
    top: 18px;
    left: 14px;
    font-size: 22px;
}

.dashboard-health-pill strong {
    display: block;
    font-size: 14px;
    line-height: 1.2;
    color: #1f1f1f;
}

.dashboard-health-value {
    display: block;
    margin-top: 2px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
}

.dashboard-health-desc {
    display: block;
    color: #777;
    font-size: 12px;
    margin-top: 1px;
}

.dashboard-state-success {
    border-left-color: #3c763d;
}

.dashboard-state-success i,
.dashboard-state-success .dashboard-health-value {
    color: #3c763d;
}

.dashboard-state-warning {
    border-left-color: #8a6d3b;
}

.dashboard-state-warning i,
.dashboard-state-warning .dashboard-health-value {
    color: #8a6d3b;
}

.dashboard-state-danger {
    border-left-color: #a94442;
}

.dashboard-state-danger i,
.dashboard-state-danger .dashboard-health-value {
    color: #a94442;
}

/*************************************************************************
 * Attention Panel
 *************************************************************************/
.dashboard-attention-panel {
    margin-bottom: 18px;
}

.dashboard-attention-list {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
}

.dashboard-attention-item {
    display: block;
    padding: 10px 12px 10px 42px;
    border-bottom: 1px solid #eeeeee;
    position: relative;
    color: #333;
    background: #fff;
}

.dashboard-attention-item:last-child {
    border-bottom: 0;
}

.dashboard-attention-item i {
    position: absolute;
    top: 12px;
    left: 14px;
    font-size: 17px;
}

.dashboard-attention-item strong {
    display: block;
    font-size: 13px;
    line-height: 1.2;
}

.dashboard-attention-item span {
    display: block;
    margin-top: 2px;
    color: #777;
    font-size: 12px;
}

.dashboard-attention-warning i {
    color: #8a6d3b;
}

.dashboard-attention-danger i {
    color: #a94442;
}

.dashboard-no-attention {
    padding: 11px 13px;
    color: #3c763d;
    background: #fff;
    border: 1px solid #d6e9c6;
    border-radius: 6px;
    font-size: 13px;
}

.dashboard-no-attention i {
    margin-right: 6px;
}

/*************************************************************************
 * Tool Cards
 *************************************************************************/
.dashboard-tool-card {
    display: block;
    min-height: 72px;
    padding: 12px 12px 10px 48px;
    margin-bottom: 10px;
    position: relative;
    color: #333;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
    transition: all .15s ease-in-out;
}

.dashboard-tool-card i {
    position: absolute;
    top: 18px;
    left: 14px;
    color: #337ab7;
    font-size: 22px;
}

.dashboard-tool-card strong {
    display: block;
    font-size: 14px;
    line-height: 1.2;
    color: #1f1f1f;
}

.dashboard-tool-card span {
    display: block;
    color: #777;
    font-size: 12px;
    margin-top: 3px;
}

.dashboard-empty-state {
    padding: 14px;
    text-align: center;
    color: #777;
    background: #fff;
    border: 1px dashed #ccc;
    border-radius: 6px;
    margin-bottom: 10px;
}

/*************************************************************************
 * Responsive Adjustments
 *************************************************************************/
@media (max-width: 767px) {
    .dashboard-command-header h1 {
        font-size: 22px;
    }

    .dashboard-health-pill,
    .dashboard-tool-card {
        min-height: 64px;
    }
}
</style>

<div class="container myaccount dashboard-command-page">
    <div class="dashboard-command-header">
        <h1>Admin Dashboard</h1>
    </div>

    <div class="dashboard-health-ribbon">
        <div class="row dashboard-card-row">
            <?php if ($canViewSessions) {
                 dashboardHealthPill('Sessions', $sessionsNeedReview ? 'Review' : 'Healthy', $activeSessions . ' online, ' . $idleSessions . ' idle', 'fa-globe', WEBROOT . 'admin/online', $sessionsNeedReview ? 'warning' : 'success'); 
            } 
           if ($canViewEvents) {
                 dashboardHealthPill('Scheduled Jobs', $jobsNeedReview ? 'Review' : 'Healthy', $enabledEvents. ' enabled, ' . $dueEvents . ' due, ' . $disabledEvents . ' disabled', 'fa-calendar', WEBROOT . 'admin/events', $jobsNeedReview ? 'warning' : 'success'); 
             }
             if ($canViewLogs) {
                 dashboardHealthPill('System Logs', $logsNeedReview ? 'Review' : 'Healthy', $errorLogs . ' errors', 'fa-file-text-o', WEBROOT . 'admin/logs', $logsNeedReview ? 'danger' : 'success'); 
             } 
             if ($canViewUpdater) {
                 dashboardHealthPill('Core Updates', $updaterNeedsReview ? 'Review' : 'Current', $updaterNewFiles . ' new, ' . $updaterUpdatedFiles . ' updated', 'fa-refresh', WEBROOT . 'admin/core-updater', $updaterStatusName === 'error' ? 'danger' : ($updaterNeedsReview ? 'warning' : 'success'));
             } ?>
        </div>
    </div>


    <?php dashboardToolSection('System Tools', '', $adminToolCards); ?>

    <div class="dashboard-command-section">
        <div class="dashboard-section-header">
            <h2>Applications</h2>
            <p></p>
        </div>
        <?php if (!empty($customModuleCards)) { ?>
            <div class="row dashboard-card-row">
                <?php foreach ($customModuleCards as $card) { dashboardToolCard($card); } ?>
            </div>
        <?php } else { ?>
            <div class="dashboard-empty-state">
                No custom applications are available for this account.
            </div>
        <?php } ?>
    </div>

    <?php dashboardToolSection('System Configuration', 'Configuration and access management.', $systemCards); ?>
</div>

<?php
if (defined('DOCROOT') && file_exists(DOCROOT . '/templates/footer.php')) {
    include(DOCROOT . '/templates/footer.php');
} else {
    include(DOCROOT . '/footer.php');
}
?>
