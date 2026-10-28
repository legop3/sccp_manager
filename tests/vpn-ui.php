<?php
// Smoke-test the rendered forms without requiring a FreePBX installation.
if (!function_exists('_')) {
    function _($message) { return $message; }
}
define('FREEPBX_IS_AUTH', true);
class MockProvisioning {
    public function managedStatus() {
        return array(
            'service' => 'active', 'vpn_configuration_exists' => true,
            'signing_tools_installed' => true, 'tftp_certificate_exists' => true,
            'sast_certificate_exists' => true, 'server_certificate_exists' => true
        );
    }
    public function deviceSettings($id) {
        return array('vpn' => 'on', 'auto_detect' => 'inherit', 'sign' => 'inherit',
            'gateway' => '', 'mtu' => '');
    }
}
class MockModule {
    public $vpnprovisioning;
    public $vpnPageMessage = '';
    public $vpnPageError = '';
    public function __construct() { $this->vpnprovisioning = new MockProvisioning(); }
    public function vpnDetectedPbxIp() { return '192.168.0.25'; }
    public function vpnSettings() {
        return array(
            'gateway' => 'https://vpn.example.org:4443/svc', 'enabled' => true,
            'vpn_default' => 'off', 'auto_detect_default' => true,
            'sign_default' => 'auto', 'pbx_ip' => '', 'vpn_subnet' => '10.254.77.0/24',
            'udp_port' => 443, 'max_same_clients' => 0, 'mtu' => 1290
        );
    }
    public function renderPhone() {
        $dev_id = 'SEP001122334455';
        ob_start();
        include __DIR__ . '/../views/form.devicevpn.php';
        return ob_get_clean();
    }
}
class FreePBX {
    public static $instance;
    public static function create() { return (object) array('Sccp_manager' => self::$instance); }
}
function checkUi($passed, $name) {
    if (!$passed) { throw new RuntimeException('FAILED: ' . $name); }
    echo "PASS: $name\n";
}
FreePBX::$instance = new MockModule();
$_GET['display'] = 'sccpsettings';
$_REQUEST['display'] = 'sccpsettings';
ob_start();
include __DIR__ . '/../page.sccp_vpn.php';
$html = ob_get_clean();
checkUi(strpos($html, 'name="vpn_host"') !== false &&
    strpos($html, 'value="vpn.example.org"') !== false, 'Public hostname shown instead of full Cisco URL');
checkUi(strpos($html, 'name="vpn_tcp_port"') !== false &&
    strpos($html, 'value="4443"') !== false, 'TCP port retained under Advanced');
checkUi(strpos($html, 'Leave blank to detect automatically.') !== false &&
    strpos($html, 'placeholder="192.168.0.25"') !== false, 'PBX address is detected');
checkUi(strpos($html, 'name="vpn_enabled"') !== false &&
    strpos($html, 'name="vpn_auto_detect_default"') !== false &&
    strpos($html, 'name="sign_default"') !== false, 'Existing save fields retained');
checkUi(strpos($html, '<details') !== false &&
    strpos($html, 'name="vpn_subnet"') !== false, 'Advanced fields remain editable');
checkUi(strpos($html, 'display=sccpsettings#sccpvpn') !== false &&
    strpos($html, 'name="display" value="sccp_vpn"') === false, 'Forms target only Server Config tab');
checkUi(strpos($html, 'router') === false && strpos($html, 'manually started') === false,
    'No manual-deployment commentary');
$phone = FreePBX::$instance->renderPhone();
checkUi(strpos($phone, 'name="sccp_vpn_mode"') !== false &&
    strpos($phone, 'name="sccp_vpn_auto_detect"') !== false &&
    strpos($phone, 'name="sccp_sign_mode"') !== false, 'Phone fields still submit');
checkUi(strpos($phone, '<details') !== false, 'Rare per-phone overrides are collapsed');
$moduleXml = file_get_contents(__DIR__ . '/../module.xml');
checkUi(strpos($moduleXml, '<sccp_vpn sort=') === false &&
    strpos($moduleXml, '<sccpsettings') !== false, 'Only Server Config navigation entry remains');
echo "VPN UI checks passed.\n";
