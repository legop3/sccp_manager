<?php
// Shared notification navigation contract regression checks.
require_once __DIR__.'/../sccpManClasses/notificationQueue.class.php';
require_once __DIR__.'/../sccpManClasses/notificationMessage.class.php';
use FreePBX\modules\Sccp_manager\notificationQueue;
use FreePBX\modules\Sccp_manager\notificationMessage;
function assertSameValue($expected,$actual,$message) {
    if ($expected!==$actual) throw new RuntimeException($message.' ('.var_export($actual,true).')');
}
$id=str_repeat('a',24);
$base='http://10.254.77.1/sccp-manager/voicemail.php?phone=SEP00EBD5CA2776&key='.str_repeat('b',64).'&service=1&single=1&op=play&id=123';
$url=notificationQueue::contextLink($base,$id);
assertSameValue($base.'&notification_id='.$id,$url,'Voicemail context must persist');
assertSameValue($url,notificationQueue::contextLink($url,'invalid'),'Invalid ID must not modify link');
assertSameValue($url,notificationQueue::contextLink($url,$id),'Repeated context must be idempotent');
$service='http://10.254.77.1/sccp-manager/custom-app.php?x=1';
assertSameValue($service.'&notification_id='.$id,notificationQueue::contextLink($service,$id),'Future app should work');
assertSameValue('Dial:111',notificationQueue::contextLink('Dial:111',$id),'Dial must remain unchanged');
assertSameValue('https://example.org/other.php?x=1',notificationQueue::contextLink('https://example.org/other.php?x=1',$id),'External app untouched');
$message=new notificationMessage('http://10.254.77.1/sccp-manager/notification-display.php?id='.str_repeat('a',24),[
    'Listen'=>$base, 'Messages'=>'http://10.254.77.1/sccp-manager/service.php?app=messages'
],'<CiscoIPPhoneText><Title>Test</Title><Text>Message</Text></CiscoIPPhoneText>');
assertSameValue(2,count($message->payload()['actions']),'Sender-provided actions remain independent of content');
try {new notificationMessage($base,['A'=>'Dial:1','B'=>'Dial:2','C'=>'Dial:3','D'=>'Dial:4']);throw new RuntimeException('Four sender actions accepted');}
catch (InvalidArgumentException $expected) {}
try {new notificationMessage('javascript:alert(1)');throw new RuntimeException('Unsafe URL accepted');}
catch (InvalidArgumentException $expected) {}
echo "Shared notification object and link contract OK\n";
