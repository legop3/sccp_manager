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
$message=new notificationMessage([
    'Listen'=>$base, 'Messages'=>'http://10.254.77.1/sccp-manager/service.php?app=messages'
],'<CiscoIPPhoneImageFile><Title>Test</Title><LocationX>0</LocationX><LocationY>0</LocationY><URL>http://127.0.0.1/test.png</URL></CiscoIPPhoneImageFile>');
assertSameValue(2,count($message->payload()['actions']),'Sender-provided actions remain independent of content');
$clearAction=new notificationMessage([
    'Listen'=>['url'=>$base,'clear'=>true],
    'Details'=>$base
],'<CiscoIPPhoneImageFile><Title>Test</Title><URL>http://127.0.0.1/test.png</URL></CiscoIPPhoneImageFile>');
assertSameValue(true,$clearAction->payload()['actions']['Listen']['clear'],'Action may opt into clear-on-activate');
assertSameValue($base,$clearAction->payload()['actions']['Details'],'Default action stays unchanged');
foreach ([
    ['url'=>$base,'clear'=>'yes'],
    ['url'=>$base,'clear'=>true,'extra'=>true],
    ['clear'=>true]
] as $invalid) {
    try {
        new notificationMessage(['Bad'=>$invalid], '<CiscoIPPhoneImageFile><URL>http://127.0.0.1/test.png</URL></CiscoIPPhoneImageFile>');
        throw new RuntimeException('Invalid action options accepted');
    } catch (InvalidArgumentException $expected) {}
}
assertSameValue(true,notificationQueue::validActionTarget($base),'Valid application URL can be activated');
assertSameValue(true,notificationQueue::validActionTarget('Dial:111'),'Valid native dial URL can be activated');
assertSameValue(false,notificationQueue::validActionTarget('javascript:alert(1)'),'Unsafe action URL rejected');
assertSameValue(false,notificationQueue::validActionTarget('file:///etc/passwd'),'Local file URL rejected');

$selfContained=new notificationMessage([], '<CiscoIPPhoneImageFile><Title>Standalone</Title><LocationX>0</LocationX><LocationY>0</LocationY><URL>http://127.0.0.1/standalone.png</URL></CiscoIPPhoneImageFile>');
assertSameValue(false, array_key_exists('url',$selfContained->payload()), 'Notification contract has no URL-only mode');
try {new notificationMessage(['A'=>'Dial:1','B'=>'Dial:2','C'=>'Dial:3','D'=>'Dial:4'], '<CiscoIPPhoneImageFile><URL>http://127.0.0.1/x.png</URL></CiscoIPPhoneImageFile>');throw new RuntimeException('Four sender actions accepted');}
catch (InvalidArgumentException $expected) {}
try {new notificationMessage([],null);throw new RuntimeException('Unsafe URL accepted');}
catch (InvalidArgumentException $expected) {}
try { new notificationMessage([], '<CiscoIPPhoneText><Title>Broken & URL</Title></CiscoIPPhoneText>'); throw new RuntimeException('Malformed XML accepted'); }
catch (InvalidArgumentException $expected) {}
$loader=file_get_contents(__DIR__.'/../Sccp_manager.class.php');
if (str_contains($loader, 'notificationMessage.class.php')) throw new RuntimeException('Value object must not be auto-constructed');
if (is_file(__DIR__.'/../sccpManClasses/notificationMessage.class.php')) throw new RuntimeException('Value object is in component scan');
try { new notificationMessage([], '<CiscoIPPhoneText><Title>Text</Title><Text>No</Text></CiscoIPPhoneText>'); throw new RuntimeException('Non-image notification accepted'); }
catch (InvalidArgumentException $expected) {}
try { new notificationMessage([],null,'alert.raw'); throw new RuntimeException('Sound-only notification accepted'); }
catch (InvalidArgumentException $expected) {}
echo "Shared notification object and link contract OK\n";
