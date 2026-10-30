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
    private $serviceContext = false;
    private $singleMessage = false;
    public function __construct($parent = null) { $this->parent = $parent; }

    public static function validDevice($id) {
        return is_string($id) && preg_match('/^SEP[0-9A-F]{12}$/D', $id);
    }

    /** Resolve the physical SCCP line and its configured mailbox once. */
    public function destinationForDevice($device)
    {
        if (!self::validDevice($device)) throw new \InvalidArgumentException('Invalid phone identifier.');
        $data = $this->parent->dbinterface->getSccpDeviceTableData('getDefaultLine', array('id' => $device));
        $line = is_array($data) ? (string) ($data['name'] ?? '') : '';
        if (!preg_match('/^[0-9]{1,16}$/D', $line)) {
            throw new \RuntimeException('Phone ' . $device . ' needs a numeric default SCCP line before visual voicemail can be enabled.');
        }
        $db = \FreePBX::Database();
        $stmt = $db->prepare('SELECT mailbox FROM sccpline WHERE name = ?');
        $stmt->execute(array($line));
        $configured = $stmt->fetchColumn();
        // SCCP mailboxes can include an Asterisk context, e.g. 111@default.
        // The visual voicemail service currently supports the default context.
        $mailbox = trim((string) ($configured === false ? '' : $configured));
        if ($mailbox === '') $mailbox = $line;
        if (!preg_match('/^([0-9]{1,16})(?:@default)?$/D', $mailbox, $matches)) {
            throw new \RuntimeException('Unsupported voicemail mailbox for SCCP line ' . $line . ': ' . $mailbox);
        }
        return array('line' => $line, 'mailbox' => $matches[1], 'extension' => $matches[1]);
    }

    public function lineForDevice($device) { return $this->destinationForDevice($device)['line']; }
    public function mailboxForDevice($device) { return $this->destinationForDevice($device)['mailbox']; }

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
        $base = $this->parent->phonecontent->baseUrl($device);
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
        return array($device, $this->destinationForDevice($device), $token);
    }

    /** Only emit Dial actions for a caller number actually present in metadata. */
    public static function callbackNumber(array $message)
    {
        $number=(string)($message['number'] ?? '');
        if ($number==='' && preg_match('/^[+0-9*#]{1,32}$/D',(string)($message['name']??'')))
            $number=(string)$message['name'];
        return preg_match('/^[+0-9*#]{1,32}$/D',$number) ? $number : '';
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

    /** The visual voicemail parser is the authoritative source for notifications. */
    public function inboxMessages($mailbox)
    {
        return array_values(array_filter($this->listMessages($mailbox),
            static function ($m) { return $m['folder'] === 'INBOX'; }));
    }

    public function notificationFields($mailbox, array $msg, $count)
    {
        $time=(int)$msg['time'];
        $name=(string)$msg['name'];
        $number=(string)$msg['number'];
        return array(
            'id'=>(string)$msg['id'], 'extension'=>(string)$mailbox,
            'count'=>(int)$count,
            'caller'=>$name!==''?$name:($number!==''?$number:'Unknown caller'),
            'number'=>$number!==''?$number:'Unknown',
            'date'=>$time>0?date('M j, Y',$time):'Unknown',
            'time'=>$time>0?date('g:i A',$time):'Unknown',
            'received'=>$time>0?date('M j, Y \\a\\t g:i A',$time):'Unknown',
            'length'=>sprintf('%d:%02d',intdiv((int)$msg['duration'],60),(int)$msg['duration']%60)
        );
    }

    /**
     * Message IDs persist when Asterisk moves a heard voicemail from INBOX
     * into Old, while msg000N filenames and folder names may change. Resolve
     * every operation against the CURRENT folder instead of stale menu links.
     */
    /** Asterisk's VoicemailMove/Remove can ACK failed operations with
     * Response: Success. The Message field, not Response alone, reports the
     * actual operation result in app_voicemail.c.
     */
    public static function requireMutationSuccess($action, array $reply)
    {
        $messages = array(
            'VoicemailRemove' => 'Message remove successful',
            'VoicemailMove' => 'Message move successful'
        );
        if (!isset($messages[$action])) {
            throw new \InvalidArgumentException('Unknown voicemail mutation.');
        }
        $status = strtolower(trim((string) ($reply['Response'] ?? '')));
        $message = strtolower(trim((string) ($reply['Message'] ?? '')));
        if ($status !== 'success' || $message !== strtolower($messages[$action])) {
            throw new \RuntimeException($action . ' failed: ' .
                ($message !== '' ? $message : 'Asterisk returned no confirmation.'));
        }
    }

    public function containsMessageId($mailbox, $id)
    {
        clearstatcache(true);
        foreach ($this->listMessages($mailbox) as $message) {
            if ($message['id'] === $id) return true;
        }
        return false;
    }

    private function messageById($mailbox, $id)
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid message.');
        }
        return self::resolveMessage($this->listMessages($mailbox), $id);
    }

    public static function resolveMessage(array $items, $id)
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid message.');
        }
        foreach ($items as $m) {
            if (is_array($m) && ($m['id'] ?? '') === $id) return $m;
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
            // Synchronous Asterisk Originate now waits for the SCCP phone
            // to answer (max Timeout). Give AMI enough response timeout
            // rather than incorrectly treating a slow VPN call as failed.
            $replyTimeout = ($fields['Action'] ?? '') === 'Originate'
                ? (int)ceil(((int)($fields['Timeout'] ?? 10000)) / 1000) + 3
                : 4;
            stream_set_timeout($stream, $replyTimeout);
            $this->sendAmi($stream, $fields);
            $reply = $this->readAmi($stream, $list, $replyTimeout);
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

    private function readAmi($stream, $list = false, $timeoutSeconds = 4)
    {
        $records = array();
        $one = array();
        $completed = false;
        $deadline = microtime(true) + max(1, min(30, (int)$timeoutSeconds));
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

    /** A finished playback channel may linger in CoreShowChannels after hangup. */
    public static function blocksVoicemailPlayback(array $event)
    {
        return !(strcasecmp((string)($event['ChannelStateDesc'] ?? ''), 'Down') === 0 &&
            (string)($event['Context'] ?? '') === 'sccp-manager-voicemail-play');
    }

    private function assertNotOnCall($line)
    {
        $r = $this->ami(array('Action'=>'CoreShowChannels'), true);
        foreach ($r['__events'] as $event) {
            if (($event['Event'] ?? '') !== 'CoreShowChannel') continue;
            if (preg_match('/^SCCP\/' . preg_quote($line, '/') . '(?:-|$|\/)/i', $event['Channel'] ?? '') && self::blocksVoicemailPlayback($event)) {
                throw new \RuntimeException('Phone is already on a call. Finish the call before playing voicemail.');
            }
        }
    }

    public static function originateFields($line, array $message, $mailbox)
    {
        if (!preg_match('/^[0-9]{1,16}$/D', $line) ||
            !preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $message['id'] ?? '')) {
            throw new \InvalidArgumentException('Invalid voicemail playback target.');
        }
        if (!preg_match('/^[0-9]{1,16}$/D', $mailbox)) throw new \InvalidArgumentException('Invalid voicemail mailbox.');
        // Caller ID comes from the actual voicemail, not the mailbox owner.
        $display = $message['name'] !== '' ? $message['name'] :
            ($message['number'] !== '' ? $message['number'] : 'Voicemail');
        // Delimiters like (, ) and , must never occur in the Gosub args.
        // Keep CallerID a phone-display-safe name, without compromising
        // its dynamic voicemail metadata origin.
        $cidName = substr(preg_replace('/[^A-Za-z0-9 ._+-]/', '', $display), 0, 48);
        if ($cidName === '') $cidName = 'Voicemail';
        $cidNum = preg_replace('/[^0-9]/', '', $message['number']);
        if ($cidNum === '') $cidNum = $line;
        return array(
            'Action'=>'Originate',
            'Channel'=>'SCCP/' . $line . '/aa=1w/ringer=silent',
            // AMI Application-based originate has no pre-dial hook. SCCP
            // keeps its *own* callingParty fields, so Asterisk's CallerID
            // alone does not reach the Cisco 7945G display.
            //
            // In Asterisk 22.6, PreDialGoSub works for synchronous Exten
            // originates. Pass caller name/number as explicit Gosub args
            // because channel Variable headers are applied AFTER predial.
            'Context'=>'sccp-manager-voicemail-play',
            'Exten'=>'s',
            'Priority'=>'1',
            'PreDialGoSub'=>'sccp-manager-voicemail-predial,s,1(' . $cidName . ',' . $cidNum . ')',
            'Variable'=>'SCCP_VM_MAILBOX=' . $mailbox . ',SCCP_VM_ID=' . $message['id'],
            'CallerID'=>'"' . $cidName . '" <' . $cidNum . '>',
            'Timeout'=>'10000',
            'Async'=>'false'
        );
    }

    public function playback($line, array $message, $mailbox)
    {
        // These contexts must be installed and loaded before originating.
        // Refuse silent caller-ID regressions if installation was incomplete.
        $dialplan = '/etc/asterisk/sccp-manager-voicemail.conf';
        if (!is_file($dialplan)) {
            throw new \RuntimeException('SCCP voicemail dialplan is missing; reinstall SCCP Manager as root.');
        }
        $this->assertNotOnCall($line);
        $this->ami(self::originateFields($line, $message, $mailbox));
        // Originate succeeded: record this voicemail as heard via the same
        // AMI move action used by the visual voicemail menu. The stable ID
        // remains valid when Asterisk moves INBOX to Old.
        if (($message['folder'] ?? '') === 'INBOX') {
            $current=$this->messageById($mailbox,$message['id']);
            if ($current['folder'] === 'INBOX') {
                $reply=$this->ami(array('Action'=>'VoicemailMove','Context'=>'default',
                    'Mailbox'=>$mailbox,'Folder'=>'INBOX','ID'=>$message['id'],'ToFolder'=>'Old'));
                self::requireMutationSuccess('VoicemailMove',$reply);
            }
        }
    }

    /**
     * Finish serving the XML page before making the call. Otherwise the 7945G
     * sometimes cancels its HTTP request as auto-answer steals screen focus,
     * producing an intermittent XML Error 4.
     */
    public function queuePlayback($line, array $message, $mailbox)
    {
        $this->assertNotOnCall($line);
        ignore_user_abort(true);
        register_shutdown_function(function () use ($line, $message, $mailbox) {
            // Playback starts at PHP request shutdown, after the XML
            // response has been written and flushed. No fixed timer.
            try {
                $this->playback($line, $message, $mailbox);
            } catch (\Throwable $e) {
                error_log('sccp_manager visual voicemail playback: ' . $e->getMessage());
            }
        });
    }

    /**
     * The 7945G maintains a history of requested XML URLs, regardless of the
     * server-side page state. Each screen has explicit Back (when relevant)
     * and Exit controls, so navigation never relies on walking that history.
     * Custom softkeys replace ALL defaults, including Select, so restore it.
     */
    private function xml($title, $prompt, array $entries = array(), $backUrl = null, $vmMenuUrl = null)
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

        $softkeys = array(array('Select', 'SoftKey:Select', 1));
        if ($vmMenuUrl !== null) {
            $softkeys[] = array('VM Menu', $vmMenuUrl, 2);
        }
        if ($backUrl !== null) {
            $softkeys[] = array('Back', $backUrl, 3);
        }
        // Init:Messages closes the Messages application instead of walking
        // the firmware's page history like SoftKey:Exit does.
        $softkeys[] = array('Exit', $this->serviceContext ? 'SoftKey:Exit' : 'Init:Messages', 4);
        foreach ($softkeys as $softkey) {
            $item = $root->appendChild($doc->createElement('SoftKeyItem'));
            $item->appendChild($doc->createElement('Name'))->appendChild($doc->createTextNode($softkey[0]));
            $item->appendChild($doc->createElement('URL'))->appendChild($doc->createTextNode($softkey[1]));
            $item->appendChild($doc->createElement('Position'))->appendChild($doc->createTextNode((string)$softkey[2]));
        }
        header('Content-Type: text/xml; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $doc->saveXML();
    }

    private function showMessageDetail($base, array $msg, $afterPlayback = false, $confirmDelete = false)
    {
        $id = $msg['id'];
        $folder = $msg['folder'];
        $from = $msg['name'] ?: ($msg['number'] ?: 'Unknown Caller');
        if ($confirmDelete) {
            // The separate 'Delete Message?' Cisco XML page produced Error 4
            // on the user's 7945G, although the regular detail page works.
            // Keep the proven detail-screen XML structure and change only
            // the action links. Deletion still requires a second press.
            $entries = array(
                array('CONFIRM DELETE', $base . '&op=confirm-delete&id=' . rawurlencode($id)),
                array('Cancel', $base . '&op=detail&id=' . rawurlencode($id))
            );
        } else {
            $entries = array(
                array(($afterPlayback ? 'Play Again' : 'Play') . ' (' . $msg['duration'] . ' sec)',
                    $base . '&op=play&id=' . rawurlencode($id) . '&request=' . bin2hex(random_bytes(6)))
            );
            // Playback may automatically move a new message to Old.
            if ($folder === 'INBOX' && !$afterPlayback) {
                $entries[] = array('Mark as Heard', $base . '&op=mark&id=' . rawurlencode($id));
            }
            // Dial URI uses the same verified caller number as voicemail
            // metadata, and works for internal SCCP extensions too.
            $callback=self::callbackNumber($msg);
            if ($callback!=='')
                $entries[] = array('Call Back ' . $callback, 'Dial:' . $callback);
            $entries[] = array('Delete Message', $base . '&op=delete&id=' . rawurlencode($id));
        }
        $this->xml(substr($from, 0, 55), 'Select an action', $entries,
            $confirmDelete ? $base . '&op=detail&id=' . rawurlencode($id) : ($this->singleMessage ? null : $base),
            $this->singleMessage ? $this->serviceUrlFromSingle($base) : null);
    }

    private function serviceUrlFromSingle($base)
    {
        return str_replace('&single=1', '', $base);
    }

    private function showMessageList($line, $base, array $messages)
    {
        $links = array();
        foreach ($messages as $m) {
            $from = $m['name'] !== '' ? $m['name'] : ($m['number'] !== '' ? $m['number'] : 'Unknown Caller');
            $label = ($m['folder'] === 'INBOX' ? '* ' : '') . $from .
                ' ' . ($m['time'] ? date('m/d H:i', $m['time']) : '');
            // Link only the stable ID, NEVER a folder or msg000N slot.
            $links[] = array(substr($label, 0, 60), $base . '&op=detail&id=' . rawurlencode($m['id']));
        }
        if (!$links) $links[] = array('No voicemail messages', $base);
        // This softkey is outside the recordings list and appears only
        // at the mailbox root, never on individual message screens.
        $this->xml('Voicemail ' . $line, count($messages) . ' messages (* = new)',
            $links, null, $base . '&op=call-menu');
    }

    /**
     * Cisco 7945G supports two telephony URIs in a CiscoIPPhoneExecute.
     * Init closes Messages and Dial starts a normal user-originated call.
     * The two URIs are independent: their order of execution is undefined.
     * There is no auto-answer or browser-dependent completion event.
     */
    private function traditionalVoicemailCall($line)
    {
        if (!is_string($line) || !preg_match('/^[0-9]{1,16}$/D', $line)) {
            throw new \InvalidArgumentException('Invalid voicemail mailbox.');
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->appendChild($doc->createElement('CiscoIPPhoneExecute'));
        foreach (array('Init:Messages', 'Dial:*9900' . $line) as $command) {
            $item = $root->appendChild($doc->createElement('ExecuteItem'));
            $item->setAttribute('URL', $command);
        }
        header('Content-Type: text/xml; charset=UTF-8');
        header('Cache-Control: no-store');
        echo $doc->saveXML();
    }

    public function handle(array $get)
    {
        try {
            list($device, $destination, $token) = $this->authenticate($get);
            $line = $destination['mailbox'];
            $base = $this->serviceUrl($device);
            $this->serviceContext = (string)($get['service'] ?? '') === '1';
            $this->singleMessage = $this->serviceContext && (string)($get['single'] ?? '') === '1';
            if ($this->serviceContext) $base .= '&service=1';
            if ($this->singleMessage) $base .= '&single=1';
            $op = (string)($get['op'] ?? 'list');
            if (!in_array($op, array('list','detail','play','notification','notification-play','mark','delete','confirm-delete','call-menu'), true)) throw new \InvalidArgumentException('Unknown voicemail operation.');
            if ($op === 'call-menu') {
                $this->traditionalVoicemailCall($line);
                return;
            }
            $messages = $this->listMessages($line);
            if ($op === 'list') {
                $this->showMessageList($line, $base, $messages);
                return;
            }
            $id = (string)($get['id'] ?? '');
            // An old phone may still have ?folder=INBOX cached; ignore it.
            // Resolve the current folder by stable message ID instead.
            $msg = $this->messageById($line, $id);
            $folder = $msg['folder'];
            $from = $msg['name'] ?: ($msg['number'] ?: 'Unknown Caller');
            if ($op === 'notification' || $op === 'notification-play') {
                if ($op === 'notification-play') $this->queuePlayback($destination['line'], $msg, $destination['mailbox']);
                $this->showMessageDetail($base, $msg, $op === 'notification-play');
                return;
            }
            if ($op === 'mark') {
                if ($folder !== 'INBOX') throw new \InvalidArgumentException('Message is already marked heard.');
                $reply = $this->ami(array('Action'=>'VoicemailMove','Context'=>'default','Mailbox'=>$line,
                    'Folder'=>'INBOX','ID'=>$id,'ToFolder'=>'Old'));
                self::requireMutationSuccess('VoicemailMove', $reply);
                $moved = $this->messageById($line, $id);
                if ($moved['folder'] !== 'Old') {
                    throw new \RuntimeException('Asterisk reported success but voicemail is not in Old.');
                }
                $this->showMessageDetail($base, $moved);
                return;
            }
            if ($op === 'delete') {
                // Do not display the separate confirmation XML that failed
                // on CP-7945G. Reuse the working message-detail renderer.
                $this->showMessageDetail($base, $msg, false, true);
                return;
            }
            if ($op === 'confirm-delete') {
                $reply = $this->ami(array('Action'=>'VoicemailRemove','Context'=>'default','Mailbox'=>$line,
                    'Folder'=>$folder,'ID'=>$id));
                self::requireMutationSuccess('VoicemailRemove', $reply);
                if ($this->containsMessageId($line, $id)) {
                    throw new \RuntimeException('Asterisk acknowledged deletion, but the message is still present.');
                }
                // Show the current list immediately. No misleading success
                // page and no extra page for the phone's history to traverse.
                if ($this->singleMessage) {
                    $this->xml('Voicemail Deleted', 'Message removed', array(), null, $this->serviceUrlFromSingle($base));
                } else {
                    $this->showMessageList($line, $base, $this->listMessages($line));
                }
                return;
            }
            if ($op === 'play') {
                // The call starts ONLY after the full XML response has been
                // flushed. This avoids racing the phone's XML parser.
                $this->queuePlayback($destination['line'], $msg, $destination['mailbox']);
                $this->showMessageDetail($base, $msg, true);
                return;
            }
            $this->showMessageDetail($base, $msg);
        } catch (\Throwable $e) {
            error_log('sccp_manager visual voicemail: ' . $e->getMessage());
            $this->xml('Voicemail Unavailable', substr($e->getMessage(),0,120), array());
        }
    }
}
