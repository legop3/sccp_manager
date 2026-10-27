<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}
$module = FreePBX::create()->Sccp_manager;
$settings = $module->vpnSettings();
$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$status = array();
$statusError = '';
try {
    $status = $module->vpnprovisioning->managedStatus();
} catch (\Throwable $error) {
    $statusError = $error->getMessage();
}
$serviceRunning = ($status['service'] ?? '') === 'active';
$helperReady = !$statusError && !empty($status['signing_tools_installed']) &&
    !empty($status['tftp_certificate_exists']) && !empty($status['sast_certificate_exists']) &&
    !empty($status['server_certificate_exists']);
?>
<div class="container-fluid">
    <h2><?php echo _('Cisco SCCP VPN Phones'); ?></h2>
    <p><?php echo _('Manage the built-in Cisco phone VPN profile and signed provisioning from FreePBX. Compatible phones connect to your existing ocserv server; SCCP Manager continues managing extensions and ordinary phone settings.'); ?></p>
    <?php if ($module->vpnPageMessage): ?>
        <div class="alert alert-success"><?php echo $escape($module->vpnPageMessage); ?></div>
    <?php endif; ?>
    <?php if ($module->vpnPageError): ?>
        <div class="alert alert-danger"><?php echo $escape($module->vpnPageError); ?></div>
    <?php endif; ?>

    <h3><?php echo _('VPN server and signing'); ?></h3>
    <?php if ($statusError): ?>
        <div class="alert alert-warning">
            <?php echo _('Managed VPN helper is not available: '), $escape($statusError); ?>
            <p><?php echo _('First install the privileged signer from the module scripts directory. Do not make your signing keys publicly readable.'); ?></p>
        </div>
    <?php else: ?>
        <table class="table table-bordered" style="max-width:740px">
            <tbody>
                <tr><th><?php echo _('ocserv systemd service'); ?></th><td><?php echo $escape($status['service'] ?? 'unknown'); ?></td></tr>
                <tr><th><?php echo _('ocserv executable'); ?></th><td><?php echo $escape($status['ocserv_binary'] ?? 'missing'); ?></td></tr>
                <tr><th><?php echo _('ocserv configuration'); ?></th><td><?php echo empty($status['vpn_configuration_exists']) ? _('Missing') : _('Present'); ?></td></tr>
                <tr><th><?php echo _('Cisco signing dependencies'); ?></th><td><?php echo $helperReady ? _('Ready') : _('Missing files or signing tools'); ?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>

    <form method="post" action="config.php?display=sccp_vpn">
        <input type="hidden" name="display" value="sccp_vpn">
        <input type="hidden" name="vpn_action" value="start">
        <button type="submit" class="btn btn-default" <?php echo $statusError || $serviceRunning ? 'disabled' : ''; ?>>
            <?php echo _('Start managed ocserv service'); ?>
        </button>
        <p class="help-block"><?php echo _('Stop any manually started ocserv process before starting this service. This button does not change ocserv.conf or your router configuration.'); ?></p>
    </form>

    <hr>
    <h3><?php echo _('VPN login for your phones'); ?></h3>
    <p><?php echo _('Create or update a login shared by multiple phones. Existing unrelated ocserv accounts are preserved. The password is sent to the privileged helper without being stored in FreePBX.'); ?></p>
    <form method="post" action="config.php?display=sccp_vpn" autocomplete="off">
        <input type="hidden" name="display" value="sccp_vpn">
        <input type="hidden" name="vpn_action" value="account">
        <div class="form-group">
            <label for="vpn_username"><?php echo _('VPN username'); ?></label>
            <input type="text" class="form-control" id="vpn_username" name="vpn_username" required
                maxlength="64" pattern="[A-Za-z0-9_.-]+" autocomplete="off" style="max-width:300px">
        </div>
        <div class="form-group">
            <label for="vpn_password"><?php echo _('VPN password'); ?></label>
            <input type="password" class="form-control" id="vpn_password" name="vpn_password" required
                autocomplete="new-password" style="max-width:300px">
        </div>
        <button type="submit" class="btn btn-default" <?php echo $statusError ? 'disabled' : ''; ?>>
            <?php echo _('Create / update VPN login'); ?>
        </button>
    </form>

    <hr>
    <h3><?php echo _('Cisco phone configuration'); ?></h3>
    <form method="post" action="config.php?display=sccp_vpn">
        <input type="hidden" name="display" value="sccp_vpn">
        <input type="hidden" name="save_vpn" value="1">
        <div class="form-group">
            <label for="vpn_enabled">
                <input id="vpn_enabled" name="vpn_enabled" type="checkbox" value="1"
                    <?php echo !empty($settings['enabled']) ? 'checked' : ''; ?>>
                <?php echo _('Enable managed VPN provisioning'); ?>
            </label>
            <p class="help-block"><?php echo _('Only selected Cisco SCCP phones receive the VPN profile. Leave existing signing certificates in place across updates.'); ?></p>
        </div>
        <div class="form-group">
            <label for="vpn_gateway"><?php echo _('VPN gateway URL'); ?></label>
            <input type="url" class="form-control" id="vpn_gateway" name="vpn_gateway"
                placeholder="https://vpn.example.net:4443/svc"
                value="<?php echo $escape($settings['gateway'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label for="vpn_devices"><?php echo _('Phones enabled for built-in VPN (one SEP MAC ID per line)'); ?></label>
            <textarea id="vpn_devices" name="vpn_devices" rows="6" class="form-control"
                placeholder="SEP001122334455"><?php echo $escape(implode("\n", (array) ($settings['devices'] ?? array()))); ?></textarea>
        </div>
        <div class="form-group">
            <label for="vpn_mtu"><?php echo _('VPN tunnel MTU'); ?></label>
            <input type="number" class="form-control" id="vpn_mtu" name="vpn_mtu" min="576" max="1500"
                value="<?php echo $escape($settings['mtu'] ?? 1290); ?>">
            <p class="help-block"><?php echo _('1290 worked with Cisco 7945G and ocserv 1.3.0.'); ?></p>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo _('Save VPN settings'); ?></button>
    </form>

    <hr>
    <h3><?php echo _('Publish signed phone configurations'); ?></h3>
    <p><?php echo _('After saving, regenerate the selected phones to create or update each signed configuration and trust list. Signing uses a root-owned helper; keys stay outside the web server.'); ?></p>
    <form method="post" action="config.php?display=sccp_vpn">
        <input type="hidden" name="display" value="sccp_vpn">
        <input type="hidden" name="vpn_action" value="regenerate">
        <button type="submit" class="btn btn-default"
            <?php echo !$helperReady || empty($settings['enabled']) ? 'disabled' : ''; ?>>
            <?php echo _('Regenerate and sign selected phones'); ?>
        </button>
    </form>
    <p class="help-block">
        <?php echo _('The first VPN profile/trust-list download must happen while a phone can reach the TFTP server. Moving the phone to another network then lets its built-in VPN access the internal PBX.'); ?>
    </p>
</div>
