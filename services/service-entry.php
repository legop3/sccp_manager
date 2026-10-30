<?php
ini_set('display_errors','0');
$level=ob_get_level();ob_start();$code=200;
try {
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    while(ob_get_level()>$level+1)ob_end_flush();
    if(ob_get_length())ob_clean();
    require_once rtrim((string)$amp_conf['AMPWEBROOT'],'/').'/admin/modules/sccp_manager/Sccp_manager.class.php';
    $module=\FreePBX::create()->Sccp_manager;
    $module->phoneservices->handle($_GET);
} catch(\Throwable $e) {
    error_log('SCCP built-in services: '.$e->getMessage());
    $code=200;
    while(ob_get_level()>$level+1)ob_end_flush();
    ob_clean();
    echo '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneText><Title>Services</Title><Text>Service unavailable. Check server logs.</Text></CiscoIPPhoneText>';
}
while(ob_get_level()>$level+1)ob_end_flush();
$xml=ob_get_clean();
$check=new DOMDocument();
if(!is_string($xml)||!@$check->loadXML($xml,LIBXML_NONET)||
 !in_array($check->documentElement->tagName,['CiscoIPPhoneText','CiscoIPPhoneMenu','CiscoIPPhoneInput','CiscoIPPhoneExecute'],true)) {
 $xml='<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneText><Title>Services</Title><Text>Unable to display service.</Text></CiscoIPPhoneText>';
}
http_response_code($code);
header('Content-Type: text/xml; charset=UTF-8');
header('Cache-Control: no-store');
header('Expires: Thu, 01 Jan 1970 00:00:01 GMT');
header('X-Content-Type-Options: nosniff');
header('Content-Length: '.strlen($xml));
header('Connection: close');
echo $xml;
