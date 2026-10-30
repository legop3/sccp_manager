<?php
// Run with: php tests/address-routing.php
require_once __DIR__ . '/../sccpManClasses/phonecontent.class.php';
class FakeVpn {
    public $on = array();
    public function enabledFor($id) { return $this->on[$id] ?? false; }
}
class FakeModule {
    public $vpnprovisioning;
    public $sccppath = array('tftp_path' => '/tmp');
    public $settings = array('enabled' => true, 'pbx_ip' => '192.168.0.25', 'vpn_subnet' => '10.254.77.0/24');
    public $manual = '';
    public function __construct() { $this->vpnprovisioning = new FakeVpn(); }
    public function vpnSettings() { return $this->settings; }
    public function getConfig($key) { return $key === 'phonecontent_host' ? $this->manual : null; }
    public function vpnDetectedPbxIp() { return '192.168.0.25'; }
}
function expectUrl($actual, $expected, $description) {
    if ($actual !== $expected) throw new RuntimeException("$description: got $actual, expected $expected");
}
$p = new FakeModule();
$p->vpnprovisioning->on = array('SEP00EBD5CA227A' => false, 'SEP00EBD5CA2776' => true);
$c = new \FreePBX\modules\Sccp_manager\phonecontent($p, '/tmp', '/tmp');
$lan = 'http://192.168.0.25/sccp-manager';
$vpn = 'http://10.254.77.1/sccp-manager';
expectUrl($c->baseUrl('SEP00EBD5CA227A'), $lan, 'VPN Off');
expectUrl($c->baseUrl('SEP00EBD5CA2776'), $vpn, 'VPN On');
expectUrl($c->baseUrl(), $lan, 'Shared publisher');
$_SERVER['HTTP_HOST'] = '10.254.77.1';
expectUrl($c->requestBaseUrl(), $vpn, 'VPN request');
$_SERVER['HTTP_HOST'] = '192.168.0.25';
expectUrl($c->requestBaseUrl(), $lan, 'LAN request');
$p->manual = '10.254.77.1';
expectUrl($c->baseUrl('SEP00EBD5CA227A'), $lan, 'Legacy global VPN value does not affect VPN Off');
expectUrl($c->baseUrl('SEP00EBD5CA2776'), $vpn, 'VPN On remains correct');
$p->manual = '192.168.0.40';
expectUrl($c->baseUrl('SEP00EBD5CA227A'), 'http://192.168.0.40/sccp-manager', 'LAN override');
expectUrl($c->baseUrl('SEP00EBD5CA2776'), $vpn, 'VPN independent of LAN override');
$p->settings['enabled'] = false;
expectUrl($c->baseUrl('SEP00EBD5CA227A'), 'http://192.168.0.40/sccp-manager', 'VPN server disabled');
echo "Address routing tests passed\n";
