<?php
error_reporting(E_ALL);
require __DIR__ . '/../sccpManClasses/notificationDelivery.class.php';
require __DIR__ . '/../sccpManClasses/notificationWorker.class.php';
use FreePBX\modules\Sccp_manager\notificationDelivery;
use FreePBX\modules\Sccp_manager\notificationWorker;
function checkIdentity($condition,$message) { if (!$condition) throw new RuntimeException($message); }
$shared=['line'=>'9111','mailbox'=>'111','extension'=>'111'];
$normal=['line'=>'150','mailbox'=>'150','extension'=>'150'];
checkIdentity(notificationDelivery::matchesDestination($shared,'missed',['9111']),'Missed call is delivered to physical SCCP line');
checkIdentity(!notificationDelivery::matchesDestination($shared,'missed',['111']),'Missed call is not routed by mailbox');
checkIdentity(notificationDelivery::matchesDestination($shared,'voicemail',['111']),'Shared voicemail is delivered by mailbox');
checkIdentity(!notificationDelivery::matchesDestination($shared,'voicemail',['9111']),'Voicemail does not route by physical line');
checkIdentity(notificationDelivery::matchesDestination($normal,'missed',['150']),'Normal missed call still routes');
checkIdentity(notificationDelivery::matchesDestination($normal,'voicemail',['150']),'Normal voicemail still routes');
$call=['dst'=>'9111','calldate'=>'2026-10-30 13:00:00','duration'=>30,'src'=>'67'];
$recording=['id'=>'one','time'=>strtotime('2026-10-30 13:00:15'),'number'=>'67'];
checkIdentity(notificationWorker::hasRecordedVoicemail($call,['111'=>[$recording]],'111'),
    'Shared mailbox recording suppresses duplicate missed call');
checkIdentity(!notificationWorker::hasRecordedVoicemail($call,['111'=>[$recording]],'9111'),
    'A different empty mailbox does not falsely suppress missed call');
echo "Shared extension routing tests passed.\n";
