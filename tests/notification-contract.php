<?php
// Shared notification navigation contract regression checks.
require_once __DIR__.'/../sccpManClasses/notificationQueue.class.php';
use FreePBX\modules\Sccp_manager\notificationQueue;
function assertSameValue($expected,$actual,$message) {
    if ($expected!==$actual) throw new RuntimeException($message.' ('.var_export($actual,true).')');
}
$id=str_repeat('a',24);
$base='http://10.254.77.1/sccp-manager/voicemail.php?phone=SEP00EBD5CA2776&key='.str_repeat('b',64).'&service=1&single=1&op=play&id=123';
$url=notificationQueue::contextLink($base,$id);
assertSameValue($base.'&notification_id='.$id,$url,'Voicemail context must persist');
assertSameValue($url,notificationQueue::contextLink($url,'invalid'),'Invalid ID must not modify link');
$service='http://10.254.77.1/sccp-manager/custom-app.php?x=1';
assertSameValue($service.'&notification_id='.$id,notificationQueue::contextLink($service,$id),'Future app should work');
assertSameValue('Dial:111',notificationQueue::contextLink('Dial:111',$id),'Dial must remain unchanged');
assertSameValue('https://example.org/other.php?x=1',notificationQueue::contextLink('https://example.org/other.php?x=1',$id),'External app untouched');
echo "Shared notification link contract OK\n";
