<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Poll Asterisk voicemail files and CDR records, plus scheduled content.
 * Kept within SCCP Manager: cron invokes it, no extra daemon or HTTP API.
 */
final class notificationWorker {
    private $module;
    private $delivery;
    public function __construct($module) {
        $this->module=$module;
        $this->delivery=new notificationDelivery($module);
    }
    public function tick() {
        $settings=(new notificationConfig($this->module))->all();
        $state=$this->module->getConfig('phonecontent_notification_state');
        if (!is_array($state)) $state=[];
        $now=time();
        $vm=new phonevoicemail($this->module);
        $inbox=$this->voicemailInbox($vm);
        if (!isset($state['initialized'])) {
            $state=['initialized'=>$now, 'vm_ids'=>$this->inboxIds($inbox), 'seen'=>[], 'scheduled_at'=>$now];
            $this->module->setConfig('phonecontent_notification_state',$state);
            return;
        }
        $dirty=false;
        if ($settings['scheduled']['enabled']) {
            $s=$settings['scheduled'];
            $date=date('Y-m-d',$now);
            $due=$s['time']!=='' ?
                date('H:i',$now)===$s['time'] && ($state['scheduled_day']??'')!==$date
                : $now-(int)($state['scheduled_at']??$now)>=60*(int)$s['interval'];
            if ($due) {
                $this->delivery->send('scheduled');
                $state['scheduled_at']=$now;
                $state['scheduled_day']=$date;
                $dirty=true;
            }
        }
        // Upgrade count-only state safely: never replay existing messages.
        if (!isset($state['vm_ids']) || !is_array($state['vm_ids'])) {
            $state['vm_ids']=$this->inboxIds($inbox);
            unset($state['vm']);
            $dirty=true;
        } else {
            $previous=$state['vm_ids'];
            foreach ($inbox as $extension=>$messages) {
                $seen=array_fill_keys((array)($previous[$extension]??[]),true);
                foreach (array_reverse($messages) as $msg) {
                    if (isset($seen[$msg['id']])) continue;
                    if ($settings['voicemail']['enabled'])
                        $this->delivery->send('voicemail',$vm->notificationFields($extension,$msg,count($messages)),[$extension]);
                }
            }
            $state['vm_ids']=$this->inboxIds($inbox);
            $dirty=true;
        }
        if ($settings['missed']['enabled']) {
            $seen=(array)($state['seen']??[]);
            foreach ($this->missedCalls($now) as $call) {
                $key=(string)$call['uniqueid'].'/'.(string)$call['dst'];
                if (isset($seen[$key])) continue;
                $seen[$key]=$now;
                // CDR src can remain the internal SCCP line even when its
                // configured caller identity is the shared public extension.
                if (!empty($call['origin_sccp_line'])) {
                    $public=$this->mailboxForLine((string)$call['origin_sccp_line']);
                    if ($public!=='' && $public!==(string)$call['origin_sccp_line']) {
                        $call['src']=$public;
                        $call['clid']=$public;
                    }
                }
                // A stored voicemail takes precedence over the missed-call popup.
                if (self::hasRecordedVoicemail($call,$inbox,$this->mailboxForLine((string)$call['dst']))) continue;
                // Do not send previously unseen historical missed calls on first enable.
                if (strtotime((string)$call['calldate'])<$now-240) continue;
                $when=strtotime((string)$call['calldate']) ?: $now;
                $number=(string)$call['src'];
                $caller=$this->formatCaller((string)($call['clid']??''),$number);
                $this->delivery->send('missed',self::missedNotificationFields($call,$when),[(string)$call['dst']]);
            }
            $state['seen']=array_filter($seen,static function($time)use($now){return (int)$time>$now-3600;});
            $dirty=true;
        }
        if ($dirty) $this->module->setConfig('phonecontent_notification_state',$state);
    }

    /** One field formatter for live missed-call delivery and designer preview. */
    public static function missedNotificationFields(array $call,$when) {
        $number=(string)($call['src']??'');
        $clid=trim((string)($call['clid']??''));
        $caller=$number!==''?$number:'Unknown caller';
        if (preg_match('/^"([^"]+)"\\s*<[^>]*>$/',$clid,$m) && trim($m[1])!=='')
            $caller=trim($m[1]);
        elseif (preg_match('/^([^<]+)\\s*<[^>]*>$/',$clid,$m) && trim($m[1])!=='')
            $caller=trim($m[1]);
        return [
            'caller'=>$caller,'number'=>$number,
            'extension'=>(string)($call['dst']??''),
            'date'=>date('M j, Y',$when),
            'time'=>date('g:i A',$when),
            'when'=>date('M j, Y \\a\\t g:i A',$when),
            'reason'=>(string)($call['disposition']??'')==='BUSY'?'Busy':'No answer'
        ];
    }

    /**
     * Suppress missed-call popups only if a real voicemail recording exists
     * for this extension and caller during the same call. Merely reaching
     * VoiceMail() does not mean the caller actually left a message.
     */
    /** CDR targets identify the SCCP line; recordings live in its mailbox. */
    private function mailboxForLine($line) {
        if (!preg_match('/^[0-9]{1,16}$/D',$line)) return $line;
        try {
            $stmt=\FreePBX::Database()->prepare('SELECT mailbox FROM sccpline WHERE name = ?');
            $stmt->execute([$line]);
            $raw=trim((string)($stmt->fetchColumn() ?: ''));
            if (preg_match('/^([0-9]{1,16})(?:@default)?$/D',$raw,$m)) return $m[1];
        } catch (\Throwable $e) {
            error_log('SCCP missed-call mailbox lookup: '.$e->getMessage());
        }
        return $line;
    }

    public static function hasRecordedVoicemail(array $call,array $inbox,$mailbox=null) {
        $extension=$mailbox===null?(string)($call['dst']??''):(string)$mailbox;
        $started=strtotime((string)($call['calldate']??'')) ?: 0;
        if (!$started || !isset($inbox[$extension])) return false;
        $ended=$started+max(0,(int)($call['duration']??0));
        $number=preg_replace('/[^0-9]/','',(string)($call['src']??''));
        foreach ($inbox[$extension] as $msg) {
            $recorded=(int)($msg['time']??0);
            if ($recorded<$started-5 || $recorded>$ended+30) continue;
            $from=preg_replace('/[^0-9]/','',(string)($msg['number']??''));
            // A different known caller is never a match.
            if ($number!=='' && $from!=='' && $number!==$from) continue;
            return true;
        }
        return false;
    }

    private function voicemailInbox(phonevoicemail $vm) {
        $result=[];
        foreach (glob('/var/spool/asterisk/voicemail/default/*/INBOX',GLOB_ONLYDIR)?:[] as $dir) {
            $mailbox=basename(dirname($dir));
            if (!preg_match('/^[0-9]{1,16}$/D',$mailbox)) continue;
            $result[$mailbox]=$vm->inboxMessages($mailbox);
        }
        return $result;
    }

    private function inboxIds(array $inbox) {
        $ids=[];
        foreach ($inbox as $mailbox=>$messages) $ids[$mailbox]=array_column($messages,'id');
        return $ids;
    }

    private function formatCaller($callerId,$fallback) {
        $callerId=trim($callerId);
        if (preg_match('/^"([^"]+)"\\s*<[^>]*>$/',$callerId,$m) && trim($m[1])!=='')
            return trim($m[1]);
        if (preg_match('/^([^<]+)\\s*<[^>]*>$/',$callerId,$m) && trim($m[1])!=='')
            return trim($m[1]);
        return $fallback!==''?$fallback:'Unknown caller';
    }

    /**
     * A linked call can have an ANSWERED voicemail CDR even when nobody
     * answered the phone. Classify the outbound SCCP dial leg, not the
     * overall call disposition.
     *
     * Each entry is one genuinely unanswered SCCP destination per linkedid.
     */
    public static function classifyMissedCallRows(array $rows, $now) {
        $calls=[];
        foreach ($rows as $row) {
            $linked=(string)($row['linkedid']??$row['uniqueid']??'');
            if ($linked==='') continue;
            $calls[$linked][]=$row;
        }
        $found=[];
        foreach ($calls as $linked=>$group) {
            $targets=[]; $lastEnd=0;
            foreach ($group as $row) {
                $started=strtotime((string)($row['calldate']??'')) ?: 0;
                $lastEnd=max($lastEnd,$started+max(0,(int)($row['duration']??0)));
                // On FreePBX, outbound SCCP channels occur as Dial's
                // destination channel or in the Dial application arguments.
                // Other answered legs (VoiceMail, trunks, IVRs) do not count.
                $channels=[];
                $destination=(string)($row['dstchannel']??'');
                if (preg_match('~^SCCP/([0-9]{1,16})(?:[-/]|$)~i',$destination,$m))
                    $channels[]=$m[1];
                if (strcasecmp((string)($row['lastapp']??''),'Dial')===0 &&
                    preg_match_all('~SCCP/([0-9]{1,16})(?=[^0-9]|$)~i',(string)($row['lastdata']??''),$matches))
                    $channels=array_merge($channels,$matches[1]);
                foreach (array_unique($channels) as $ext) {
                    if (!isset($targets[$ext])) $targets[$ext]=['answered'=>false,'candidate'=>null];
                    if (strtoupper((string)($row['disposition']??''))==='ANSWERED' &&
                        preg_match('~^SCCP/'.preg_quote($ext,'~').'(?=[^0-9]|$)~i',$destination)) {
                        $targets[$ext]['answered']=true;
                    }
                    if ($targets[$ext]['candidate']===null ||
                        $started > (strtotime((string)$targets[$ext]['candidate']['calldate'])?:0))
                        $targets[$ext]['candidate']=$row;
                }
            }
            // Preserve the original caller from the linked call, not an
            // internal Dial/VoiceMail leg that may have an empty src.
            $origin='';
            $originSccp='';
            foreach ($group as $entry) {
                $from=(string)($entry['channel']??'');
                if (preg_match('~^SCCP/([0-9]{1,16})(?=[^0-9]|$)~i',$from,$m)) {
                    $origin=$m[1];
                    $originSccp=$m[1];
                    break;
                }
            }
            if ($origin==='') {
                foreach ($group as $entry) {
                    $source=(string)($entry['src']??'');
                    if (preg_match('/^[+0-9*#]{1,32}$/D',$source)) {
                        $origin=$source;
                        break;
                    }
                }
            }
            // Wait until all CDR legs have finished. This avoids declaring
            // a call missed while a phone is still ringing or in voicemail.
            if ($lastEnd===0 || $lastEnd>$now-15 || $lastEnd<$now-240) continue;
            foreach ($targets as $ext=>$state) {
                if ($state['answered'] || !$state['candidate']) continue;
                $row=$state['candidate'];
                if ($origin!=='' && $origin!==$ext) {
                    $row['src']=$origin;
                    if (trim((string)($row['clid']??''))==='') $row['clid']=$origin;
                }
                $row['dst']=$ext;
                if ($originSccp!=='') $row['origin_sccp_line']=$originSccp;
                $row['uniqueid']=$linked;
                $row['disposition']='NO ANSWER';
                $found[]=$row;
            }
        }
        return $found;
    }

    private function missedCalls($now) {
        global $amp_conf;
        try {
            $host=(string)($amp_conf['AMPDBHOST']??'localhost');
            $port=(int)($amp_conf['AMPDBPORT']??3306);
            $user=(string)($amp_conf['AMPDBUSER']??'');
            $pass=(string)($amp_conf['AMPDBPASS']??'');
            if (!$user) return [];
            $db=new \PDO('mysql:host='.$host.';port='.$port.';dbname=asteriskcdrdb;charset=utf8mb4',
                $user,$pass,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_TIMEOUT=>3]);
            $start=date('Y-m-d H:i:s',$now-900);
            $stmt=$db->prepare("SELECT uniqueid,linkedid,src,dst,clid,disposition,calldate,duration,channel,dstchannel,lastapp,lastdata FROM cdr WHERE calldate >= ? ORDER BY calldate DESC LIMIT 1500");
            $stmt->execute([$start]);
            return self::classifyMissedCallRows($stmt->fetchAll(\PDO::FETCH_ASSOC),$now);
        } catch (\Throwable $e) {
            error_log('SCCP notification CDR check failed: '.$e->getMessage());
            return [];
        }
    }
}
