<?php
// Shared notification navigation contract regression checks.
require_once __DIR__.'/../sccpManClasses/notificationQueue.class.php';
require_once __DIR__.'/../sccpManClasses/notificationMessage.php';
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
],'<CiscoIPPhoneImageFile><Title>Test</Title><LocationX>0</LocationX><LocationY>0</LocationY><URL>http://127.0.0.1/test.png</URL></CiscoIPPhoneImageFile>');
assertSameValue(2,count($message->payload()['actions']),'Sender-provided actions remain independent of content');
$selfContained=new notificationMessage('',[], '<CiscoIPPhoneImageFile><Title>Standalone</Title><LocationX>0</LocationX><LocationY>0</LocationY><URL>http://127.0.0.1/standalone.png</URL></CiscoIPPhoneImageFile>');
assertSameValue('', $selfContained->payload()['url'], 'Self-contained notifications need no published URL');
try {new notificationMessage($base,['A'=>'Dial:1','B'=>'Dial:2','C'=>'Dial:3','D'=>'Dial:4']);throw new RuntimeException('Four sender actions accepted');}
catch (InvalidArgumentException $expected) {}
try {new notificationMessage('javascript:alert(1)');throw new RuntimeException('Unsafe URL accepted');}
catch (InvalidArgumentException $expected) {}
try { new notificationMessage('',[], '<CiscoIPPhoneText><Title>Broken & URL</Title></CiscoIPPhoneText>'); throw new RuntimeException('Malformed XML accepted'); }
catch (InvalidArgumentException $expected) {}
$loader=file_get_contents(__DIR__.'/../Sccp_manager.class.php');
if (str_contains($loader, 'notificationMessage.class.php')) throw new RuntimeException('Value object must not be auto-constructed');
if (is_file(__DIR__.'/../sccpManClasses/notificationMessage.class.php')) throw new RuntimeException('Value object is in component scan');
try { new notificationMessage('',[], '<CiscoIPPhoneText><Title>Text</Title><Text>No</Text></CiscoIPPhoneText>'); throw new RuntimeException('Non-image notification accepted'); }
catch (InvalidArgumentException $expected) {}
echo "Shared notification object and link contract OK\n";
