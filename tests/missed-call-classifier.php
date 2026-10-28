<?php
require_once __DIR__.'/../sccpManClasses/notificationWorker.class.php';
use FreePBX\modules\Sccp_manager\notificationWorker;
$now=strtotime('2026-10-28 19:00:00');
function row($id,$dst,$disposition,$app='Dial',$data='SCCP/111,20',$duration=30) {
    return ['linkedid'=>$id,'uniqueid'=>$id,'src'=>'5550123','dst'=>'111',
        'clid'=>'"Example" <5550123>','calldate'=>'2026-10-28 18:58:00',
        'duration'=>$duration,'disposition'=>$disposition,'channel'=>'PJSIP/trunk-1',
        'dstchannel'=>$dst,'lastapp'=>$app,'lastdata'=>$data];
}
$cases=[
    'hangup unanswered'=>[[row('a','SCCP/111-0001','NO ANSWER')],1],
    'voicemail answered no recording'=>[[row('b','SCCP/111-0001','NO ANSWER'),row('b','','ANSWERED','VoiceMail','111@default')],1],
    'voicemail answered with recording'=>[[row('c','SCCP/111-0001','NO ANSWER'),row('c','','ANSWERED','VoiceMail','111@default',70)],1],
    'phone answered'=>[[row('d','SCCP/111-0001','ANSWERED')],0],
    'phone answered then transfer'=>[[row('e','SCCP/111-0001','ANSWERED'),row('e','PJSIP/222','ANSWERED','Dial','PJSIP/222',45)],0],
    'multiple targets'=>[[row('f','SCCP/111-0001','NO ANSWER'),row('f','SCCP/222-0001','NO ANSWER','Dial','SCCP/222,20')],2],
    'only voicemail not missed'=>[[row('g','','ANSWERED','VoiceMail','111@default')],0]
];
foreach ($cases as $name=>$case) {
    $actual=count(notificationWorker::classifyMissedCallRows($case[0],$now));
    if ($actual!==$case[1]) {fwrite(STDERR,"FAIL $name: $actual instead of {$case[1]}\n");exit(1);}
}
echo "Missed call classification tests passed\n";
