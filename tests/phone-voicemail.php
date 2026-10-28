<?php
error_reporting(E_ALL);
require __DIR__ . '/../sccpManClasses/phonevoicemail.class.php';
use FreePBX\modules\Sccp_manager\phonevoicemail;
function verify($ok, $message) { if (!$ok) throw new RuntimeException($message); }

$raw = "[message]\norigtime=1782735000\ncallerid=\"Alicia Q\" <15005550124>\nduration=47\nmsg_id=1782735000-00000123\n";
$m = phonevoicemail::parseMessage($raw, 'INBOX', 'msg0002.txt');
verify($m !== null, 'Message parsing');
verify($m['name']==='Alicia Q', 'Caller name');
verify($m['number']==='15005550124', 'Caller number');
verify($m['duration']===47 && $m['folder']==='INBOX', 'Duration and folder');
verify(phonevoicemail::parseMessage($raw, 'INBOX', '../foo') === null, 'Invalid metadata filename');

$orig = phonevoicemail::originateFields('111', $m);
verify($orig['Channel'] === 'SCCP/111/aa=1w', 'Muted auto-answer');
verify($orig['Data'] === 'sccp-manager-vm-playback.agi,111,1782735000-00000123', 'Playback selects stable Asterisk message ID');
verify($orig['Application'] === 'AGI', 'Asterisk invokes post-answer playback AGI');
verify($orig['Data'] === 'sccp-manager-vm-playback.agi,111,1782735000-00000123',
    'AGI selects voicemail by stable ID and adds post-answer delay');
verify($orig['CallerID'] === '"Alicia Q" <15005550124>', 'Dynamic caller ID preserved');
verify($orig['Async'] === 'true', 'Async originate');
$m['name'] = "Evil\r\nAction: Hangup";
verify(strpos(phonevoicemail::originateFields('111',$m)['CallerID'],"\n")===false, 'No AMI field injection');
try {
    phonevoicemail::originateFields('111/../../../', $m);
    throw new RuntimeException('Invalid SCCP device line accepted');
} catch (InvalidArgumentException $expected) { }
verify(phonevoicemail::validDevice('SEP00AABBCCDDEE')===true, 'Valid phone ID');
verify(!phonevoicemail::validDevice('SEP00AABBCCDDEE/../'), 'Unsafe device ID');
$relocated = $m;
$relocated['folder'] = 'Old';
verify(phonevoicemail::resolveMessage(array($relocated),'1782735000-00000123')['folder'] === 'Old',
    'Previously new voicemail remains accessible after Asterisk moves it to Old');
try {
    phonevoicemail::resolveMessage(array($relocated), 'nonexistent-id');
    throw new RuntimeException('Expected missing-message exception');
} catch (RuntimeException $expected) {
    verify(strpos($expected->getMessage(),'Message no longer exists') !== false,
        'Missing voicemail is reported safely');
}
$agiScript = file_get_contents(__DIR__ . '/../scripts/sccp-manager-vm-playback.agi');
verify(strpos($agiScript,"vmAgiExec('Wait', '2')") !== false,
    'Playback AGI waits for SCCP RTP after auto-answer');
verify(strpos($agiScript,"vmAgiExec('VoiceMailPlayMsg'") !== false,
    'Playback AGI invokes Asterisk voicemail playback');

echo "Visual voicemail tests passed.\n";
