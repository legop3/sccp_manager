<?php
class FakeFirewall
{
    public $services = array();
    public $zones = array();
    public $networks = array();
    public $enabled = true;
    public $reloads = 0;

    public function getAllCustomServices() { return $this->services; }
    public function addCustomService($name, $proto, $port) {
        $id = 'generated-' . (count($this->services) + 1);
        $this->services[$id] = array('name' => $name, 'custfw' => array('protocol' => $proto, 'port' => $port));
    }
    public function editCustomService($id, $name, $proto, $port) {
        $this->services[$id] = array('name' => $name, 'custfw' => array('protocol' => $proto, 'port' => $port));
    }
    public function setCustomServiceZones($id, $zones) { $this->zones[$id] = $zones; }
    public function get_networkmaps() { return $this->networks; }
    public function addNetworkToZone($net, $zone, $description) { $this->networks[$net] = $zone; }
    public function removeNetwork($net) { unset($this->networks[$net]); }
    public function getConfig($key) { return $this->enabled; }
    public function startFirewall() { ++$this->reloads; }
}
class FakeModule
{
    public $config = array();
    public function getConfig($key) { return $this->config[$key] ?? null; }
    public function setConfig($key, $value) { $this->config[$key] = $value; }
}
class FreePBX
{
    public static $firewall;
    public static function Firewall() { return self::$firewall; }
}
require_once __DIR__ . '/../sccpManClasses/vpnfirewall.class.php';

use FreePBX\modules\Sccp_manager\vpnfirewall;

function must($value, $label)
{
    if (!$value) { throw new RuntimeException('FAILED: ' . $label); }
    echo "PASS: $label\n";
}

$parent = new FakeModule();
$fw = new FakeFirewall();
$fw->services['untouched'] = array('name' => 'Other SIP Service', 'custfw' => array('protocol' => 'udp', 'port' => '5060'));
$fw->zones['untouched'] = array('external');
FreePBX::$firewall = $fw;
$manager = new vpnfirewall($parent);

$first = $manager->configure(4443, 443, '10.254.77.0/24');
must($first['managed'] && $first['reloaded'], 'Firewall activated using the FreePBX module');
must(count($fw->services) === 3, 'Creates only two managed TCP/UDP services');
must($fw->services['generated-2']['custfw'] === array('protocol' => 'tcp', 'port' => '4443'), 'TCP service has correct port');
must($fw->services['generated-3']['custfw'] === array('protocol' => 'udp', 'port' => '443'), 'UDP service has correct port');
must($fw->zones['generated-2'] === array('external') && $fw->zones['generated-3'] === array('external'), 'Only public VPN ports are exposed');
must(($fw->networks['10.254.77.0/24'] ?? null) === 'internal', 'VPN clients assigned to internal firewall zone');
must($fw->reloads === 1, 'Applies running firewall after save');

$manager->configure(4443, 443, '10.254.77.0/24');
must(count($fw->services) === 3, 'Repeated save is idempotent');

$manager->configure(5555, 8443, '10.254.78.0/24');
must(count($fw->services) === 3, 'Port changes update services rather than duplicating');
must($fw->services['generated-2']['custfw']['port'] === '5555', 'TCP port update propagates');
must($fw->services['generated-3']['custfw']['port'] === '8443', 'UDP port update propagates');
must(!isset($fw->networks['10.254.77.0/24']) && isset($fw->networks['10.254.78.0/24']), 'Old managed VPN pool is removed after change');
must($fw->services['untouched']['custfw']['port'] === '5060', 'Unrelated firewall services remain unchanged');

$fw->enabled = false;
$before = $fw->reloads;
$manager->configure(5555, 8443, '10.254.78.0/24');
must($fw->reloads === $before, 'Does not start a disabled FreePBX Firewall');

$fw->networks['10.254.78.0/24'] = 'trusted';
$blocked = false;
try { $manager->configure(5555, 8443, '10.254.78.0/24'); }
catch (RuntimeException $e) { $blocked = true; }
must($blocked, 'Does not override subnet assigned to another zone');

echo "FreePBX Firewall integration tests passed.\n";
