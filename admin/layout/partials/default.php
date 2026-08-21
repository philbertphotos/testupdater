<?php
/*
|--------------------------------------------------------------------------
| Default Configuration Partial
|--------------------------------------------------------------------------
|
| Renders normal configuration groups returned by _cfg_panel($name).
|
*/

$panelGroups = _cfg_panel($name);

if (!is_array($panelGroups) || empty($panelGroups)) {
    ?>
    <div class="alert alert-warning">
        No configuration fields were found for <strong><?php echo cfgpanel_e($name); ?></strong>.
    </div>
    <?php
    return;
}

	foreach ($panelGroups as $key => $configs) {
		// Hide internal  info records unless debug mode enabled
		if ($key == 'info' && (!defined('DEBUG') || !DEBUG)) {
			continue;
		}
    if (!is_array($configs) || empty($configs)) {
        continue;
    }
    ?>

    <div class="panel panel-default config-panel">
        <div class="panel-heading">
            <h4 class="panel-title">
                <i class="fa fa-sliders"></i>
                <?php echo cfgpanel_e(ucfirst($key)); ?> Settings
            </h4>
        </div>

        <div class="panel-body">
            <div class="row">
                <?php
				foreach ($configs as $config) {

					// Hide internal type info records unless debug mode enabled
					if (preg_match('/^_[a-z0-9]+_info$/i', $config['name'])	&& (!defined('DEBUG') || !DEBUG)) {
						continue;
					}

					$fieldName = $config['name'];
					$fieldId = cfgpanel_field_id($config['name']);
					$fieldValue = cfgpanel_config_value($config);

					switch ($key) {
                        case 'checkbox':
                            cfgpanel_render_checkbox(
                                $config,
                                $fieldValue,
                                $fieldName,
                                $fieldId,
                                'col-md-4 col-sm-6'
                            );
                            break;

                        case 'textbox':
                            cfgpanel_render_textarea(
                                $config,
                                $fieldValue,
                                $fieldName,
                                $fieldId,
                                'col-md-6 col-sm-12'
                            );
                            break;

                        case 'select':
                            cfgpanel_render_select(
                                $config,
                                $fieldValue,
                                $fieldName,
                                $fieldId,
                                'col-md-6 col-sm-12'
                            );
                            break;

                        case 'wysiwyg':
                            cfgpanel_render_wysiwyg(
                                $config,
                                $fieldValue,
                                $fieldName,
                                $fieldId
                            );
                            break;

                        case 'input':
                        default:
                            cfgpanel_render_input(
                                $config,
                                $fieldValue,
                                $fieldName,
                                $fieldId,
                                'col-md-6 col-sm-12'
                            );
                            break;
                    }
                }
                ?>
            </div>
        </div>
    </div>

    <?php
}
