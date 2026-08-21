<?php
/*
|--------------------------------------------------------------------------
| Config Panel Helper Functions
|--------------------------------------------------------------------------
|
| Shared Bootstrap 3 rendering helpers used by the configuration partials.
|
*/

/*************************************************************************
 * Config Visibility Functions
 *************************************************************************/

if (!function_exists('cfgpanel_is_internal')) {
	function cfgpanel_is_internal($config)
	{
		if (empty($config['name'])) {
			return false;
		}

		return preg_match('/^_[a-z0-9]+_info$/i', $config['name']);
	}
}

if (!function_exists('cfgpanel_e')) {
    function cfgpanel_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('cfgpanel_array_value')) {
    function cfgpanel_array_value($array, $index, $default = '')
    {
        if (is_array($array) && array_key_exists($index, $array)) {
            return $array[$index];
        }

        return $default;
    }
}

if (!function_exists('cfgpanel_config_value')) {
    function cfgpanel_config_value($config, $default = '')
    {
        return isset($config['value']) ? $config['value'] : $default;
    }
}

if (!function_exists('cfgpanel_validation')) {
    function cfgpanel_validation($config)
    {
        return isset($config['validation']) ? trim($config['validation']) : '';
    }
}

if (!function_exists('cfgpanel_is_readonly')) {
    function cfgpanel_is_readonly($config)
    {
        return (cfgpanel_validation($config) === 'readonly');
    }
}

if (!function_exists('cfgpanel_is_password')) {
    function cfgpanel_is_password($fieldName)
    {
        return (stripos($fieldName, 'password') !== false);
    }
}

if (!function_exists('cfgpanel_field_id')) {
    function cfgpanel_field_id($name, $suffix = '')
    {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);

        if ($suffix !== '') {
            $id .= '-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $suffix);
        }

        return $id;
    }
}

if (!function_exists('cfgpanel_render_help')) {
    function cfgpanel_render_help($config)
    {
        if (!empty($config['desc'])) {
            echo '<p class="help-block config-help">' . cfgpanel_e($config['desc']) . '</p>';
        }
    }
}

if (!function_exists('cfgpanel_render_hidden_id')) {
    function cfgpanel_render_hidden_id($config)
    {
        if (isset($config['id'])) {
            echo '<input type="hidden" name="' . cfgpanel_e($config['name']) . '-id" value="' . cfgpanel_e($config['id']) . '">' . "\n";
        }
    }
}

if (!function_exists('cfgpanel_render_input')) {
    function cfgpanel_render_input($config, $value, $fieldName, $fieldId, $columnClass)
    {
        $validation = cfgpanel_validation($config);
        $readonly = cfgpanel_is_readonly($config);
        $type = cfgpanel_is_password($config['name']) ? 'password' : 'text';
        ?>
        <div class="<?php echo cfgpanel_e($columnClass); ?>">
            <div class="config-card">
                <label class="control-label config-label" for="<?php echo cfgpanel_e($fieldId); ?>">
                    <?php echo cfgpanel_e($config['title']); ?>
                </label>

                <input class="form-control <?php echo cfgpanel_e($validation); ?>"
                       id="<?php echo cfgpanel_e($fieldId); ?>"
                       type="<?php echo cfgpanel_e($type); ?>"
                       name="<?php echo cfgpanel_e($fieldName); ?>"
                       placeholder="<?php echo cfgpanel_e(isset($config['example']) ? $config['example'] : ''); ?>"
                       value="<?php echo cfgpanel_e($value); ?>"
                       <?php echo $readonly ? 'disabled' : ''; ?>>

                <?php cfgpanel_render_help($config); ?>
            </div>
        </div>
        <?php
        cfgpanel_render_hidden_id($config);
    }
}

if (!function_exists('cfgpanel_render_textarea')) {
    function cfgpanel_render_textarea($config, $value, $fieldName, $fieldId, $columnClass)
    {
        $validation = cfgpanel_validation($config);
        $readonly = cfgpanel_is_readonly($config);
        ?>
        <div class="<?php echo cfgpanel_e($columnClass); ?>">
            <div class="config-card">
                <label class="control-label config-label" for="<?php echo cfgpanel_e($fieldId); ?>">
                    <?php echo cfgpanel_e($config['title']); ?>
                </label>

                <textarea rows="4"
                          class="form-control <?php echo cfgpanel_e($validation); ?>"
                          id="<?php echo cfgpanel_e($fieldId); ?>"
                          name="<?php echo cfgpanel_e($fieldName); ?>"
                          placeholder="<?php echo cfgpanel_e(isset($config['example']) ? $config['example'] : ''); ?>"
                          <?php echo $readonly ? 'disabled' : ''; ?>><?php echo cfgpanel_e($value); ?></textarea>

                <?php cfgpanel_render_help($config); ?>
            </div>
        </div>
        <?php
        cfgpanel_render_hidden_id($config);
    }
}

if (!function_exists('cfgpanel_render_checkbox')) {
    function cfgpanel_render_checkbox($config, $value, $fieldName, $fieldId, $columnClass)
    {
        $checked = ((string) $value === 'true');
        $hiddenId = $fieldId . '-id';
        ?>
        <div class="<?php echo cfgpanel_e($columnClass); ?>">
            <div class="config-card">
                <div class="checkbox">
                    <label class="config-label">
                        <input type="checkbox"
                               id="<?php echo cfgpanel_e($fieldId); ?>"
                               class="js-config-checkbox"
                               data-target="<?php echo cfgpanel_e($hiddenId); ?>"
                               <?php echo $checked ? 'checked' : ''; ?>>
                        <?php echo cfgpanel_e($config['title']); ?>
                    </label>
                </div>

                <input id="<?php echo ($hiddenId); ?>"
                       class="js-checkbox-value"
                       type="hidden"
                       name="<?php echo cfgpanel_e($fieldName); ?>"
                       value="<?php echo $checked ? 'true' : 'false'; ?>">

                <?php cfgpanel_render_help($config); ?>
            </div>
        </div>
        <?php
        cfgpanel_render_hidden_id($config);
    }
}

if (!function_exists('cfgpanel_render_select')) {
    function cfgpanel_render_select($config, $value, $fieldName, $fieldId, $columnClass)
    {
        ?>
        <div class="<?php echo cfgpanel_e($columnClass); ?>">
            <div class="config-card">
                <label class="control-label config-label" for="<?php echo cfgpanel_e($fieldId); ?>">
                    <?php echo cfgpanel_e($config['title']); ?>
                </label>

                <select name="<?php echo cfgpanel_e($fieldName); ?>"
                        id="<?php echo cfgpanel_e($fieldId); ?>"
                        class="form-control">
                    <?php echo call_user_func_array(array(new ppsmMain, '_timezonelist'), array($value)); ?>
                </select>

                <?php cfgpanel_render_help($config); ?>
            </div>
        </div>
        <?php
        cfgpanel_render_hidden_id($config);
    }
}

if (!function_exists('cfgpanel_render_wysiwyg')) {
    function cfgpanel_render_wysiwyg($config, $value, $fieldName, $fieldId)
    {
        ?>
        <div class="col-md-12">
            <div class="config-card">
                <label class="control-label config-label" for="<?php echo cfgpanel_e($fieldId); ?>">
                    <?php echo cfgpanel_e($config['title']); ?>
                </label>

                <textarea class="trumbowyg form-control"
                          id="<?php echo cfgpanel_e($fieldId); ?>"
                          name="<?php echo cfgpanel_e($fieldName); ?>"
                          placeholder="<?php echo cfgpanel_e(isset($config['example']) ? $config['example'] : ''); ?>"><?php echo cfgpanel_e($value); ?></textarea>

                <?php cfgpanel_render_help($config); ?>
            </div>
        </div>
        <?php
        cfgpanel_render_hidden_id($config);
    }
}
