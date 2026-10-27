<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Registers Cisco VPN ports and the VPN client pool with FreePBX Firewall.
 * Does not write firewall chains directly or alter unrelated services.
 */
class vpnfirewall
{
    private $parent;

    public function __construct($parent)
    {
        $this->parent = $parent;
    }

    public function configure($tcp, $udp, $subnet)
    {
        $tcp = (int) $tcp;
        $udp = (int) $udp;
        if ($tcp < 1 || $tcp > 65535 || $udp < 1 || $udp > 65535 ||
            !preg_match('/^\d{1,3}(?:\.\d{1,3}){3}\/(?:\d|[12]\d|3[0-2])$/D', $subnet)) {
            throw new \InvalidArgumentException('Invalid Cisco VPN firewall ports or network');
        }

        try {
            $firewall = \FreePBX::Firewall();
        } catch (\Throwable $error) {
            // The optional FreePBX Firewall module is not present.
            return array('managed' => false, 'reason' => 'FreePBX Firewall module not installed');
        }
        if (!is_object($firewall) || !method_exists($firewall, 'getAllCustomServices')) {
            return array('managed' => false, 'reason' => 'FreePBX Firewall API unavailable');
        }

        $this->upsertService($firewall, 'SCCP Manager VPN TCP', 'tcp', (string) $tcp);
        $this->upsertService($firewall, 'SCCP Manager VPN UDP', 'udp', (string) $udp);

        // In the firewall's default rules, VPN traffic must be recognized as
        // internal for SCCP signaling and media, not merely its public port.
        $networkmaps = $firewall->get_networkmaps();
        if (!is_array($networkmaps)) {
            $networkmaps = array();
        }
        $previous = $this->parent->getConfig('vpn_firewall_network');
        if (is_string($previous) && $previous !== '' && $previous !== $subnet &&
            ($networkmaps[$previous] ?? null) === 'internal') {
            $firewall->removeNetwork($previous);
        }
        if (isset($networkmaps[$subnet]) && $networkmaps[$subnet] !== 'internal') {
            throw new \RuntimeException('The VPN subnet is already assigned to a different FreePBX firewall zone');
        }
        if (!isset($networkmaps[$subnet])) {
            $firewall->addNetworkToZone($subnet, 'internal', 'SCCP Manager VPN client addresses');
        }
        $this->parent->setConfig('vpn_firewall_network', $subnet);

        // Reload only if Firewall is running; do not enable it implicitly or
        // change an administrator's existing global firewall policy.
        $enabled = (bool) $firewall->getConfig('status');
        if ($enabled) {
            $firewall->startFirewall();
        }
        return array('managed' => true, 'reloaded' => $enabled);
    }

    private function upsertService($firewall, $name, $protocol, $port)
    {
        $entries = $firewall->getAllCustomServices();
        if (!is_array($entries)) {
            $entries = array();
        }
        $found = array();
        foreach ($entries as $id => $service) {
            if (is_array($service) && ($service['name'] ?? null) === $name) {
                $found[] = (string) $id;
            }
        }
        if (count($found) > 1) {
            throw new \RuntimeException('Duplicate managed FreePBX Firewall service: ' . $name);
        }
        if (!$found) {
            $firewall->addCustomService($name, $protocol, $port);
            $entries = $firewall->getAllCustomServices();
            foreach ((array) $entries as $id => $service) {
                if (is_array($service) && ($service['name'] ?? null) === $name) {
                    $found[] = (string) $id;
                }
            }
        }
        if (count($found) !== 1) {
            throw new \RuntimeException('Unable to locate Cisco VPN firewall service: ' . $name);
        }
        $id = $found[0];
        $firewall->editCustomService($id, $name, $protocol, $port);
        $firewall->setCustomServiceZones($id, array('external'));
    }
}
