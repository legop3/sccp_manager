<?php

require_once __DIR__ . '/../sccpManClasses/vpnprovisioning.class.php';

use FreePBX\modules\Sccp_manager\vpnprovisioning;

function check($condition, $description) {
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    echo "PASS: {$description}\n";
}

class FakeFreePBX {
    public $config = array();
    public function getConfig($key) { return $this->config[$key] ?? null; }
    public function setConfig($key, $value) { $this->config[$key] = $value; }
}

$directory = sys_get_temp_dir() . '/sccp-vpn-test-' . bin2hex(random_bytes(5));
mkdir($directory, 0700, true);
register_shutdown_function(function () use ($directory) {
    foreach (glob($directory . '/*') ?: array() as $file) {
        @unlink($file);
    }
    @rmdir($directory);
});
$key = openssl_pkey_new(array('private_key_bits' => 2048));
$csr = openssl_csr_new(array('commonName' => 'example.test'), $key);
$cert = openssl_csr_sign($csr, null, $key, 1);
openssl_x509_export($cert, $pem);
file_put_contents($directory . '/server.pem', $pem);
$tlv = $directory . '/tlvfile';
$sgn = $directory . '/sgnfile';
file_put_contents($tlv, "#!/bin/sh\n[ \"\$1\" = \"--build\" ] || exit 3\ntouch \"\$2\"\n");
file_put_contents($sgn, "#!/bin/sh\n[ \"\$1\" = \"--build\" ] || exit 3\ntouch \"\$2.sgn\"\n");
chmod($tlv, 0700);
chmod($sgn, 0700);

$parent = new FakeFreePBX();
$global = array(
    'enabled' => true,
    'vpn_default' => 'off',
    'sign_default' => 'auto',
    'gateway' => 'https://vpn.example.test:4443/svc',
    'mtu' => 1290,
    'managed_signer' => false,
    'server_certificate' => $directory . '/server.pem',
    'tftp_certificate' => $directory . '/tftp.pem',
    'sast_certificate' => $directory . '/sast.pem',
    'sgnfile' => $sgn,
    'tlvfile' => $tlv
);
$parent->setConfig('vpn_provisioning', $global);
$phone = 'SEP00EBD5CA5557';
$other = 'SEPAAAAAAAAAAAA';
$parent->setConfig('vpn_device_' . $phone, array(
    'vpn' => 'on',
    'sign' => 'inherit',
    'gateway' => 'https://remote.example.test:4443/svc?x=1&y=2',
    'mtu' => '1200'
));
$service = new vpnprovisioning($parent);
$effective = $service->resolvedSettings($phone);
check($effective['vpn'] === true, 'explicit VPN override works with server VPN default off');
check($effective['sign'] === 'auto', 'per-phone signing inherits server default');
check($effective['mtu'] === 1200, 'per-phone MTU override works');
check($service->enabledFor($other) === false, 'other phone VPN remains off without a MAC allowlist');

$xml = new SimpleXMLElement('<device><vpnGroup><old>yes</old></vpnGroup><versionStamp>0</versionStamp></device>');
$service->apply($xml, $phone);
check(count($xml->vpnGroup) === 1, 'replaces existing template VPN group');
check((string) $xml->vpnGroup->addresses->url1 === $effective['gateway'], 'serializes escaped VPN URL correctly');
check((string) $xml->vpnGroup->mtu === '1200', 'uses per-phone MTU');
check(strpos($xml->asXML(), '<vpnGroup>') < strpos($xml->asXML(), '<versionStamp>'), 'puts VPN profile before version stamp');
preg_match('/-----BEGIN CERTIFICATE-----\s*(.*?)\s*-----END CERTIFICATE-----/s', $pem, $matches);
$expected = base64_encode(sha1(base64_decode(preg_replace('/\s+/', '', $matches[1])), true));
check((string) $xml->vpnGroup->credentials->certHash1 === $expected, 'computes DER SHA-1 hash');

$plain = new SimpleXMLElement('<device><vpnGroup><old>yes</old></vpnGroup><versionStamp>0</versionStamp></device>');
$service->apply($plain, $other);
check(!isset($plain->vpnGroup), 'removes model-template VPN when the phone VPN option is off');

$xmlPath = $directory . '/' . $phone . '.cnf.xml';
file_put_contents($xmlPath, $xml->asXML());
$service->publish($phone, $xmlPath);
check(is_file($directory . '/ITL' . $phone . '.tlv'), 'signs an enabled VPN phone and creates its ITL');
check(is_file($xmlPath . '.sgn'), 'creates signed configuration');

$parent->setConfig('vpn_device_' . $phone, array('vpn' => 'off', 'sign' => 'inherit'));
(new vpnprovisioning($parent))->publish($phone, $xmlPath);
check(is_file($xmlPath . '.sgn'), 'automatic signing continues after VPN disabled when an ITL exists');

$parent->setConfig('vpn_device_' . $phone, array('vpn' => 'off', 'sign' => 'off'));
$blocked = false;
try {
    (new vpnprovisioning($parent))->publish($phone, $xmlPath);
} catch (RuntimeException $error) {
    $blocked = strpos($error->getMessage(), 'ITL') !== false;
}
check($blocked, 'cannot turn off signing for a phone with trusted ITL');

$parent->setConfig('vpn_device_' . $other, array('vpn' => 'off', 'sign' => 'on'));
$otherXml = $directory . '/' . $other . '.cnf.xml';
file_put_contents($otherXml, '<device/>');
(new vpnprovisioning($parent))->publish($other, $otherXml);
check(is_file($otherXml . '.sgn'), 'signing can be enabled without phone VPN');

$parent->setConfig('vpn_device_' . $other, array('vpn' => 'on', 'sign' => 'off'));
$rejected = false;
try {
    (new vpnprovisioning($parent))->publish($other, $otherXml);
} catch (RuntimeException $error) {
    $rejected = strpos($error->getMessage(), 'requires signed') !== false;
}
check($rejected, 'VPN with signing disabled is rejected');

echo "All per-device VPN/signing tests passed.\n";
