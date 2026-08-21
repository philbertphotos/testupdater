<?php
/*
|--------------------------------------------------------------------------
| LDAP Configuration Partial
|--------------------------------------------------------------------------
|
| Renders LDAP domain tabs and LDAP array-based configuration values.
|
*/

$account_suffix = json_decode(_get_setting('account_suffix'), true);

if (!is_array($account_suffix) || empty($account_suffix)) {
    $account_suffix = array('primary');
}
?>

<div class="panel panel-default config-panel">
    <div class="panel-heading clearfix">
        <h4 class="panel-title pull-left">
            <i class="fa fa-sitemap"></i>
            LDAP Domains
        </h4>
        <span class="pull-right text-muted hidden-xs">Manage domain connection settings</span>
    </div>

    <div class="panel-body">
        <ul id="LdapTab" class="nav nav-tabs config-tabs" role="tablist" data-tabs="tabs">
            <?php
            $inx = 0;
            foreach ($account_suffix as $suffix) {
                $suffix = ltrim(strtolower($suffix), '@');
                $tabId = str_replace('.', '', $suffix);
                $activeClass = ($inx === 0) ? 'active' : '';
                ?>
                <li id="li<?php echo (int) $inx; ?>" class="<?php echo cfgpanel_e($activeClass); ?>">
                    <a href="#<?php echo cfgpanel_e($tabId); ?>" data-toggle="tab" role="tab">
                        <?php echo cfgpanel_e($suffix); ?>
                        <?php if ($inx >= 1) { ?>
                            <button onclick="removeTab(event);"
                                    class="btn btn-warning btn-xs config-inline-remove"
                                    type="button">
                                <span class="fa fa-times"></span>
                            </button>
                        <?php } ?>
                    </a>
                </li>
                <?php
                $inx++;
            }
            ?>
            <li id="last">
                <a href="#addTab" data-toggle="tab" role="tab">
                    <i class="fa fa-plus"></i>
                    Add Domain
                </a>
            </li>
        </ul>

        <div id="my-tab-content" class="tab-content">
            <?php
            $int = 0;
            foreach ($account_suffix as $suffix) {
                $suffix = ltrim(strtolower($suffix), '@');
                $dc = str_replace('.', '', $suffix);
                $activePane = ($int === 0) ? ' active' : '';
                ?>

                <div class="tab-pane<?php echo cfgpanel_e($activePane); ?>" id="<?php echo cfgpanel_e($dc); ?>">
                    <h4 class="config-section-title"><?php echo cfgpanel_e($suffix); ?> Settings</h4>

                    <div class="row">
                        <?php
                        foreach ($configs as $config) {
                            $value = json_decode($config['value'], true);

                            if (!is_array($value)) {
                                $value = array();
                            }

                            $fieldValue = cfgpanel_array_value($value, $int, '');
                            $fieldBaseId = cfgpanel_field_id($config['name'], $int);

                            switch ($config['formtype']) {
                                case 'checkbox':
                                    cfgpanel_render_checkbox(
                                        $config,
                                        $fieldValue,
                                        $config['name'] . '[]',
                                        $fieldBaseId,
                                        'col-md-4 col-sm-6'
                                    );
                                    break;

                                case 'textbox':
                                    cfgpanel_render_textarea(
                                        $config,
                                        $fieldValue,
                                        $config['name'] . '[]',
                                        $fieldBaseId,
                                        'col-md-6 col-sm-12'
                                    );
                                    break;

                                default:
                                    cfgpanel_render_input(
                                        $config,
                                        $fieldValue,
                                        $config['name'] . '[]',
                                        $fieldBaseId,
                                        'col-md-6 col-sm-12'
                                    );
                                    break;
                            }
                        }
                        ?>
                    </div>
                </div>

                <?php
                $int++;
            }
            ?>

            <div class="tab-pane" id="addTab">
                <div class="alert alert-info config-empty">
                    <strong>Add Domain</strong><br>
                    Use the existing add-domain JavaScript workflow if available. This tab is kept for compatibility with the current LDAP interface.
                </div>
            </div>
        </div>
    </div>
</div>
