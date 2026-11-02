<?php
require_once __DIR__.'/../sccpManClasses/phoneservices.class.php';
use FreePBX\modules\Sccp_manager\phoneservices;
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
require_once __DIR__.'/../sccpManClasses/notificationNavigation.php';
$nav=(new ReflectionClass(\FreePBX\modules\Sccp_manager\notificationNavigation::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($nav,'returnUrl'))->setValue($nav,'http://127.0.0.1/notification-queue.php?id=1&op=return');
(new ReflectionProperty($nav,'clearUrl'))->setValue($nav,'http://127.0.0.1/notification-queue.php?id=1&op=dismiss');

foreach (['xml'=>['Message','A text message'],
          'menu'=>['Messages',[['Inbox','http://127.0.0.1/service.php?x=1']]],
          'form'=>['Input','http://127.0.0.1/service.php?x=1','Text','text']] as $kind=>$args) {
    $method=new ReflectionMethod(phoneservices::class,$kind);
    ob_start();$method->invokeArgs($s,$args);$raw=ob_get_clean();
    $dom=new DOMDocument();
    check(@$dom->loadXML($raw,LIBXML_NONET),'Valid child '.$kind.' XML');
    $keys=$dom->getElementsByTagName('SoftKeyItem');
    $last=$keys->item($keys->length-1);
    check($last->getElementsByTagName('Name')->item(0)->textContent==='Back',
        'Child '.$kind.' must return to notification instead of exit');
}
echo "Notification-linked service pages OK\n";
