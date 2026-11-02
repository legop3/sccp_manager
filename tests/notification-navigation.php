<?php
require_once __DIR__.'/../sccpManClasses/notificationQueue.class.php';
use FreePBX\modules\Sccp_manager\notificationNavigation;
function ensure($ok,$message) {if (!$ok) throw new RuntimeException($message);}
$nav=(new ReflectionClass(notificationNavigation::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($nav,'id'))->setValue($nav,str_repeat('a',24));
(new ReflectionProperty($nav,'returnUrl'))->setValue($nav,'http://127.0.0.1/sccp-manager/notification-queue.php?op=return&id=1');
(new ReflectionProperty($nav,'clearUrl'))->setValue($nav,'http://127.0.0.1/sccp-manager/notification-queue.php?op=dismiss&id=1');
(new ReflectionProperty($nav,'entry'))->setValue($nav,true);
ensure($nav->active(),'Linked application should be active');
$keys=$nav->softkeys('menu');
ensure(count($keys)===2 && $keys[0][0]==='Clear' && $keys[0][2]===1,'Entry Clear first');
ensure($keys[1][0]==='Select','Entry retains Select');
foreach (['text','menu','input'] as $type) {
    $xml='<CiscoIPPhoneMenu><Title>Test</Title>'.notificationNavigation::xmlSoftkeys($nav->softkeys($type)).'</CiscoIPPhoneMenu>';
    ensure((new DOMDocument())->loadXML($xml,LIBXML_NONET),'Valid entry XML '.$type);
}
(new ReflectionProperty($nav,'entry'))->setValue($nav,false);
foreach (['text','menu','input'] as $type) {
    $keys=$nav->softkeys($type);
    $last=end($keys);
    ensure($last[0]==='Back' && $last[2]===4,'Child page returns to same notification: '.$type);
    ensure(count($keys)<=4,'Maximum softkey count '.$type);
}
$link='http://127.0.0.1/sccp-manager/example.php?item=1';
ensure(str_contains($nav->link($link),'notification_id='.str_repeat('a',24)),'Generic app receives context');
ensure($nav->link($nav->link($link))===$nav->link($link),'Context is idempotent');
ensure($nav->fields()['notification_id']===str_repeat('a',24),'Child link query fields');
echo "Shared notification navigation passed\n";
