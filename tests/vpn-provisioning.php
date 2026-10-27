<?php

require_once __DIR__ . '/../sccpManClasses/vpnprovisioning.class.php';

use FreePBX\modules\Sccp_manager\vpnprovisioning;

function check($ok, $description)
{
    if (!$ok) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    echo "PASS: {$description}\n";
}

$directory = sys_get_temp_dir() . '/sccp-vpn-test-' . bin2hex(random_bytes(6));
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

// Stubs create output files while checking that the utilities are invoked
// with argv, not through shell interpolation.
$tlv = $directory . '/tlvfile';
$sgn = $directory . '/sgnfile';
file_put_contents($tlv, "#!/bin/sh\n[ \"\$1\" = \"--build\" ] || exit 3\ntouch \"\$2\"\n");
file_put_contents($sgn, "#!/bin/sh\n[ \"\$1\" = \"--build\" ] || exit 3\ntouch \"\$2.sgn\"\n");
chmod($tlv, 0700);
chmod($sgn, 0700);

$settings = array(
    'enabled' => true,
    'devices' => array('SEP00EBD5CA5557'),
    'gateway' => 'https://vpn.example.test:4443/svc?key=1&site=2',
    'server_certificate' => $directory . '/server.pem',
    'tftp_certificate' => $directory . '/tftp.pem',
    'sast_certificate' => $directory . '/sast.pem',
    'sgnfile' => $sgn,
    'tlvfile' => $tlv
);
$configPath = $directory . '/config.json';
file_put_contents($configPath, json_encode($settings, JSON_THROW_ON_ERROR));

$service = new vpnprovisioning(null, $configPath);
$xml = new SimpleXMLElement('<device><vpnGroup><old>yes</old></vpnGroup><versionStamp>0</versionStamp></device>');
$service->apply($xml, 'SEP00EBD5CA5557');
check(count($xml->vpnGroup) === 1, 'replaces an old VPN group without duplication');
check((string) $xml->vpnGroup->addresses->url1 === $settings['gateway'], 'retains URL with XML special characters');
check((string) $xml->vpnGroup->autoNetDetect === '0', 'forces VPN for testing');
check((string) $xml->vpnGroup->authMethod === '0', 'uses username/password authentication');
check(strpos($xml->asXML(), '<vpnGroup>') < strpos($xml->asXML(), '<versionStamp>'), 'preserves VPN element ordering');
preg_match('/-----BEGIN CERTIFICATE-----\s*(.*?)\s*-----END CERTIFICATE-----/s', $pem, $matches);
$expected = base64_encode(sha1(base64_decode(preg_replace('/\s+/', '', $matches[1])), true));
check((string) $xml->vpnGroup->credentials->certHash1 === $expected, 'computes the certificate hash from DER');

$plain = new SimpleXMLElement('<device><vpnGroup><old>yes</old></vpnGroup><versionStamp>0</versionStamp></device>');
$service->apply($plain, 'SEPAAAAAAAAAAAA');
check(!isset($plain->vpnGroup), 'non-selected phones do not inherit the model VPN profile');

$xmlPath = $directory . '/SEP00EBD5CA5557.cnf.xml';
file_put_contents($xmlPath, $xml->asXML());
$service->publish('SEP00EBD5CA5557', $xmlPath);
check(is_file($directory . '/ITLSEP00EBD5CA5557.tlv'), 'creates a phone-specific ITL');
check(is_file($xmlPath . '.sgn'), 'creates the signed configuration');

$settings['devices'] = array('SEPAAAAAAAAAAAA');
file_put_contents($configPath, json_encode($settings));
(new vpnprovisioning(null, $configPath))->publish('SEP00EBD5CA5557', $xmlPath);
check(is_file($xmlPath . '.sgn'), 'continues signing a phone that already has an ITL');

$settings['enabled'] = false;
file_put_contents($configPath, json_encode($settings));
$inactive = new vpnprovisioning(null, $configPath);
$unchanged = new SimpleXMLElement('<device><vpnGroup><old>yes</old></vpnGroup></device>');
$inactive->apply($unchanged, 'SEP00EBD5CA5557');
check((string) $unchanged->vpnGroup->old === 'yes', 'disabled integration does not alter existing templates');

echo "All VPN provisioning tests passed.\n";
