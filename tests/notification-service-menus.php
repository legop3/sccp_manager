<?php
require_once __DIR__.'/../sccpManClasses/phoneservices.class.php';
use FreePBX\modules\Sccp_manager\phoneservices;
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$s=new phoneservices();
foreach (['notificationReturn'=>'http://127.0.0.1/notification-queue.php?id=1&op=return',
          'notificationClear'=>'http://127.0.0.1/notification-queue.php?id=1&op=dismiss',
          'notificationEntry'=>true] as $name=>$value)
    (new ReflectionProperty(phoneservices::class,$name))->setValue($s,$value);
foreach (['xml'=>['Message','A text message'],
          'menu'=>['Messages',[['Inbox','http://127.0.0.1/service.php?x=1']]],
          'form'=>['Input','http://127.0.0.1/service.php?x=1','Text','text']] as $kind=>$args) {
    $method=new ReflectionMethod(phoneservices::class,$kind);
    ob_start();$method->invokeArgs($s,$args);$raw=ob_get_clean();
    $dom=new DOMDocument();
    check(@$dom->loadXML($raw,LIBXML_NONET),'Valid notification-linked '.$kind.' XML');
    $softkeys=$dom->getElementsByTagName('SoftKeyItem');
    check($softkeys->length<=4,'No more than four softkeys in '.$kind);
    $name=$softkeys->item(0)->getElementsByTagName('Name')->item(0)->textContent;
    check($name==='Clear','Clear is first for '.$kind);
}
echo "Notification-linked service pages OK\n";
