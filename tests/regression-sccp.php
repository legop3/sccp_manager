<?php
/**
 * Regression tests for two previously manually patched SCCP Manager crashes:
 * undefined realm in AMI realtime status; missing ip in CallManager/SRST rows.
 * No live Asterisk/FreePBX server required.
 */
require_once __DIR__ . '/../sccpManTraits/helperFunctions.php';
require_once __DIR__ . '/../sccpManClasses/aminterface.class.php';
require_once __DIR__ . '/../sccpManClasses/xmlinterface.class.php';

set_error_handler(function ($severity, $message) {
    throw new ErrorException($message, 0, $severity);
});

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

$ami = \FreePBX\modules\Sccp_manager\aminterface::parseRealTimeStatusOutput(array(
    'mysql Connected to database admin@localhost',
    'mysql2 Database backend unavailable',
    'mysql3 disconnected from admin@192.0.2.1',
    'mysql4 not connected to database',
    'mysql5 connected at first character'
));
check($ami['mysql']['status'] === 'OK', 'connected realtime backend is OK');
check($ami['mysql']['realm'] === 'admin@localhost', 'realm is parsed');
check($ami['mysql2']['status'] === 'ERROR', 'unavailable backend is ERROR');
check($ami['mysql2']['realm'] === '', 'absent realm is empty rather than undefined');
check($ami['mysql3']['status'] === 'ERROR', 'disconnected does not count as connected');
check($ami['mysql3']['realm'] === 'admin@192.0.2.1', 'realm is scoped to its row');
check($ami['mysql4']['status'] === 'ERROR', 'not connected does not count as connected');
check($ami['mysql5']['status'] === 'OK', 'connected matches at any position');

$instance = (new ReflectionClass(\FreePBX\modules\Sccp_manager\xmlinterface::class))
    ->newInstanceWithoutConstructor();
$method = new ReflectionMethod($instance, 'get_server_sccp_bind');
$settings = array(
    'bindaddr' => '0.0.0.0',
    'ccm_address' => 'internal;192.168.0.25:2000;garbage;1.2.3.4/24',
    'server_if_list' => array(array('ip' => '192.168.0.99')),
    'port' => '2000',
    'externhost' => '',
    'externip' => ''
);
$result = $method->invoke($instance, $settings);
check(count($result) === 1, 'malformed server address rows skipped');
check(isset($result['192.168.0.25']), 'valid explicit SCCP address kept');
check($result['192.168.0.25']['port'] === '2000', 'explicit SCCP port retained');
$settings['ccm_address'] = 'internal;garbage;192.168.0.0/24';
$result = $method->invoke($instance, $settings);
check(isset($result['192.168.0.99']), 'invalid explicit list falls back to available local interface');
echo "All SCCP Manager regression tests passed.\n";
