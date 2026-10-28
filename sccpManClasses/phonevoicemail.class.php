<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Read-only mailbox browsing + playback through authenticated Cisco XML.
 * All devices share the same application code, but get distinct bearer URLs
 * in signed SEP configs. No mailbox/extension can be supplied by the handset.
 */
class phonevoicemail
{
    private $parent;
    public function __construct($parent = null) { $this->parent = $parent; }

    public static function validDevice($id) {
        return is_string($id) && preg_match('/^SEP[0-9A-F]{12}$/D', $id);
    }

    /** The default SCCP line is the mailbox for this physical handset. */
    public function mailboxForDevice($device)
    {
        if (!self::validDevice($device)) throw new \InvalidArgumentException('Invalid phone identifier.');
        $data = $this->parent->dbinterface->getSccpDeviceTableData('getDefaultLine', array('id' => $device));
        $line = is_array($data) ? (string) ($data['name'] ?? '') : '';
        // Not arbitrary dial strings: only numeric SCCP lines are supported.
        if (!preg_match('/^[0-9]{1,16}$/D', $line)) {
            throw new \RuntimeException('Phone ' . $device . ' needs a numeric default SCCP line before visual voicemail can be enabled.');
        }
        return $line;
    }

    public function tokenForDevice($device)
    {
        if (!self::validDevice($device)) throw new \InvalidArgumentException('Invalid phone identifier.');
        $key = 'phonecontent_vm_token_' . $device;
        $saved = $this->parent->getConfig($key);
        if (is_string($saved) && preg_match('/^[a-f0-9]{64}$/D', $saved)) return $saved;
        $token = bin2hex(random_bytes(32));
        $this->parent->setConfig($key, $token);
        return $token;
    }

    public function serviceUrl($device)
    {
        $this->mailboxForDevice($device);
        $base = $this->parent->phonecontent->baseUrl();
        if (!$base) throw new \RuntimeException('Set the Phone Content PBX address first.');
        return $base . '/voicemail.php?phone=' . $device . '&key=' . $this->tokenForDevice($device);
    }

    private function authenticate(array $query)
    {
        $device = $query['phone'] ?? null;
        $token = $query['key'] ?? null;
        if (!self::validDevice($device) || !is_string($token) ||
            !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \RuntimeException('Invalid voicemail link.');
        }
        $saved = $this->parent->getConfig('phonecontent_vm_token_' . $device);
        if (!is_string($saved) || !hash_equals($saved, $token)) {
            throw new \RuntimeException('Voicemail link is not authorized.');
        }
        $settings = $this->parent->phonemenus->config();
        if (empty($settings['enabled']) ||
            ($settings['messages'][0]['type'] ?? '') !== 'visualvoicemail') {
            throw new \RuntimeException('Visual voicemail is disabled.');
        }
        return array($device, $this->mailboxForDevice($device), $token);
    }

    public static function parseMessage($raw, $folder, $file)
    {
        if (!preg_match('/^msg[0-9]{4}\.txt$/D', $file)) return null;
        $values = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (preg_match('/^([a-z_]+)=(.*)$/', $line, $m)) $values[$m[1]] = trim($m[2]);
        }
        $id = (string) ($values['msg_id'] ?? $values['msgid'] ?? '');
        // Asterisk generates opaque message IDs; refuse legacy messages with no
        // stable ID instead of accidentally playing the wrong message number.
        if (!preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $id)) return null;
        $cid = trim((string) ($values['callerid'] ?? ''));
        $number = '';
        $name = '';
        if (preg_match('/^(.*?)\s*<([0-9+*# -]{1,40})>$/', $cid, $m)) {
            $name = trim($m[1], ' "');
            $number = trim($m[2]);
        } elseif (preg_match('/^[0-9+*# -]{1,40}$/D', $cid)) {
            $number = $cid;
        } else {
            $name = trim($cid, ' "');
        }
        $name = preg_replace('/[\x00-\x1F\x7F<>"\\\\]/', '', $name);
        $number = preg_replace('/[^0-9+*#]/', '', $number);
        $time = isset($values['origtime']) && ctype_digit($values['origtime']) ? (int)$values['origtime'] : 0;
        $duration = isset($values['duration']) && ctype_digit($values['duration']) ? (int)$values['duration'] : 0;
        return array(
            'id' => $id, 'folder' => $folder, 'file' => $file,
            'name' => $name, 'number' => $number,
            'time' => $time, 'duration' => $duration
        );
    }

    public function listMessages($mailbox)
    {
        if (!preg_match('/^[0-9]{1,16}$/D', $mailbox)) throw new \InvalidArgumentException('Invalid mailbox.');
        $base = '/var/spool/asterisk/voicemail/default/' . $mailbox;
        $items = array();
        foreach (array('INBOX','Old') as $folder) {
            $dir = $base . '/' . $folder;
            if (!is_dir($dir) || is_link($dir)) continue;
            foreach (glob($dir . '/msg[0-9][0-9][0-9][0-9].txt') ?: array() as $path) {
                if (!is_file($path) || is_link($path)) continue;
                $raw = @file_get_contents($path, false, null, 0, 8192);
                if ($raw === false) continue;
                $msg = self::parseMessage($raw, $folder, basename($path));
                if ($msg) $items[] = $msg;
            }
        }
        usort($items, function ($a, $b) {
            return $b['time'] <=> $a['time'];
        });
        return array_slice($items, 0, 100);
    }

    private function messageById($mailbox, $id, $folder)
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $id) ||
            !in_array($folder, array('INBOX','Old'), true)) {
            throw new \InvalidArgumentException('Invalid message.');
        }
        foreach ($this->listMessages($mailbox) as $m) {
            if ($m['id'] === $id && $m['folder'] === $folder) return $m;
        }
        throw new \RuntimeException('Message no longer exists. Refresh voicemail.');
    }

    /** AMI is accessed only on loopback with FreePBX credentials. */
    private function ami(array $fields, $list = false)
    {
        global $amp_conf;
        $username = (string)($amp_conf['AMPMGRUSER'] ?? '');
        $password = (string)($amp_conf['AMPMGRPASS'] ?? '');
        if ($username === '' || $password === '') throw new \RuntimeException('FreePBX AMI credentials unavailable.');
        $errno = 0; $error = '';
        $stream = @stream_socket_client('tcp://127.0.0.1:5038', $errno, $error, 3);
        if (!$stream) throw new \RuntimeException('Asterisk Manager is unreachable.');
        stream_set_timeout($stream, 4);
        try {
            fgets($stream, 2048); // AMI banner
            $this->sendAmi($stream, array('Action'=>'Login','Username'=>$username,'Secret'=>$password,'Events'=>'off'));
            $login = $this->readAmi($stream);
            if (strtolower($login['Response'] ?? '') !== 'success') {
                throw new \RuntimeException('Asterisk Manager login failed.');
            }
            $this->sendAmi($stream, $fields);
            $reply = $this->readAmi($stream, $list);
            $this->sendAmi($stream, array('Action'=>'Logoff'));
            if (strtolower($reply['Response'] ?? '') !== 'success') {
                throw new \RuntimeException('Asterisk rejected ' . $fields['Action'] . ': ' . ($reply['Message'] ?? 'unknown error'));
            }
            return $reply;
        } finally {
            fclose($stream);
        }
    }

    private function sendAmi($stream, array $fields)
    {
        $payload = '';
        foreach ($fields as $key => $value) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $key) ||
                !is_scalar($value) || preg_match('/[\r\n\x00]/', (string) $value)) {
                throw new \RuntimeException('Invalid Asterisk Manager parameter.');
            }
            $payload .= $key . ': ' . $value . "\r\n";
        }
        if (fwrite($stream, $payload . "\r\n") === false) {
            throw new \RuntimeException('AMI request failed.');
        }
    }

    private function readAmi($stream, $list = false)
    {
        $records = array();
        $one = array();
        $completed = false;
        $deadline = microtime(true) + 4;
        while (microtime(true) < $deadline && !feof($stream)) {
            $line = fgets($stream, 65536);
            if ($line === false) break;
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if (!$one) continue;
                $records[] = $one; $one = array();
                if (!$list) break;
                $last = end($records);
                if (strtolower($last['EventList'] ?? '') === 'complete' ||
                    in_array($last['Event'] ?? '', array('CoreShowChannelsComplete','DeviceStateListComplete'),true)) {
                    $completed = true;
                    break;
                }
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) $one[substr($line,0,$colon)] = ltrim(substr($line,$colon+1));
        }
        if (!$records) throw new \RuntimeException('Asterisk Manager did not respond.');
        if ($list && !$completed) throw new \RuntimeException('Cannot confirm phone is idle; refused to interrupt a possible call.');
        $first = $records[0];
        if ($list) $first['__events'] = array_slice($records,1);
        return $first;
    }

    private function assertNotOnCall($line)
    {
        $r = $this->ami(array('Action'=>'CoreShowChannels'), true);
        foreach ($r['__events'] as $event) {
            if (($event['Event'] ?? '') !== 'CoreShowChannel') continue;
            if (preg_match('/^SCCP\/' . preg_quote($line, '/') . '(?:-|$|\/)/i', $event['Channel'] ?? '')) {
                throw new \RuntimeException('Phone is already on a call. Finish the call before playing voicemail.');
            }
        }
    }

    public static function originateFields($line, array $message)
    {
        if (!preg_match('/^[0-9]{1,16}$/D', $line) ||
            !preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $message['id'] ?? '')) {
            throw new \InvalidArgumentException('Invalid voicemail playback target.');
        }
        // Caller ID comes from the actual voicemail, not the mailbox owner.
        $display = $message['name'] !== '' ? $message['name'] :
            ($message['number'] !== '' ? $message['number'] : 'Voicemail');
        $cidName = substr(preg_replace('/[^A-Za-z0-9 ._()+-]/', '', $display), 0, 48);
        if ($cidName === '') $cidName = 'Voicemail';
        $cidNum = preg_replace('/[^0-9]/', '', $message['number']);
        if ($cidNum === '') $cidNum = $line;
        return array(
            'Action'=>'Originate',
            'Channel'=>'SCCP/' . $line . '/aa=1w',
            'Application'=>'VoiceMailPlayMsg',
            'Data'=>$line . '@default,' . $message['id'],
            'CallerID'=>'"' . $cidName . '" <' . $cidNum . '>',
            'Timeout'=>'10000',
            'Async'=>'true'
        );
    }

    public function playback($line, array $message)
    {
        $this->assertNotOnCall($line);
        $this->ami(self::originateFields($line, $message));
    }

    private function xml($title, $prompt, array $entries = array())
    {
        $doc = new \DOMDocument('1.0','UTF-8');
        $root = $doc->appendChild($doc->createElement('CiscoIPPhoneMenu'));
        foreach (array('Title'=>$title,'Prompt'=>$prompt) as $key=>$val) {
            $root->appendChild($doc->createElement($key))->appendChild($doc->createTextNode($val));
        }
        foreach ($entries as $entry) {
            $item = $root->appendChild($doc->createElement('MenuItem'));
            $item->appendChild($doc->createElement('Name'))->appendChild($doc->createTextNode($entry[0]));
            $item->appendChild($doc->createElement('URL'))->appendChild($doc->createTextNode($entry[1]));
        }
        header('Content-Type: text/xml; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $doc->saveXML();
    }

    public function handle(array $get)
    {
        try {
            list($device, $line, $token) = $this->authenticate($get);
            $base = $this->serviceUrl($device);
            $op = (string)($get['op'] ?? 'list');
            if (!in_array($op, array('list','detail','play','mark','delete','confirm-delete'), true)) throw new \InvalidArgumentException('Unknown voicemail operation.');
            $messages = $this->listMessages($line);
            if ($op === 'list') {
                $links = array();
                foreach ($messages as $m) {
                    $from = $m['name'] !== '' ? $m['name'] : ($m['number'] !== '' ? $m['number'] : 'Unknown Caller');
                    $label = ($m['folder'] === 'INBOX' ? '* ' : '') . $from .
                        ' ' . ($m['time'] ? date('m/d H:i', $m['time']) : '');
                    $links[] = array(substr($label,0,60), $base . '&op=detail&id=' . rawurlencode($m['id']) . '&folder=' . $m['folder']);
                }
                if (!$links) $links[] = array('No voicemail messages', $base);
                $this->xml('Voicemail ' . $line, count($messages) . ' messages (* = new)', $links);
                return;
            }
            $id = (string)($get['id'] ?? '');
            $folder = (string)($get['folder'] ?? '');
            $msg = $this->messageById($line, $id, $folder);
            $from = $msg['name'] ?: ($msg['number'] ?: 'Unknown Caller');
            if ($op === 'mark') {
                if ($folder !== 'INBOX') throw new \InvalidArgumentException('Message is already marked heard.');
                $this->ami(array('Action'=>'VoicemailMove','Context'=>'default','Mailbox'=>$line,
                    'Folder'=>'INBOX','ID'=>$id,'ToFolder'=>'Old'));
                $this->xml('Marked Heard', 'Message moved to Old.', array(array('Back to messages', $base)));
                return;
            }
            if ($op === 'delete') {
                $this->xml('Delete Message?', 'Delete voicemail from ' . substr($from = ($msg['name'] ?: ($msg['number'] ?: 'Unknown')),0,38) . '?', array(
                    array('YES - Delete', $base . '&op=confirm-delete&id=' . rawurlencode($id) . '&folder=' . $folder),
                    array('Cancel', $base . '&op=detail&id=' . rawurlencode($id) . '&folder=' . $folder)
                ));
                return;
            }
            if ($op === 'confirm-delete') {
                $this->ami(array('Action'=>'VoicemailRemove','Context'=>'default','Mailbox'=>$line,
                    'Folder'=>$folder,'ID'=>$id));
                $this->xml('Message Deleted','Voicemail deleted.',array(array('Back to messages',$base)));
                return;
            }
            if ($op === 'play') {
                $this->playback($line, $msg);
                $this->xml('Playing Voicemail', 'The recording will play on your phone.', array(
                    array('Return to messages', $base)
                ));
                return;
            }
            $this->xml(substr($from,0,55), 'Select an action', array(
                array('Play (' . $msg['duration'] . ' sec)', $base . '&op=play&id=' . rawurlencode($id) . '&folder=' . $folder),
                ...($folder === 'INBOX' ? array(array('Mark as Heard', $base . '&op=mark&id=' . rawurlencode($id) . '&folder=' . $folder)) : array()),
                array('Delete Message', $base . '&op=delete&id=' . rawurlencode($id) . '&folder=' . $folder),
                array('Back to messages', $base)
            ));
        } catch (\Throwable $e) {
            error_log('sccp_manager visual voicemail: ' . $e->getMessage());
            $this->xml('Voicemail Unavailable', substr($e->getMessage(),0,120), array());
        }
    }
}
