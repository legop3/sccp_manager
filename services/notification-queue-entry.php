<?php
// Authenticated, phone-facing XML queue dispatcher.
ini_set('display_errors','0');
header('Content-Type: text/xml; charset=UTF-8');
header('Cache-Control: no-store');
try {
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    $module=\FreePBX::create()->Sccp_manager;
    (new \FreePBX\modules\Sccp_manager\notificationQueue($module))->handle($_GET);
} catch (\Throwable $e) {
    error_log('SCCP notification queue endpoint: '.$e->getMessage());
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneText><Title>Notification unavailable</Title><Text>Unable to open notification.</Text><SoftKeyItem><Name>Exit</Name><URL>SoftKey:Exit</URL><Position>1</Position></SoftKeyItem></CiscoIPPhoneText>';
}
