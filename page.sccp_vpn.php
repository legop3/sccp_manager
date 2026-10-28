<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}
// Keep older bookmarks working without presenting two copies of this page.
if (($_GET['display'] ?? '') === 'sccp_vpn') {
    $destination = 'config.php?display=sccpsettings#sccpvpn';
    if (!headers_sent()) {
        header('Location: ' . $destination, true, 302);
    } else {
        echo '<script>window.location.replace(' . json_encode($destination) . ');</script>';
    }
    return;
}

$module = FreePBX::create()->Sccp_manager;
$settings = $module->vpnSettings();
$actionUrl = 'config.php?display=sccpsettings#sccpvpn';
$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$gateway = parse_url((string) ($settings['gateway'] ?? ''));
$host = is_array($gateway) ? ($gateway['host'] ?? '') : '';
$tcpPort = is_array($gateway) ? ($gateway['port'] ?? 4443) : 4443;
$detectedIp = $module->vpnDetectedPbxIp();
$status = array();
$statusError = '';
try {
    $status = $module->vpnprovisioning->managedStatus();
} catch (\Throwable $error) {
    $statusError = $error->getMessage();
}
$serviceRunning = ($status['service'] ?? '') === 'active';
$configured = !empty($status['vpn_configuration_exists']);
$signingReady = !$statusError && !empty($status['signing_tools_installed'])
    && !empty($status['tftp_certificate_exists'])
    && !empty($status['sast_certificate_exists'])
    && !empty($status['server_certificate_exists']);
$stateLabel = $statusError ? _('Unavailable') :
    ($serviceRunning ? _('Running') : ($configured ? _('Stopped') : _('Not configured')));
?>

<div class="sccp-vpn-settings" style="max-width:860px; padding:12px 4px 24px;">
    <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px;">
        <h3 style="margin:0;"><?php echo _('VPN & Signing'); ?></h3>
        <span class="text-muted"><?php echo _('Server'); ?>: <strong><?php echo $escape($stateLabel); ?></strong></span>
    </div>
    <?php if ($module->vpnPageMessage): ?>
        <div class="alert alert-success"><?php echo $escape($module->vpnPageMessage); ?></div>
    <?php endif; ?>
    <?php if ($module->vpnPageError): ?>
        <div class="alert alert-danger"><?php echo $escape($module->vpnPageError); ?></div>
    <?php endif; ?>
    <?php if ($statusError): ?>
        <div class="alert alert-warning"><?php echo $escape($statusError); ?></div>
    <?php elseif (!$signingReady && $configured): ?>
        <div class="alert alert-warning"><?php echo _('Signing tools or certificates are unavailable.'); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo $escape($actionUrl); ?>">
        <input type="hidden" name="save_vpn" value="1">
        <div class="form-group">
            <label for="vpn_enabled">
                <input id="vpn_enabled" name="vpn_enabled" type="checkbox" value="1"
                    <?php echo !empty($settings['enabled']) ? 'checked' : ''; ?>>
                <?php echo _('Enable VPN for Cisco phones'); ?>
            </label>
        </div>

        <div class="form-group">
            <label for="vpn_host"><?php echo _('VPN address'); ?></label>
            <input type="text" class="form-control" id="vpn_host" name="vpn_host"
                style="max-width:460px;" placeholder="vpn.example.com"
                value="<?php echo $escape($host); ?>" autocomplete="off">
        </div>

        <h4 style="margin-top:24px;"><?php echo _('Phone defaults'); ?></h4>
        <div class="form-group">
            <label for="vpn_default"><?php echo _('VPN on new phones'); ?></label>
            <select class="form-control" name="vpn_default" id="vpn_default" style="max-width:320px;">
                <option value="off" <?php echo ($settings['vpn_default'] ?? 'off') === 'off' ? 'selected' : ''; ?>><?php echo _('Off'); ?></option>
                <option value="on" <?php echo ($settings['vpn_default'] ?? 'off') === 'on' ? 'selected' : ''; ?>><?php echo _('On'); ?></option>
            </select>
        </div>
        <div class="form-group">
            <label for="vpn_auto_detect_default">
                <input type="checkbox" id="vpn_auto_detect_default" name="vpn_auto_detect_default" value="1"
                    <?php echo !empty($settings['auto_detect_default']) ? 'checked' : ''; ?>>
                <?php echo _('Connect automatically when off-site'); ?>
            </label>
        </div>
        <div class="form-group">
            <label for="sign_default"><?php echo _('Configuration signing'); ?></label>
            <select class="form-control" name="sign_default" id="sign_default" style="max-width:320px;">
                <option value="auto" <?php echo ($settings['sign_default'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>><?php echo _('Automatic'); ?></option>
                <option value="on" <?php echo ($settings['sign_default'] ?? 'auto') === 'on' ? 'selected' : ''; ?>><?php echo _('Always'); ?></option>
                <option value="off" <?php echo ($settings['sign_default'] ?? 'auto') === 'off' ? 'selected' : ''; ?>><?php echo _('Off'); ?></option>
            </select>
        </div>

        <details style="margin:20px 0;">
            <summary style="cursor:pointer; font-weight:600;"><?php echo _('Advanced settings'); ?></summary>
            <div style="padding:16px 0 0 12px; max-width:510px;">
                <div class="row">
                    <div class="form-group col-sm-6">
                        <label for="vpn_tcp_port"><?php echo _('TCP port'); ?></label>
                        <input class="form-control" type="number" id="vpn_tcp_port" name="vpn_tcp_port"
                            min="1" max="65535" value="<?php echo $escape($tcpPort); ?>">
                    </div>
                    <div class="form-group col-sm-6">
                        <label for="vpn_udp_port"><?php echo _('UDP port'); ?></label>
                        <input class="form-control" type="number" id="vpn_udp_port" name="vpn_udp_port"
                            min="1" max="65535" value="<?php echo $escape($settings['udp_port'] ?? 443); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="vpn_pbx_ip"><?php echo _('PBX address'); ?></label>
                    <input class="form-control" type="text" name="vpn_pbx_ip" id="vpn_pbx_ip"
                        placeholder="<?php echo $escape($detectedIp ?: _('Enter IPv4 address')); ?>"
                        value="<?php echo $escape($settings['pbx_ip'] ?? ''); ?>">
                    <small class="text-muted"><?php echo _('Leave blank to detect automatically.'); ?></small>
                </div>
                <div class="form-group">
                    <label for="vpn_subnet"><?php echo _('VPN subnet'); ?></label>
                    <input class="form-control" type="text" name="vpn_subnet" id="vpn_subnet"
                        value="<?php echo $escape($settings['vpn_subnet'] ?? '10.254.77.0/24'); ?>">
                </div>
                <div class="row">
                    <div class="form-group col-sm-6">
                        <label for="vpn_max_same_clients"><?php echo _('Phones per login'); ?></label>
                        <input class="form-control" type="number" name="vpn_max_same_clients" id="vpn_max_same_clients"
                            min="0" max="1000" value="<?php echo $escape($settings['max_same_clients'] ?? 0); ?>">
                        <small class="text-muted"><?php echo _('0 = unlimited'); ?></small>
                    </div>
                    <div class="form-group col-sm-6">
                        <label for="vpn_mtu"><?php echo _('Tunnel MTU'); ?></label>
                        <input class="form-control" type="number" name="vpn_mtu" id="vpn_mtu"
                            min="576" max="1500" value="<?php echo $escape($settings['mtu'] ?? 1290); ?>">
                    </div>
                </div>
            </div>
        </details>

        <button class="btn btn-primary" type="submit"><?php echo _('Save VPN settings'); ?></button>
    </form>

    <hr style="margin:28px 0;">
    <h4><?php echo _('VPN login'); ?></h4>
    <form method="post" action="<?php echo $escape($actionUrl); ?>" autocomplete="off">
        <input type="hidden" name="vpn_action" value="account">
        <div class="row" style="max-width:640px;">
            <div class="form-group col-sm-6">
                <label for="vpn_username"><?php echo _('Username'); ?></label>
                <input type="text" class="form-control" id="vpn_username" name="vpn_username"
                    required maxlength="64" pattern="[A-Za-z0-9_.-]+" autocomplete="off">
            </div>
            <div class="form-group col-sm-6">
                <label for="vpn_password"><?php echo _('Password'); ?></label>
                <input type="password" class="form-control" id="vpn_password" name="vpn_password"
                    required autocomplete="new-password">
            </div>
        </div>
        <button class="btn btn-default" type="submit" <?php echo $statusError || !$configured ? 'disabled' : ''; ?>>
            <?php echo _('Save login'); ?>
        </button>
    </form>

    <hr style="margin:28px 0;">
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <?php if (!$serviceRunning && $configured && !$statusError): ?>
            <form method="post" action="<?php echo $escape($actionUrl); ?>">
                <input type="hidden" name="vpn_action" value="start">
                <button type="submit" class="btn btn-default"><?php echo _('Start VPN server'); ?></button>
            </form>
        <?php endif; ?>
        <form method="post" action="<?php echo $escape($actionUrl); ?>">
            <input type="hidden" name="vpn_action" value="regenerate">
            <button type="submit" class="btn btn-default" <?php echo !$signingReady ? 'disabled' : ''; ?>>
                <?php echo _('Regenerate phone configurations'); ?>
            </button>
        </form>
    </div>
</div>
<script>
(function () {
    // After POST, retain the VPN tab instead of returning to Server Config's first tab.
    if (window.location.hash === '#sccpvpn') {
        jQuery(function ($) { $('a[href="#sccpvpn"]').tab('show'); });
    }
})();
</script>
