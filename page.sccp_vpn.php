<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

$module = FreePBX::create()->Sccp_manager;
$settings = $module->vpnSettings();
$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$fields = array(
    'gateway' => array('VPN gateway URL', 'https://vpn.example.com:4443/svc'),
    'server_certificate' => array('ocserv server certificate (PEM)', '/etc/ocserv/server-cert.pem'),
    'tftp_certificate' => array('TFTP signing certificate/key (PEM)', '/etc/cisco-signing/tftp.pem'),
    'sast_certificate' => array('SAST signing certificate/key (PEM)', '/etc/cisco-signing/sast.pem'),
    'sgnfile' => array('sgnfile executable', '/opt/certutils/sgnfile'),
    'tlvfile' => array('tlvfile executable', '/opt/certutils/tlvfile')
);
?>
<div class="container-fluid">
    <h2><?php echo _('Cisco SCCP VPN Provisioning'); ?></h2>
    <p><?php echo _('Generate built-in Cisco SSL VPN profiles and signed TFTP configuration files for selected phones. This does not install or configure the VPN server.'); ?></p>
    <?php if ($module->vpnPageMessage): ?>
        <div class="alert alert-success"><?php echo $escape($module->vpnPageMessage); ?></div>
    <?php endif; ?>
    <?php if ($module->vpnPageError): ?>
        <div class="alert alert-danger"><?php echo $escape($module->vpnPageError); ?></div>
    <?php endif; ?>

    <form method="post" action="config.php?display=sccp_vpn">
        <input type="hidden" name="display" value="sccp_vpn">
        <input type="hidden" name="save_vpn" value="1">
        <div class="form-group">
            <label for="vpn_enabled">
                <input id="vpn_enabled" name="vpn_enabled" type="checkbox" value="1"
                    <?php echo !empty($settings['enabled']) ? 'checked' : ''; ?>>
                <?php echo _('Enable VPN provisioning for selected phones'); ?>
            </label>
        </div>
        <div class="form-group">
            <label for="vpn_devices"><?php echo _('Phone IDs (one SEP MAC address per line)'); ?></label>
            <textarea id="vpn_devices" name="vpn_devices" rows="5" class="form-control"
                placeholder="SEP001122334455"><?php echo $escape(implode("\n", (array) ($settings['devices'] ?? array()))); ?></textarea>
            <p class="help-block"><?php echo _('Only these phones receive a generated VPN profile. Phones that already have an ITL continue to receive signed configurations when removed from this list.'); ?></p>
        </div>
        <?php foreach ($fields as $key => $field): ?>
            <div class="form-group">
                <label for="vpn_<?php echo $escape($key); ?>"><?php echo $escape(_($field[0])); ?></label>
                <input type="text" class="form-control" id="vpn_<?php echo $escape($key); ?>"
                    name="vpn_<?php echo $escape($key); ?>"
                    placeholder="<?php echo $escape($field[1]); ?>"
                    value="<?php echo $escape($settings[$key] ?? ''); ?>">
            </div>
        <?php endforeach; ?>
        <div class="form-group">
            <label for="vpn_mtu"><?php echo _('VPN MTU'); ?></label>
            <input type="number" class="form-control" id="vpn_mtu" name="vpn_mtu" min="576" max="1500"
                value="<?php echo $escape($settings['mtu'] ?? 1290); ?>">
        </div>
        <button type="submit" class="btn btn-primary"><?php echo _('Save VPN provisioning settings'); ?></button>
    </form>
    <hr>
    <p><strong><?php echo _('Requirements'); ?></strong></p>
    <p><?php echo _('Install certutils and initialize a trusted ITL and TFTP/SAST signing keys before provisioning. Ensure the FreePBX process can execute the utilities and access the signing keys. Existing ITLs must not be casually regenerated with different keys.'); ?></p>
    <p><?php echo _('After saving, open each selected phone in Phones Manager and save it to regenerate and sign its TFTP configuration. Do not replace your working ocserv settings.'); ?></p>
</div>
