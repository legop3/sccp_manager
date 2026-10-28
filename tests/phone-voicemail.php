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
verify(!isset($orig['Application']) && !isset($orig['Data']), 'No application-mode originate: SCCP calling party predial requires extension mode');
verify($orig['Context'] === 'sccp-manager-voicemail-play' && $orig['Exten'] === 's' &&
       $orig['Priority'] === '1', 'Playback uses isolated direct SCCP channel dialplan');
verify($orig['PreDialGoSub'] === 'sccp-manager-voicemail-predial,s,1(Alicia Q,15005550124)',
    'Dynamic name and number are passed to SCCP predial before call setup');
verify($orig['Variable'] === 'SCCP_VM_MAILBOX=111,SCCP_VM_ID=1782735000-00000123',
    'Native playback selects the correct mailbox and stable message ID');
$source = file_get_contents(__DIR__ . '/../sccpManClasses/phonevoicemail.class.php');
verify(strpos($source, 'usleep(') === false && strpos($source, 'sleep(') === false,
    'Voicemail playback scheduling has no artificial timer');
verify(strpos($source, 'register_shutdown_function') !== false,
    'Playback still follows HTTP XML response completion');
verify($orig['CallerID'] === '"Alicia Q" <15005550124>', 'Dynamic caller ID preserved');
verify($orig['Async'] === 'false', 'Synchronous extension originate is required for Asterisk 22.6 PreDialGoSub');
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

$softkeysDetail = array_map('strval', iterator_to_array($xml->xpath('//SoftKeyItem/Name')));
verify(!in_array('VM Menu', $softkeysDetail, true),
    'VM Menu shortcut does not appear in individual message details');
$names = array();
foreach ($xml->MenuItem as $item) $names[] = (string)$item->Name;
verify(!in_array('Mark as Heard', $names, true),
    'Do not present a stale Mark Heard action after playback moves the voicemail');

ob_start();
$listing->invoke($vm, '111', $baseUrl, array($m));
$xml = simplexml_load_string(ob_get_clean());
verify((string)$xml->SoftKeyItem[1]->Name === 'VM Menu' &&
    (string)$xml->SoftKeyItem[1]->URL === $baseUrl . '&op=call-menu' &&
    (int)$xml->SoftKeyItem[1]->Position === 2,
    'Traditional Voicemail is a second softkey separate from recordings');
verify((string)$xml->SoftKeyItem[2]->URL === 'Init:Messages',
    'List Exit closes application in one action');
verify(count($xml->MenuItem) === 1,
    'VM Menu shortcut does not consume a voicemail list row');
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

$dialplan = file_get_contents(__DIR__ . '/../conf/voicemail-dialplan.conf');
verify(strpos($dialplan, '[sccp-manager-voicemail-predial]') !== false &&
       strpos($dialplan, 'Set(CHANNEL(CallingParty)="${ARG1}" <${ARG2}>)') !== false,
    'SCCP-specific Caller Party is set in predial, before phone receives call setup');
verify(strpos($dialplan, 'VoiceMailPlayMsg(${SCCP_VM_MAILBOX}@default,${SCCP_VM_ID})') !== false,
    'Voicemail still plays directly on SCCP audio channel without an intermediary');
verify(strpos($dialplan, 'Wait(') === false && strpos($dialplan, 'Sleep(') === false,
    'SCCP dialplan has no artificial delays');
verify(strpos($dialplan, 'same => n,Return()') !== false,
    'Predial subroutine returns before phone call is placed');
$installer = file_get_contents(__DIR__ . '/../install.php');
verify(strpos($installer, '#include sccp-manager-voicemail.conf') !== false &&
    strpos($installer, "conf/voicemail-dialplan.conf") !== false,
    'Module installer deploys and includes managed voicemail contexts on FreePBX');
verify(strpos($source, "'PreDialGoSub'=>'sccp-manager-voicemail-predial") !== false,
    'Caller ID is populated by native chan_sccp predial hook');
verify(strpos($source, "stream_set_timeout(\$stream, \$replyTimeout)") !== false,
    'Synchronous origination tolerates the configured answer timeout');
$unsafe = $m;
$unsafe['name'] = "Weird (test), \r\nName";
$fields = phonevoicemail::originateFields('111', $unsafe);
verify(strpos($fields['PreDialGoSub'], '(Weird test Name,') !== false,
    'Gosub name cannot inject comma or parentheses into Asterisk argument delimiters');
verify(!str_contains($fields['PreDialGoSub'], "\r") && !str_contains($fields['PreDialGoSub'], "\n"),
    'No AMI predial header injection');


$callMenu = new ReflectionMethod(phonevoicemail::class, 'traditionalVoicemailCall');
ob_start();
$callMenu->invoke($vm, '150');
$callXml = simplexml_load_string(ob_get_clean());
verify($callXml !== false && $callXml->getName() === 'CiscoIPPhoneExecute',
    'VM Menu returns a valid Cisco execute object');
verify(count($callXml->ExecuteItem) === 2, 'Exactly two phone-native instructions');
verify((string)$callXml->ExecuteItem[0]['URL'] === 'Init:Messages',
    'Closing the Messages application is explicitly requested');
verify((string)$callXml->ExecuteItem[1]['URL'] === 'Dial:*9900150',
    'The call is placed from the phone using its numeric mailbox, not *97');
try {
    $callMenu->invoke($vm, '150;hangup');
    throw new LogicException('Unsafe mailbox allowed into telephony URI');
} catch (InvalidArgumentException $expected) { }
$endpointSource = file_get_contents(__DIR__ . '/../sccpManClasses/phonecontent.class.php');
verify(strpos($endpointSource, "'CiscoIPPhoneExecute'") !== false,
    'Public endpoint accepts validated Execute reply instead of replacing it with an error');
verify(strpos($dialplan, '[from-internal-custom]') !== false &&
    strpos($dialplan, 'exten => _*9900.,1,NoOp(') !== false,
    'Module creates normal user-originated voicemail access extension');
verify(strpos($dialplan, 'VoiceMailMain(') !== false &&
    strpos($dialplan, '@default)') !== false,
    'Traditional voicemail menu uses caller-selected mailbox');
verify(strpos($dialplan, '@default,s)') === false,
    'Normal voicemail password checks are not skipped');

echo "Visual voicemail tests passed.\n";
