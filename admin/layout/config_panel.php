<?php
/*
|--------------------------------------------------------------------------
| UserManager Configuration Panel
|--------------------------------------------------------------------------
|
| Bootstrap 3 compatible configuration panel loader.
|
| This file is designed to be included from:
|
|     /admin/configuration.php
|
| Expected variable:
|
|     $name = current config type, example: ldap, security, email, pssmadmin
|
| Folder layout:
|
|     /admin/layout/config_panel.php
|     /admin/layout/partials/config_helpers.php
|     /admin/layout/partials/ldap.php
|     /admin/layout/partials/security.php
|     /admin/layout/partials/default.php
|     /admin/layout/partials/trumbowyg_assets.php
|     /admin/layout/partials/trumbowyg_init.php
|
| Notes:
| - DOCROOT is used for server-side includes.
| - WEBROOT is used for browser URLs.
| - Pages loaded through the router should not reload env.php.
| - This file keeps the existing updateconfig.php submit flow.
|
*/

if (!defined('DOCROOT')) {
    exit('Application environment not loaded.');
}

global $d, $db_database;

$name = isset($name) ? strtolower(trim($name)) : 'ldap';
$formType = isset($_GET['config']) ? $_GET['config'] : $name;
$partialsPath = DOCROOT . '/admin/layout/partials';

require_once $partialsPath . '/config_helpers.php';

$configs = _dbquery(
    'SELECT * FROM ' . $db_database . ".config WHERE type='" . addslashes($name) . "' ORDER BY formtype;",
    MYSQLI_ASSOC
);

if (!is_array($configs)) {
    $configs = array();
}

$hasWysiwyg = (stripos(json_encode($configs), 'wysiwyg') !== false);

if ($hasWysiwyg) {
    require $partialsPath . '/trumbowyg_assets.php';
}

$page_title = strtoupper($name);
$page_desc = '';

foreach ($configs as $config) {

	if (
		isset($config['name']) &&
		preg_match('/^_[a-z0-9]+_info$/i', $config['name'])
	) {
		$page_title = $config['title'];
		$page_desc = $config['desc'];

		continue;
	}
}

?>

<style>
.config-shell {
    margin-top: 15px;
    margin-bottom: 35px;
}

.config-page-header {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 18px 20px;
    margin-bottom: 20px;
}

.config-page-header h3 {
    margin-top: 0;
    margin-bottom: 5px;
    font-weight: 600;
}

.config-page-header .text-muted {
    margin-bottom: 0;
}

.config-panel {
    border-radius: 6px;
    border-color: #ddd;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
}

.config-panel .panel-heading {
    background: #f8f8f8;
    border-bottom: 1px solid #ddd;
}

.config-panel .panel-title {
    font-weight: 600;
}

.config-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 15px;
    min-height: 128px;
    transition: border-color .15s ease, box-shadow .15s ease;
}

.config-card:hover {
    border-color: #5bc0de;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
}

.config-label {
    font-weight: 600;
    margin-bottom: 7px;
}

.config-help {
    color: #777;
    font-size: 12px;
    margin-top: 7px;
    margin-bottom: 0;
}

.config-actions {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 15px;
    margin-top: 20px;
}

.config-actions .btn {
    min-width: 160px;
}

.config-tabs {
    margin-bottom: 18px;
}

.config-tabs > li > a {
    border-radius: 6px 6px 0 0;
}

.config-tabs > li.active > a,
.config-tabs > li.active > a:hover,
.config-tabs > li.active > a:focus {
    font-weight: 600;
}

.config-inline-remove {
    margin-left: 6px;
}

.config-field-changed {
    border-color: #f0ad4e !important;
    box-shadow: 0 0 5px rgba(240,173,78,.35);
}

.config-section-title {
    font-weight: 600;
    margin-top: 5px;
    margin-bottom: 15px;
    color: #333;
}

.config-empty {
    margin-bottom: 0;
}

.local-user-row {
    margin-bottom: 10px;
}

.local-user-row .input-group-addon {
    cursor: pointer;
}

</style>

<div class="container myaccount config-shell">

    <div class="config-page-header clearfix">
        <div class="pull-left">
            <h3><?php echo $page_title ?></h3>
            <p class="text-muted"><?php echo $page_desc ?></p>
        </div>
        <div class="pull-right hidden-xs">
            <!--<span class="label label-info">Bootstrap 3</span>-->
        </div>
    </div>

    <form name="<?php echo cfgpanel_e($name); ?>"
          action="<?php echo cfgpanel_e(WEBROOT); ?>admin/updateconfig.php"
          autocomplete="off"
          method="post"
          class="config-form">

        <?php
        switch ($name) {
            case 'ldap':
                require $partialsPath . '/ldap.php';
                break;

            case 'security':
                require $partialsPath . '/security.php';
				//require $partialsPath '/security_config_fields.php';

                break;

            default:
                require $partialsPath . '/default.php';
                break;
        }
        ?>

        <div class="config-actions clearfix">
            <div class="pull-left text-muted hidden-xs">
                <i class="fa fa-info-circle"></i>
                Review changes before saving.
            </div>

            <div class="pull-right">
                <button type="submit"
                        name="<?php echo cfgpanel_e($name); ?>config-submit"
                        class="btn btn-primary btn-lg"
                        value="Update">
                    <i class="fa fa-save"></i>
                    Save Changes
                </button>

                <input type="hidden"
                       name="formtype"
                       value="<?php echo cfgpanel_e($formType); ?>">
            </div>
        </div>

    </form>
</div>

<script>

</script>

<?php
if ($hasWysiwyg) {
    require $partialsPath . '/trumbowyg_init.php';
}
?>
