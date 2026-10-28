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
verify($orig['Data'] === '111@default,1782735000-00000123', 'Native playback selects stable Asterisk message ID');
verify($orig['Application'] === 'VoiceMailPlayMsg', 'Asterisk plays voicemail directly with no extra wait');
$source = file_get_contents(__DIR__ . '/../sccpManClasses/phonevoicemail.class.php');
verify(strpos($source, 'usleep(') === false && strpos($source, 'sleep(') === false,
    'Voicemail playback scheduling has no artificial timer');
verify(strpos($source, 'register_shutdown_function') !== false,
    'Playback still follows HTTP XML response completion');
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

$vm = new phonevoicemail();
$baseUrl = 'http://192.0.2.10/sccp-manager/voicemail.php?phone=SEP00AABBCCDDEE&key=abc';
$detail = new ReflectionMethod(phonevoicemail::class, 'showMessageDetail');
$listing = new ReflectionMethod(phonevoicemail::class, 'showMessageList');

ob_start();
$detail->invoke($vm, $baseUrl, $relocated, false);
$xml = simplexml_load_string(ob_get_clean());
verify($xml !== false, 'Voicemail detail must contain valid XML');
verify((string)$xml->MenuItem[0]->Name === 'Play (47 sec)', 'Detail retains Play action');
verify((string)$xml->SoftKeyItem[0]->URL === 'SoftKey:Select',
    'Custom softkeys preserve menu Select');
$softkeys = array();
foreach ($xml->SoftKeyItem as $softkey) {
    $softkeys[(string)$softkey->Name] = array((string)$softkey->URL, (int)$softkey->Position);
}
verify(($softkeys['Back'] ?? null) === array($baseUrl, 3),
    'Back returns directly to list without relying on phone history');
verify(($softkeys['Exit'] ?? null) === array('Init:Messages', 4),
    'Exit closes Messages rather than traversing handset history');
verify(strpos($xml->asXML(), 'SoftKey:Exit') === false,
    'Never use native history-walking Exit');

ob_start();
$detail->invoke($vm, $baseUrl, $m, true);
$xml = simplexml_load_string(ob_get_clean());
verify((string)$xml->MenuItem[0]->Name === 'Play Again (47 sec)',
    'After playback, remain on selected message details');
verify((string)$xml->MenuItem[0]->URL === $baseUrl . '&op=play&id=1782735000-00000123',
    'Replay points to same stable voicemail ID');
// A separate 'Delete Message?' Cisco menu failed with XML Error 4 on CP-7945G.
// Confirmation now reuses the verified detail menu template, changing only
// the actions. No voicemail can be removed on the first Delete press.
ob_start();
$detail->invoke($vm, $baseUrl, $relocated, false, true);
$deleteXml = simplexml_load_string(ob_get_clean());
verify($deleteXml !== false, 'Delete confirmation must be valid XML');
verify((string)$deleteXml->getName() === 'CiscoIPPhoneMenu',
    'Delete confirmation uses the known-working CiscoIPPhoneMenu object');
verify((string)$deleteXml->Title === (string)$xml->Title &&
    (string)$deleteXml->Prompt === (string)$xml->Prompt,
    'Delete confirmation retains the same title and prompt as normal message detail');
verify((string)$deleteXml->MenuItem[0]->Name === 'CONFIRM DELETE',
    'Deleting requires an explicit second confirmation');
verify((string)$deleteXml->MenuItem[0]->URL ===
    $baseUrl . '&op=confirm-delete&id=1782735000-00000123',
    'Confirmation link targets the same stable voicemail ID');
verify((string)$deleteXml->MenuItem[1]->Name === 'Cancel' &&
    (string)$deleteXml->MenuItem[1]->URL ===
    $baseUrl . '&op=detail&id=1782735000-00000123',
    'Deletion can be cancelled without changing the mailbox');
verify((string)$deleteXml->SoftKeyItem[0]->URL === 'SoftKey:Select',
    'Delete confirmation restores Cisco Select softkey');
verify(strpos($deleteXml->asXML(), 'Delete Message?') === false,
    'Avoid the failing standalone Delete Message confirmation XML page');

$names = array();
foreach ($xml->MenuItem as $item) $names[] = (string)$item->Name;
verify(!in_array('Mark as Heard', $names, true),
    'Do not present a stale Mark Heard action after playback moves the voicemail');

ob_start();
$listing->invoke($vm, '111', $baseUrl, array($m));
$xml = simplexml_load_string(ob_get_clean());
verify((string)$xml->SoftKeyItem[1]->URL === 'Init:Messages',
    'List Exit closes application in one action');
verify(strpos((string)$xml->MenuItem[0]->URL, '&folder=') === false,
    'Listing links use stable message ID only');

$phoneContentSource = file_get_contents(__DIR__ . '/../sccpManClasses/phonecontent.class.php');
verify(strpos($phoneContentSource, 'Expires: Thu, 01 Jan 1970 00:00:01 GMT') !== false,
    'Voicemail HTTP endpoint explicitly prevents Cisco URL history stacking');

// Asterisk 22 returns Response: Success even for failed voicemail mutations.
$mutations = array(
    array('VoicemailRemove','Message remove successful'),
    array('VoicemailMove','Message move successful')
);
foreach ($mutations as $mutation) {
    phonevoicemail::requireMutationSuccess($mutation[0], array(
        'Response'=>'Success', 'Message'=>$mutation[1] . "\n"));
    foreach (array('Response'=>'Error', 'Message'=>str_replace('successful','failed',$mutation[1])) as $key=>$badValue) {
        $reply = array('Response'=>'Success', 'Message'=>$mutation[1]);
        $reply[$key] = $badValue;
        try {
            phonevoicemail::requireMutationSuccess($mutation[0], $reply);
            throw new LogicException('Incorrectly accepted a failed voicemail AMI operation');
        } catch (RuntimeException $expected) {
            verify(strpos($expected->getMessage(),'failed:') !== false,
                'Voicemail mutation errors must be visible to the menu');
        }
    }
}
try {
    phonevoicemail::requireMutationSuccess('Originate', array('Response'=>'Success'));
    throw new LogicException('Unsupported voicemail mutation was accepted');
} catch (InvalidArgumentException $expected) { }

echo "Visual voicemail tests passed.\n";
