<?php
error_reporting(E_ALL);
require __DIR__ . '/../sccpManClasses/notificationDelivery.class.php';
require __DIR__ . '/../sccpManClasses/notificationWorker.class.php';
require __DIR__ . '/../sccpManClasses/phonevoicemail.class.php';
use FreePBX\modules\Sccp_manager\phonevoicemail;
use FreePBX\modules\Sccp_manager\notificationDelivery;
use FreePBX\modules\Sccp_manager\notificationWorker;
function checkIdentity($condition,$message) { if (!$condition) throw new RuntimeException($message); }
$shared=phonevoicemail::identityFromConfig('9111',['mailbox'=>'111@default','cid_num'=>'111']);
$normal=phonevoicemail::identityFromConfig('150',['mailbox'=>'150','cid_num'=>'150']);
$independent=phonevoicemail::identityFromConfig('825',['mailbox'=>'900@default','cid_num'=>'175']);
checkIdentity($shared['line']==='9111' && $shared['mailbox']==='111' && $shared['extension']==='111',
    'SCCP line, voicemail mailbox and presented caller ID are independent');
checkIdentity($independent['mailbox']==='900' && $independent['extension']==='175',
    'Public caller ID never comes from voicemail mailbox');
checkIdentity(phonevoicemail::identityFromConfig('150',['mailbox'=>'','cid_num'=>''])['extension']==='150',
    'Unconfigured caller ID falls back to physical line, not mailbox');

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

// Linked CDR source identity must override the SCCP hardware line name.
$now=strtotime('2026-10-30 15:30:00');
$source=[
  'uniqueid'=>'123','linkedid'=>'123','calldate'=>'2026-10-30 15:28:00',
  'duration'=>10,'channel'=>'SCCP/9111-00000001','dstchannel'=>'',
  'src'=>'111','clid'=>'"Carpet" <111>','dst'=>'150','disposition'=>'NO ANSWER',
  'lastapp'=>'Dial','lastdata'=>'SCCP/150'
];
$missed=notificationWorker::classifyMissedCallRows([$source],$now);
checkIdentity(count($missed)===1,'One unanswered SCCP destination');
checkIdentity($missed[0]['src']==='111','Keep CDR originating caller number');
checkIdentity($missed[0]['clid']==='"Carpet" <111>','Preserve presentation caller name');
$child=$source;
$child['channel']='SCCP/150-00000002';
$child['src']='150';
$child['clid']='"Recipient" <150>';
$child['lastapp']='AppDial2';
$child['lastdata']='(Outgoing Line)';
$child['dstchannel']='SCCP/150-00000002';
$multi=notificationWorker::classifyMissedCallRows([$child,$source],$now);
checkIdentity(count($multi)===1,'Linked outgoing SCCP leg yields one missed call');
checkIdentity($multi[0]['src']==='111' && $multi[0]['clid']==='"Carpet" <111>',
    'Originating Dial leg wins over destination CDR row ordering');
$presented=$source;
$presented['src']='9111'; // CDR channel identity differs from the presented number
$presented['clid']='"Carpet" <111>';
$display=notificationWorker::classifyMissedCallRows([$presented],$now);
checkIdentity(count($display)===1 && $display[0]['src']==='111',
    'Missed call uses the presented caller number rather than channel identity');
$blankSrc=$source;
$blankSrc['src']='';
$blankSrc['clid']='"Cisco 150" <150>';
$display=notificationWorker::classifyMissedCallRows([$blankSrc],$now);
checkIdentity(count($display)===1 && $display[0]['src']==='150',
    'Missed call retains extension from caller ID when CDR source is empty');
echo "Shared extension routing tests passed.\n";
