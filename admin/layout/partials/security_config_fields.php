<?php

/**
 * Security Configuration Renderer
 *
 * Restored from original security.php.
 * This file is responsible for rendering:
 *
 * - Checkboxes
 * - Text Inputs
 * - Text Areas
 * - Select Lists
 * - WYSIWYG Editors
 */

//echo json_encode($configs);
foreach (_cfg_panel($name) as $key => $configs) {
		// Hide internal  info records unless debug mode enabled
		if ($key == 'info' && (!defined('DEBUG') || !DEBUG)) {
			continue;
		}
	switch ($key) {

case 'checkbox':
?>
<div class="row">
<?php foreach ($configs as $config) { ?>
    <div class="form-group col-md-4">
        <div class="field-widget">

            <div class="checkbox">
                <label>
                    <input
                        id="<?php echo $config['name']; ?>"
                        type="checkbox"
                        <?php echo ($config['value'] == 'true' ? 'checked' : ''); ?>>
                    <?php echo $config['title']; ?>
                </label>
            </div>

            <input
                id="<?php echo $config['name']; ?>-id"
                type="hidden"
                name="<?php echo $config['name']; ?>"
                value="<?php echo $config['value']; ?>">

            <input
                type="hidden"
                name="<?php echo $config['name']; ?>-id"
                value="<?php echo $config['id']; ?>">

        </div>
    </div>
<?php } ?>
</div>
<?php
        break;

    case 'textbox':
?>
<div class="row">
<?php foreach ($configs as $config) { ?>
    <div class="form-group col-md-6">

        <div class="field-label">
            <label for="<?php echo $config['name']; ?>">
                <?php echo $config['title']; ?>
            </label>
        </div>

        <div class="field-widget">

            <textarea
                class="form-control <?php echo isset($config['validation']) ? $config['validation'] : ''; ?>"
                rows="4"
                id="<?php echo $config['name']; ?>"
                name="<?php echo $config['name']; ?>"
                placeholder="<?php echo $config['example']; ?>"
                <?php echo ($config['validation'] == 'readonly' ? 'disabled' : ''); ?>
            ><?php echo $config['value']; ?></textarea>

            <em><?php echo $config['desc']; ?></em>

        </div>

        <input
            type="hidden"
            name="<?php echo $config['name']; ?>-id"
            value="<?php echo $config['id']; ?>">

    </div>
<?php } ?>
</div>
<?php
        break;

    case 'input':
?>
<div class="row">
<?php foreach ($configs as $config) { ?>
    <div class="form-group col-md-6">

        <div class="field-label">
            <label for="<?php echo $config['name']; ?>">
                <?php echo $config['title']; ?>
            </label>
        </div>
        <div class="field-widget">

            <input
                class="form-control <?php echo isset($config['validation']) ? $config['validation'] : ''; ?>"
                id="<?php echo $config['name']; ?>"
                name="<?php echo $config['name']; ?>"
                type="<?php echo (strpos($config['name'],'password') !== false ? 'password' : 'text'); ?>"
                value="<?php echo $config['value']; ?>"
                placeholder="<?php echo $config['example']; ?>"
                <?php echo ($config['validation'] == 'readonly' ? 'disabled' : ''); ?>>

            <em><?php echo $config['desc']; ?></em>

        </div>

        <input
            type="hidden"
            name="<?php echo $config['name']; ?>-id"
            value="<?php echo $config['id']; ?>">

    </div>
<?php } ?>
</div>
<?php
        break;

    case 'select':
?>
<div class="row">
<?php foreach ($configs as $config) { ?>
    <div class="form-group col-md-6">

        <div class="field-label">
            <label for="<?php echo $config['name']; ?>">
                <?php echo $config['title']; ?>
            </label>
        </div>

        <div class="field-widget">

            <select
                class="form-control"
                id="<?php echo $config['name']; ?>"
                name="<?php echo $config['name']; ?>">

                <?php
                echo call_user_func_array(
                    array(new ppsmMain, "_timezonelist"),
                    array($config['value'])
                );
                ?>

            </select>

            <em><?php echo $config['desc']; ?></em>

        </div>

        <input
            type="hidden"
            name="<?php echo $config['name']; ?>-id"
            value="<?php echo $config['id']; ?>">

    </div>
<?php } ?>
</div>
<?php
        break;

    case 'wysiwyg':
?>
<div class="row">
<?php foreach ($configs as $config) { ?>
    <div class="form-group col-md-12">

        <div class="field-label">
            <label for="<?php echo $config['name']; ?>">
                <?php echo $config['title']; ?>
            </label>
        </div>

        <div class="field-widget">

            <textarea
                class="trumbowyg"
                name="<?php echo $config['name']; ?>"
                placeholder="<?php echo $config['example']; ?>"
            ><?php echo htmlspecialchars($config['value']); ?></textarea>

        </div>

        <input
            type="hidden"
            name="<?php echo $config['name']; ?>-id"
            value="<?php echo $config['id']; ?>">

    </div>
<?php } ?>
</div>
<?php
        break;

    default:
?>
<div class="row">
<?php foreach ($configs as $config) { ?>
    <div class="form-group col-md-6">

        <div class="field-label">
            <label for="<?php echo $config['name']; ?>">
                <?php echo $config['title']; ?>
            </label>
        </div>

        <div class="field-widget">

            <input
                class="form-control"
                id="<?php echo $config['name']; ?>"
                name="<?php echo $config['name']; ?>"
                type="text"
                value="<?php echo $config['value']; ?>">

            <em><?php echo $config['desc']; ?></em>

        </div>

        <input
            type="hidden"
            name="<?php echo $config['name']; ?>-id"
            value="<?php echo $config['id']; ?>">

    </div>
<?php } ?>
</div>
<?php
        break;
    }
}
?>