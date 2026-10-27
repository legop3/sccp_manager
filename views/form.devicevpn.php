<?php
// Included inside the existing SCCP device edit form.
// Device preferences are stored in FreePBX's KV store, not in chan_sccp
// realtime columns (which must contain only valid driver settings).
$deviceVpn = $this->vpnprovisioning->deviceSettings($dev_id ?? '');
$globalVpn = $this->vpnSettings();
$vpnEscape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$vpnChoices = array('inherit' => _('Use server default'), 'on' => _('Enabled'), 'off' => _('Disabled'));
?>
<fieldset class="sccp-device-vpn">
    <legend><?php echo _('VPN & Signed Provisioning'); ?></legend>
    <p class="help-block">
        <?php echo _('Save this device to update its VPN configuration and signed provisioning automatically. VPN server settings and signing keys are managed under SCCP Connectivity > Server Config > VPN Phones.'); ?>
    </p>
    <div class="element-container">
        <div class="row"><div class="form-group">
            <div class="col-md-3"><label for="sccp_vpn_mode"><?php echo _('Built-in VPN'); ?></label></div>
            <div class="col-md-9">
                <select class="form-control" name="sccp_vpn_mode" id="sccp_vpn_mode">
                    <?php foreach ($vpnChoices as $id => $title): ?>
                        <option value="<?php echo $vpnEscape($id); ?>" <?php echo $deviceVpn['vpn'] === $id ? 'selected' : ''; ?>><?php echo $vpnEscape($title); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="help-block"><?php echo _('The server default is currently: '), !empty($globalVpn['enabled']) && (($globalVpn['vpn_default'] ?? 'off') === 'on') ? _('Enabled') : _('Disabled'); ?>.
                </p>
            </div>
        </div></div>
    </div>
    <div class="element-container">
        <div class="row"><div class="form-group">
            <div class="col-md-3"><label for="sccp_sign_mode"><?php echo _('Signed configurations'); ?></label></div>
            <div class="col-md-9">
                <select class="form-control" name="sccp_sign_mode" id="sccp_sign_mode">
                    <?php foreach ($vpnChoices as $id => $title): ?>
                        <option value="<?php echo $vpnEscape($id); ?>" <?php echo $deviceVpn['sign'] === $id ? 'selected' : ''; ?>><?php echo $vpnEscape($title); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="help-block"><?php echo _('Server default: '), $vpnEscape($globalVpn['sign_default'] ?? 'auto'), _('. Automatic signing covers VPN phones and devices with an existing ITL. Turning signing off for an already-trusted phone is blocked to prevent a trust failure.'); ?></p>
            </div>
        </div></div>
    </div>
    <div class="element-container">
        <div class="row"><div class="form-group">
            <div class="col-md-3"><label for="sccp_vpn_gateway"><?php echo _('VPN gateway override'); ?></label></div>
            <div class="col-md-9">
                <input type="url" class="form-control" id="sccp_vpn_gateway" name="sccp_vpn_gateway"
                    value="<?php echo $vpnEscape($deviceVpn['gateway']); ?>"
                    placeholder="<?php echo $vpnEscape($globalVpn['gateway'] ?? 'https://vpn.example.com:4443/svc'); ?>">
                <p class="help-block"><?php echo _('Leave blank to use the global gateway.'); ?></p>
            </div>
        </div></div>
    </div>
    <div class="element-container">
        <div class="row"><div class="form-group">
            <div class="col-md-3"><label for="sccp_vpn_mtu"><?php echo _('VPN MTU override'); ?></label></div>
            <div class="col-md-9">
                <input type="number" class="form-control" id="sccp_vpn_mtu" name="sccp_vpn_mtu"
                    min="576" max="1500" value="<?php echo $vpnEscape($deviceVpn['mtu']); ?>"
                    placeholder="<?php echo $vpnEscape($globalVpn['mtu'] ?? 1290); ?>">
                <p class="help-block"><?php echo _('Leave blank to inherit the global MTU.'); ?></p>
            </div>
        </div></div>
    </div>
</fieldset>
