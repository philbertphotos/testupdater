<?php
/*
|--------------------------------------------------------------------------
| Security Configuration Partial
|--------------------------------------------------------------------------
|
| Renders local user accounts and password reset actions.
|
*/

global $d;

$localusers = $d->rows('SELECT id, username, hash, role FROM localusers;');
?>
<div class="panel panel-default config-panel">
    <div class="panel-heading clearfix">
        <h4 class="panel-title pull-left">
            <i class="fa fa-lock"></i>
            Local Security Accounts
        </h4>
        <span class="pull-right text-muted hidden-xs">Manage local administrator passwords</span>
    </div>

    <div class="panel-body">
        <?php if (!is_array($localusers) || empty($localusers)) { ?>
            <div class="alert alert-warning config-empty">
                No local users were found.
            </div>
        <?php } else { ?>
            <div class="row">
                <?php foreach ($localusers as $user) { ?>
                    <div class="col-md-6 col-sm-12 local-user-row">
                        <div class="config-card">
                            <label class="control-label config-label" for="local-user-<?php echo cfgpanel_e($user->id); ?>">
                                Local Admin
                            </label>

                            <div class="input-group">
                                <input disabled
                                       class="form-control"
                                       id="local-user-<?php echo cfgpanel_e($user->id); ?>"
                                       type="text"
                                       value="<?php echo cfgpanel_e($user->username); ?>">

                                <span class="input-group-addon info js-local-password"
                                      data-user-id="<?php echo cfgpanel_e($user->id); ?>"
                                      data-username="<?php echo cfgpanel_e($user->username); ?>"
                                      title="Change Password">
                                    <span class="glyphicon glyphicon-asterisk"></span>
                                </span>
                            </div>

                            <p class="help-block config-help">
                                Click the icon to update the local password.
                            </p>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>
    <?php
    require_once DOCROOT . '/admin/layout/partials/security_config_fields.php';
    ?>

<script>
(function ($) {
    'use strict';

    $('.js-local-password').on('click', function () {
        var userId = $(this).data('user-id');
        var username = $(this).data('username');
        var inputId = 'localpass-' + userId;

        BootstrapDialog.show({
            title: 'Local Password - ' + username,
            message: '<input placeholder="Enter New Password" id="' + inputId + '" type="password" class="form-control" value="">',
            buttons: [{
                label: 'Cancel',
                cssClass: 'btn-danger',
                action: function (dialogRef) {
                    dialogRef.close();
                }
            }, {
                label: 'Save',
                cssClass: 'btn-success',
                hotkey: 13,
                action: function (dialogRef) {
                    var localpass = $('#' + inputId).val();

                    $.ajax({
                        type: 'POST',
                        data: 'action=localpassword&localpass=' + encodeURIComponent(localpass) + '&id=' + encodeURIComponent(userId),
                        url: '<?php echo cfgpanel_e(WEBROOT); ?>admin/ajax.php',
                        dataType: 'json',
                        success: function (data) {
                            if (data.result == 0) {
                                BootstrapDialog.show({
                                    message: data.info,
                                    onshown: function (successDialog) {
                                        setTimeout(function () {
                                            successDialog.close();
                                            BootstrapDialog.closeAll();
                                        }, 5000);
                                    }
                                });
                            } else {
                                BootstrapDialog.alert(data.info);
                            }
                        },
                        error: function (xhr) {
                            BootstrapDialog.alert('Unable to update local password. ' + xhr.statusText);
                        }
                    });
                }
            }]
        });
    });
})(jQuery);
</script>