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
    private $config;
    private $parent;
    private const HELPER = '/usr/local/libexec/sccp-manager-vpn-helper';

    public function __construct($parent = null)
    {
        $this->parent = $parent;
    }

    private function settings()
    {
        if ($this->config !== null) {
            return $this->config;
        }
        $settings = array();
        if ($this->parent !== null && method_exists($this->parent, 'getConfig')) {
            $stored = $this->parent->getConfig('vpn_provisioning');
            if (is_array($stored)) {
                $settings = $stored;
            }
        }
        $settings += array(
            'enabled' => false,
            'managed_signer' => true,
            'vpn_default' => 'off',
            'sign_default' => 'auto',
            'gateway' => '',
            'mtu' => 1290
        );
        if (!in_array($settings['vpn_default'], array('on', 'off'), true)) {
            throw new \RuntimeException('Invalid global VPN default');
        }
        if (!in_array($settings['sign_default'], array('auto', 'on', 'off'), true)) {
            throw new \RuntimeException('Invalid global signing default');
        }
        if (!empty($settings['enabled'])) {
            self::validateGateway((string) $settings['gateway']);
            $mtu = (int) $settings['mtu'];
            if ($mtu < 576 || $mtu > 1500) {
                throw new \RuntimeException('VPN MTU must be between 576 and 1500');
            }
        }
        if (empty($settings['managed_signer']) && !empty($settings['enabled'])) {
            foreach (array('server_certificate', 'tftp_certificate', 'sast_certificate', 'sgnfile', 'tlvfile') as $name) {
                if (empty($settings[$name]) || !is_string($settings[$name]) ||
                    $settings[$name][0] !== '/') {
                    throw new \RuntimeException("VPN setting {$name} must be an absolute path");
                }
            }
        }
        return $this->config = $settings;
    }

    public static function validateGateway($url)
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['fragment'])) {
            throw new \InvalidArgumentException('VPN gateway must be an HTTPS URL without credentials or a fragment');
        }
    }

    public function deviceSettings($deviceId)
    {
        $defaults = array('vpn' => 'inherit', 'sign' => 'inherit', 'gateway' => '', 'mtu' => '');
        $deviceId = strtoupper((string) $deviceId);
        if (!preg_match('/^SEP[0-9A-F]{12}$/D', $deviceId)) {
            return $defaults;
        }
        if ($this->parent === null || !method_exists($this->parent, 'getConfig')) {
            return $defaults;
        }
        $saved = $this->parent->getConfig('vpn_device_' . $deviceId);
        if (!is_array($saved)) {
            return $defaults;
        }
        return array_merge($defaults, array_intersect_key($saved, $defaults));
    }

    public function resolvedSettings($deviceId)
    {
        $deviceId = strtoupper((string) $deviceId);
        $global = $this->settings();
        $device = $this->deviceSettings($deviceId);
        $vpnMode = in_array($device['vpn'], array('inherit', 'on', 'off'), true)
            ? $device['vpn'] : 'inherit';
        $signMode = in_array($device['sign'], array('inherit', 'on', 'off'), true)
            ? $device['sign'] : 'inherit';
        $vpn = !empty($global['enabled']) &&
            ($vpnMode === 'on' || ($vpnMode === 'inherit' && $global['vpn_default'] === 'on'));
        $sign = $signMode === 'inherit' ? $global['sign_default'] :
            ($signMode === 'on' ? 'on' : 'off');
        return array(
            'vpn' => $vpn,
            'sign' => $sign,
            'gateway' => $device['gateway'] !== '' ? $device['gateway'] : $global['gateway'],
            'mtu' => $device['mtu'] !== '' ? (int) $device['mtu'] : (int) $global['mtu']
        );
    }

    public function enabledFor($deviceId)
    {
        return $this->resolvedSettings($deviceId)['vpn'];
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
        $effective = $this->resolvedSettings($deviceId);
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

        if (!$effective['vpn']) {
            return;
        }
        self::validateGateway($effective['gateway']);
        if (!empty($settings['managed_signer'])) {
            $hash = trim($this->runHelper(array('hash')));
            if (!preg_match('/^[A-Za-z0-9+\/]{27}=$/D', $hash)) {
                throw new \RuntimeException('The privileged helper returned an invalid VPN certificate hash');
            }
        } else {
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
        }
        $mtu = (int) $effective['mtu'];
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
        $this->element($doc, $addresses, 'url1', $effective['gateway']);
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
        $trusted = is_file($itlPath);
        $effective = $this->resolvedSettings($deviceId);
        $sign = $effective['sign'] === 'on' ||
            ($effective['sign'] === 'auto' && ($effective['vpn'] || $trusted));
        if ($effective['vpn'] && !$sign) {
            throw new \RuntimeException('Cisco built-in VPN provisioning requires signed configuration files');
        }

        if (!$sign) {
            if ($trusted) {
                throw new \RuntimeException('This phone already has an ITL: signing cannot be disabled without a planned trust migration');
            }
            return;
        }
        if (!is_file($xmlPath)) {
            throw new \RuntimeException('Cannot sign a nonexistent phone configuration');
        }
        if (!empty($settings['managed_signer'])) {
            $this->runHelper(array('sign', $deviceId));
            if (!is_file($xmlPath . '.sgn') || !is_file($itlPath)) {
                throw new \RuntimeException('Managed signer did not publish signed configuration and ITL');
            }
            return;
        }
        if (!$trusted) {
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

    public function managedStatus()
    {
        $output = $this->runHelper(array('status'));
        $status = json_decode($output, true);
        return is_array($status) ? $status : array('service' => 'unknown');
    }

    public function startManagedService()
    {
        return $this->runHelper(array('start'));
    }
    public function setVpnAccount($username, $password)
    {
        if (!is_string($username) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $username)) {
            throw new \InvalidArgumentException('VPN username must contain 1-64 simple characters');
        }
        if (!is_string($password) || $password === '' || strlen($password) > 1024 ||
            strpbrk($password, "\r\n") !== false) {
            throw new \InvalidArgumentException('VPN password must be nonempty, <=1024 bytes, and contain no newlines');
        }
        return $this->runHelper(array('set-account', $username), $password . "\n");
    }


    private function runHelper(array $args, $input = null)
    {
        return $this->run(array_merge(array('/usr/bin/sudo', '-n', self::HELPER), $args), $input);
    }

    private function run(array $command, $input = null)
    {
        if (!is_executable($command[0])) {
            throw new \RuntimeException('VPN signing utility is not executable: ' . $command[0]);
        }
        // PHP 7.4+ supports an argv array: never interpolate paths into a shell.
        $pipes = array();
        $process = proc_open($command, array(
            0 => $input === null ? array('file', '/dev/null', 'r') : array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        ), $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start VPN signing utility');
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('VPN command failed: ' . trim($stderr ?: $stdout));
        }
        return (string) $stdout;
    }
}
