<?php
// Run: php tests/notification-addresses.php
require_once __DIR__.'/../sccpManClasses/notificationDelivery.class.php';
class NotificationAddressFixture {
    public function baseUrl($device) {
        if ($device === 'SEP00EBD5CA227A') return 'http://192.168.0.25/sccp-manager';
        if ($device === 'SEP00EBD5CA2776') return 'http://10.254.77.1/sccp-manager';
        throw new RuntimeException('Unexpected device identifier');
    }
}
class NotificationModuleFixture {
    public $phonecontent;
    public function __construct() { $this->phonecontent = new NotificationAddressFixture(); }
}
$delivery = new \FreePBX\modules\Sccp_manager\notificationDelivery(new NotificationModuleFixture());
$method = new ReflectionMethod($delivery, 'displayUrl');
$method->setAccessible(true);
$id = '0123456789abcdef01234567';
foreach ([
    'SEP00EBD5CA227A' => 'http://192.168.0.25/sccp-manager',
    'SEP00EBD5CA2776' => 'http://10.254.77.1/sccp-manager'
] as $device => $base) {
    $actual = $method->invoke($delivery, $device, $id);
    if ($actual !== $base.'/notification-display.php?id='.$id) throw new RuntimeException('Wrong notification URL for '.$device);
}
if ($method->invoke($delivery, 'SEP00EBD5CA2776', null) !== null) throw new RuntimeException('Null document must have no URL');
$source = file_get_contents(__DIR__.'/../sccpManClasses/notificationDelivery.class.php');
if (substr_count($source, 'displayUrl($device,$this->publicDocument($graphic))') !== 2)
    throw new RuntimeException('Both missed-call and voicemail branches must use the same URL resolver');
echo "Notification address tests passed\n";
