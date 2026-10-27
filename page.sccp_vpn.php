<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}
$module = FreePBX::create()->Sccp_manager;
$settings = $module->vpnSettings();
$actionUrl = (($_REQUEST['display'] ?? '') === 'sccpsettings')
    ? 'config.php?display=sccpsettings#sccpvpn'
    : 'config.php?display=sccp_vpn';
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
    <h2><?php echo _('Cisco SCCP VPN & Signing'); ?></h2>
    <p><?php echo _('Set Cisco phone VPN and signing defaults here. Choose VPN and signed provisioning individually in Phones Manager, alongside the device settings.'); ?></p>
    <?php if ($module->vpnPageMessage): ?>
        <div class="alert alert-success"><?php echo $escape($module->vpnPageMessage); ?></div>
    <?php endif; ?>
    <?php if ($module->vpnPageError): ?>
        <div class="alert alert-danger"><?php echo $escape($module->vpnPageError); ?></div>
    <?php endif; ?>

    <h3><?php echo _('VPN server and signing'); ?></h3>
    <p><?php echo _('The server package, service and certificate tools are installed with SCCP Manager. Enter the server address and save below to finish initialization.'); ?></p>
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

    <form method="post" action="<?php echo $actionUrl; ?>">
        <input type="hidden" name="display" value="sccp_vpn">
        <input type="hidden" name="vpn_action" value="start">
        <button type="submit" class="btn btn-default" <?php echo $statusError || !$status['vpn_configuration_exists'] || $serviceRunning ? 'disabled' : ''; ?>>
            <?php echo _('Start managed ocserv service'); ?>
        </button>
        <p class="help-block"><?php echo _('Stop any manually started ocserv process before starting this service. This button does not change ocserv.conf or your router configuration.'); ?></p>
    </form>

    <hr>
    <h3><?php echo _('VPN login for your phones'); ?></h3>
    <p><?php echo _('Create or update a login shared by multiple phones. Existing unrelated ocserv accounts are preserved. The password is sent to the privileged helper without being stored in FreePBX.'); ?></p>
    <form method="post" action="<?php echo $actionUrl; ?>" autocomplete="off">
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
        <button type="submit" class="btn btn-default" <?php echo $statusError || empty($status['vpn_configuration_exists']) ? 'disabled' : ''; ?>>
            <?php echo _('Create / update VPN login'); ?>
        </button>
    </form>

    <hr>
    <h3><?php echo _('Server defaults for phones'); ?></h3>
    <p><?php echo _('Configure the VPN concentrator once here. In Phones Manager, each Cisco SCCP device can inherit these defaults or override VPN, signing, gateway, and MTU.'); ?></p>
    <form method="post" action="<?php echo $actionUrl; ?>">
        <input type="hidden" name="save_vpn" value="1">
        <div class="form-group">
            <label for="vpn_enabled">
                <input id="vpn_enabled" name="vpn_enabled" type="checkbox" value="1"
                    <?php echo !empty($settings['enabled']) ? 'checked' : ''; ?>>
                <?php echo _('Enable Cisco built-in VPN provisioning'); ?>
            </label>
            <p class="help-block"><?php echo _('This enables the shared VPN gateway for phones. Signed provisioning can be enabled independently of VPN.'); ?></p>
        </div>
        <div class="form-group">
            <label for="vpn_gateway"><?php echo _('VPN gateway URL'); ?></label>
            <input type="url" class="form-control" id="vpn_gateway" name="vpn_gateway"
                placeholder="https://vpn.example.net:4443/svc"
                value="<?php echo $escape($settings['gateway'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label for="vpn_pbx_ip"><?php echo _('Internal PBX IPv4 address'); ?></label>
            <input type="text" class="form-control" name="vpn_pbx_ip" id="vpn_pbx_ip"
                placeholder="192.168.0.25" value="<?php echo $escape($settings['pbx_ip'] ?? ''); ?>">
            <p class="help-block"><?php echo _('The address Cisco phones should reach through VPN for SCCP and audio. Usually the LAN IP of this FreePBX machine.'); ?></p>
        </div>
        <div class="form-group">
            <label for="vpn_subnet"><?php echo _('Phone VPN address pool'); ?></label>
            <input type="text" class="form-control" name="vpn_subnet" id="vpn_subnet"
                value="<?php echo $escape($settings['vpn_subnet'] ?? '10.254.77.0/24'); ?>">
            <p class="help-block"><?php echo _('Dedicated private subnet for connected phones. Must not overlap the PBX LAN.'); ?></p>
        </div>
        <div class="form-group">
            <label for="vpn_udp_port"><?php echo _('VPN UDP / DTLS port'); ?></label>
            <input type="number" class="form-control" name="vpn_udp_port" id="vpn_udp_port"
                min="1" max="65535" value="<?php echo $escape($settings['udp_port'] ?? 443); ?>">
        </div>
        <div class="form-group">
            <label for="vpn_max_same_clients"><?php echo _('Simultaneous phones per VPN login'); ?></label>
            <input type="number" class="form-control" name="vpn_max_same_clients" id="vpn_max_same_clients"
                min="0" max="1000" value="<?php echo $escape($settings['max_same_clients'] ?? 0); ?>">
            <p class="help-block"><?php echo _('Zero allows an unlimited number of phones to share one username.'); ?></p>
        </div>
        <div class="form-group">
            <label for="vpn_default"><?php echo _('New phone VPN default'); ?></label>
            <select class="form-control" name="vpn_default" id="vpn_default">
                <option value="off" <?php echo ($settings['vpn_default'] ?? 'off') === 'off' ? 'selected' : ''; ?>><?php echo _('Disabled unless enabled on the phone'); ?></option>
                <option value="on" <?php echo ($settings['vpn_default'] ?? 'off') === 'on' ? 'selected' : ''; ?>><?php echo _('Enabled by default for Cisco SCCP phones'); ?></option>
            </select>
        </div>
        <div class="form-group">
            <label for="vpn_auto_detect_default">
                <input type="checkbox" id="vpn_auto_detect_default" name="vpn_auto_detect_default" value="1"
                    <?php echo !empty($settings['auto_detect_default']) ? 'checked' : ''; ?>>
                <?php echo _('Connect automatically when away from the local network'); ?>
            </label>
            <p class="help-block"><?php echo _('Recommended for remote Cisco 7945G/7965G/7975G phones. The phone checks whether its TFTP server is reachable and automatically tries the VPN if not. Individual phones can override this in Phones Manager.'); ?></p>
        </div>
        <div class="form-group">
            <label for="sign_default"><?php echo _('Signed configuration default'); ?></label>
            <select class="form-control" name="sign_default" id="sign_default">
                <option value="auto" <?php echo ($settings['sign_default'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>><?php echo _('Automatic — VPN phones and phones already using ITL'); ?></option>
                <option value="on" <?php echo ($settings['sign_default'] ?? 'auto') === 'on' ? 'selected' : ''; ?>><?php echo _('Sign all Cisco SCCP phone configurations'); ?></option>
                <option value="off" <?php echo ($settings['sign_default'] ?? 'auto') === 'off' ? 'selected' : ''; ?>><?php echo _('Unsigned by default unless enabled on the phone'); ?></option>
            </select>
            <p class="help-block"><?php echo _('A device with an existing trusted ITL cannot be changed to unsigned provisioning without an intentional trust reset. Signing certificates stay outside the TFTP directory.'); ?></p>
        </div>
        <div class="form-group">
            <label for="vpn_mtu"><?php echo _('Default VPN tunnel MTU'); ?></label>
            <input type="number" class="form-control" id="vpn_mtu" name="vpn_mtu" min="576" max="1500"
                value="<?php echo $escape($settings['mtu'] ?? 1290); ?>">
        </div>
        <button type="submit" class="btn btn-primary"><?php echo _('Save settings and configure VPN server'); ?></button>
        <p class="help-block"><?php echo _('On first setup this creates the VPN server certificate and configuration, enables its service and starts it. The module never regenerates an existing certificate automatically. Forward your configured TCP port and UDP port on your router to the PBX. FreePBX Firewall rules are saved but the firewall is never started or restarted by this action; apply those changes separately when ready.'); ?></p>
    </form>

    <hr>
    <h3><?php echo _('Regenerate managed phone configurations'); ?></h3>
    <p><?php echo _('Normally saving a phone in Phones Manager generates its XML and signs it when required. Use this button after changing a global setting to update managed Cisco phones without editing each phone.'); ?></p>
    <form method="post" action="<?php echo $actionUrl; ?>">
        <input type="hidden" name="vpn_action" value="regenerate">
        <button type="submit" class="btn btn-default" <?php echo !$helperReady ? 'disabled' : ''; ?>>
            <?php echo _('Regenerate managed phones'); ?>
        </button>
    </form>
    <p class="help-block">
        <?php echo _('Provision the phone over its local TFTP connection first, then test VPN registration on a different network. An existing ITL is never automatically erased or rotated.'); ?>
    </p>
</div>
