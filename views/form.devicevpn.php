<?php
// Per-device options are stored in the module KV store.
$deviceVpn = $this->vpnprovisioning->deviceSettings($dev_id ?? '');
$globalVpn = $this->vpnSettings();
$vpnEscape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$vpnChoices = array('inherit' => _('Default'), 'on' => _('On'), 'off' => _('Off'));
?>
<fieldset class="sccp-device-vpn">
    <legend><?php echo _('VPN'); ?></legend>
    <div class="form-group">
        <label for="sccp_vpn_mode"><?php echo _('Built-in VPN'); ?></label>
        <select class="form-control" name="sccp_vpn_mode" id="sccp_vpn_mode" style="max-width:340px;">
            <?php foreach ($vpnChoices as $id => $title): ?>
                <option value="<?php echo $vpnEscape($id); ?>" <?php echo $deviceVpn['vpn'] === $id ? 'selected' : ''; ?>><?php echo $vpnEscape($title); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="sccp_vpn_auto_detect"><?php echo _('Connect automatically when off-site'); ?></label>
        <select class="form-control" name="sccp_vpn_auto_detect" id="sccp_vpn_auto_detect" style="max-width:340px;">
            <?php foreach ($vpnChoices as $id => $title): ?>
                <option value="<?php echo $vpnEscape($id); ?>" <?php echo $deviceVpn['auto_detect'] === $id ? 'selected' : ''; ?>><?php echo $vpnEscape($title); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <details style="margin:16px 0;">
        <summary style="cursor:pointer; font-weight:600;"><?php echo _('Advanced'); ?></summary>
        <div style="padding:14px 0 0 12px; max-width:450px;">
            <div class="form-group">
                <label for="sccp_sign_mode"><?php echo _('Configuration signing'); ?></label>
                <select class="form-control" name="sccp_sign_mode" id="sccp_sign_mode">
                    <?php foreach ($vpnChoices as $id => $title): ?>
                        <option value="<?php echo $vpnEscape($id); ?>" <?php echo $deviceVpn['sign'] === $id ? 'selected' : ''; ?>><?php echo $vpnEscape($title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="sccp_vpn_gateway"><?php echo _('VPN URL override'); ?></label>
                <input type="url" class="form-control" id="sccp_vpn_gateway" name="sccp_vpn_gateway"
                    value="<?php echo $vpnEscape($deviceVpn['gateway']); ?>"
                    placeholder="<?php echo $vpnEscape($globalVpn['gateway'] ?: 'https://vpn.example.com:4443/svc'); ?>">
            </div>
            <div class="form-group">
                <label for="sccp_vpn_mtu"><?php echo _('Tunnel MTU override'); ?></label>
                <input type="number" class="form-control" id="sccp_vpn_mtu" name="sccp_vpn_mtu"
                    min="576" max="1500" value="<?php echo $vpnEscape($deviceVpn['mtu']); ?>">
            </div>
        </div>
    </details>
</fieldset>
