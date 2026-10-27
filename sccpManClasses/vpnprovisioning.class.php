<?php

namespace FreePBX\modules\Sccp_manager;

/**
 * Optional Cisco SCCP VPN profile generation and signed TFTP publishing.
 *
 * No action is taken unless an administrator installs and enables the external
 * config file. This deliberately does not create or rotate signing keys, modify
 * ocserv, or replace the normal SCCP provisioning templates.
 */
class vpnprovisioning
{
    private $configFile;
    private $config;
    private $parent;

    public function __construct($parent = null, $configFile = null)
    {
        $this->configFile = $configFile ?: '/etc/sccp-manager/vpn-provisioning.json';
        $this->parent = $parent;
    }

    private function settings()
    {
        if ($this->config !== null) {
            return $this->config;
        }
        // FreePBX's BMO key/value store is authoritative when configured
        // through the module UI. A local JSON file remains a migration/CLI
        // fallback for installations predating that interface.
        $settings = null;
        if ($this->parent !== null && method_exists($this->parent, 'getConfig')) {
            $saved = $this->parent->getConfig('vpn_provisioning');
            if (is_array($saved)) {
                $settings = $saved;
            }
        }
        if ($settings === null) {
            if (!is_file($this->configFile)) {
                return $this->config = array('enabled' => false);
            }
            $contents = file_get_contents($this->configFile);
            if ($contents === false) {
                throw new \RuntimeException('Cannot read VPN provisioning configuration');
            }
            $settings = json_decode($contents, true);
            if (!is_array($settings) || json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('VPN provisioning configuration is not valid JSON');
            }
        }
        if (!isset($settings['enabled']) || $settings['enabled'] !== true) {
            return $this->config = array('enabled' => false);
        }
        if (empty($settings['devices']) || !is_array($settings['devices'])) {
            throw new \RuntimeException('VPN provisioning needs a nonempty devices allowlist');
        }
        foreach ($settings['devices'] as $device) {
            if (!is_string($device) || !preg_match('/^SEP[0-9A-F]{12}$/D', $device)) {
                throw new \RuntimeException('VPN device IDs must be SEP followed by twelve hexadecimal digits');
            }
        }
        $url = $settings['gateway'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['fragment'])) {
            throw new \RuntimeException('VPN gateway must be an HTTPS URL without credentials or a fragment');
        }
        foreach (array('server_certificate', 'tftp_certificate', 'sast_certificate', 'sgnfile', 'tlvfile') as $name) {
            if (empty($settings[$name]) || !is_string($settings[$name]) ||
                $settings[$name][0] !== '/') {
                throw new \RuntimeException("VPN setting {$name} must be an absolute path");
            }
        }
        return $this->config = $settings;
    }

    public function enabledFor($deviceId)
    {
        $settings = $this->settings();
        return !empty($settings['enabled']) &&
            in_array(strtoupper((string) $deviceId), $settings['devices'], true);
    }

    /**
     * Remove a template-provided VPN profile when centrally managed VPN is on,
     * then insert a per-device profile before versionStamp (Cisco XML ordering).
     * Unconfigured installations leave all XML completely untouched.
     */
    public function apply(\SimpleXMLElement $xml, $deviceId)
    {
        $settings = $this->settings();
        if (empty($settings['enabled'])) {
            return;
        }
        $root = dom_import_simplexml($xml);
        $doc = $root->ownerDocument;
        $versionStamp = null;
        $oldGroups = array();
        foreach ($root->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            if ($child->nodeName === 'vpnGroup') {
                $oldGroups[] = $child;
            } elseif ($child->nodeName === 'versionStamp') {
                $versionStamp = $child;
            }
        }
        foreach ($oldGroups as $child) {
            $root->removeChild($child);
        }

        if (!$this->enabledFor($deviceId)) {
            return;
        }
        $certificate = file_get_contents($settings['server_certificate']);
        if ($certificate === false ||
            !preg_match('/-----BEGIN CERTIFICATE-----\s*(.*?)\s*-----END CERTIFICATE-----/s', $certificate, $matches)) {
            throw new \RuntimeException('Cannot read the VPN server PEM certificate');
        }
        $der = base64_decode(preg_replace('/\s+/', '', $matches[1]), true);
        if ($der === false || openssl_x509_read($certificate) === false) {
            throw new \RuntimeException('VPN server certificate is invalid');
        }
        $hash = base64_encode(sha1($der, true));
        $mtu = isset($settings['mtu']) ? (int) $settings['mtu'] : 1290;
        if ($mtu < 576 || $mtu > 1500) {
            throw new \RuntimeException('VPN MTU must be between 576 and 1500');
        }
        $vpn = $doc->createElement('vpnGroup');
        $fields = array(
            'mtu' => (string) $mtu,
            'failConnectTime' => '30',
            'authMethod' => '0',
            'pswdPersistent' => '0',
            'autoNetDetect' => '0',
            'enableHostIDCheck' => '0'
        );
        foreach ($fields as $name => $value) {
            $this->element($doc, $vpn, $name, $value);
        }
        $addresses = $doc->createElement('addresses');
        $this->element($doc, $addresses, 'url1', $settings['gateway']);
        $vpn->appendChild($addresses);
        $credentials = $doc->createElement('credentials');
        $this->element($doc, $credentials, 'hashAlg', '0');
        $this->element($doc, $credentials, 'certHash1', $hash);
        $vpn->appendChild($credentials);
        $root->insertBefore($vpn, $versionStamp);
    }

    private function element(\DOMDocument $doc, \DOMElement $parent, $name, $value)
    {
        $node = $doc->createElement($name);
        $node->appendChild($doc->createTextNode((string) $value));
        $parent->appendChild($node);
    }

    /**
     * Keep the signed configuration synchronized on every SCCP Manager save.
     * Once a phone has an ITL, continue signing it even if removed from the
     * VPN allowlist: an unsigned replacement could then be rejected.
     */
    public function publish($deviceId, $xmlPath)
    {
        $settings = $this->settings();
        $deviceId = strtoupper((string) $deviceId);
        if (!preg_match('/^SEP[0-9A-F]{12}$/D', $deviceId)) {
            return;
        }
        $itlPath = dirname($xmlPath) . '/ITL' . $deviceId . '.tlv';
        if (empty($settings['enabled'])) {
            // Silently generating only unsigned XML can strand a phone that
            // already trusts signed provisioning. Refuse instead of reporting
            // a misleading successful regenerate operation.
            if (is_file($itlPath)) {
                throw new \RuntimeException('Existing Cisco ITL requires signed provisioning; enable VPN management before regenerating this phone');
            }
            return;
        }
        if (!$this->enabledFor($deviceId) && !is_file($itlPath)) {
            return;
        }
        if (!is_file($xmlPath)) {
            throw new \RuntimeException('Cannot sign a nonexistent phone configuration');
        }
        if (!is_file($itlPath)) {
            $this->run(array(
                $settings['tlvfile'], '--build', $itlPath,
                '--filename', 'ITLFile.tlv',
                '--sast', $settings['sast_certificate'],
                '--tftp', $settings['tftp_certificate']
            ));
            if (!is_file($itlPath)) {
                throw new \RuntimeException('ITL signing utility did not produce a trust list');
            }
        }
        $this->run(array(
            $settings['sgnfile'], '--build', $xmlPath,
            '--tftp', $settings['tftp_certificate']
        ));
        if (!is_file($xmlPath . '.sgn')) {
            throw new \RuntimeException('Signing utility did not produce the signed configuration');
        }
    }

    private function run(array $command)
    {
        if (!is_executable($command[0])) {
            throw new \RuntimeException('VPN signing utility is not executable: ' . $command[0]);
        }
        // PHP 7.4+ supports an argv array: never interpolate paths into a shell.
        $pipes = array();
        $process = proc_open($command, array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        ), $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start VPN signing utility');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('VPN signing utility failed: ' . trim($stderr ?: $stdout));
        }
    }
}
