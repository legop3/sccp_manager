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
        if (!isset($state['initialized'])) {
            $state=['initialized'=>$now, 'vm'=>$this->voicemailCounts(), 'seen'=>[], 'scheduled_at'=>$now];
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
        if ($settings['voicemail']['enabled']) {
            $counts=$this->voicemailCounts();
            foreach ($counts as $extension=>$count) {
                $previous=(int)($state['vm'][$extension]??$count);
                if ($count>$previous) {
                    $details=$this->latestVoicemail($extension);
                    $this->delivery->send('voicemail',[
                        'count'=>$count, 'extension'=>$extension,
                        'caller'=>$details['caller'], 'number'=>$details['number'],
                        'date'=>$details['date'], 'time'=>$details['time'],
                        'length'=>$details['length'],
                        'received'=>$details['received']
                    ],[$extension]);
                }
            }
            $state['vm']=$counts;
            $dirty=true;
        } else if (!empty($state['vm'])) {
            $state['vm']=$this->voicemailCounts();
            $dirty=true;
        }
        if ($settings['missed']['enabled']) {
            $seen=(array)($state['seen']??[]);
            foreach ($this->missedCalls($now) as $call) {
                $key=(string)$call['uniqueid'];
                if (isset($seen[$key])) continue;
                $seen[$key]=$now;
                // Do not send previously unseen historical missed calls on first enable.
                if (strtotime((string)$call['calldate'])<$now-120) continue;
                $when=strtotime((string)$call['calldate']) ?: $now;
                $number=(string)$call['src'];
                $caller=$this->formatCaller((string)($call['clid']??''),$number);
                $this->delivery->send('missed',[
                    'caller'=>$caller, 'number'=>$number,
                    'extension'=>(string)$call['dst'],
                    'date'=>date('M j, Y',$when),
                    'time'=>date('g:i A',$when),
                    'when'=>date('M j, Y \\a\\t g:i A',$when),
                    'reason'=>(string)$call['disposition']==='BUSY'?'Busy':'No answer'
                ],[(string)$call['dst']]);
            }
            $state['seen']=array_filter($seen,static function($time)use($now){return (int)$time>$now-3600;});
            $dirty=true;
        }
        if ($dirty) $this->module->setConfig('phonecontent_notification_state',$state);
    }

    /** Extract the latest message metadata; never read voicemail audio. */
    private function latestVoicemail($extension) {
        $result=['caller'=>'Unknown caller','number'=>'Unknown',
            'date'=>'Unknown','time'=>'Unknown','length'=>'Unknown','received'=>'Unknown'];
        $latest=null; $latestTime=0; $latestData=[];
        foreach (glob('/var/spool/asterisk/voicemail/*/'.$extension.'/INBOX/msg*.txt')?:[] as $file) {
            if (!is_file($file) || is_link($file)) continue;
            $lines=@file($file,FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) continue;
            $data=[];
            foreach ($lines as $line) {
                if (preg_match('/^([a-z_]+)=(.*)$/i',$line,$m)) {
                    $data[strtolower($m[1])]=trim($m[2]);
                }
            }
            $stamp=isset($data['origtime']) && ctype_digit((string)$data['origtime'])
                ? (int)$data['origtime'] : (int)@filemtime($file);
            if ($latest===null || $stamp>$latestTime) {
                $latest=$file; $latestTime=$stamp; $latestData=$data;
            }
        }
        if ($latest===null) return $result;
        $number=trim((string)($latestData['callerid']??''));
        $result['caller']=$this->formatCaller($number,'Unknown');
        if (preg_match('/<([^>]+)>/',$number,$m)) $number=trim($m[1]);
        $result['number']=$number!==''?$number:'Unknown';
        if ($latestTime>0) {
            $result['date']=date('M j, Y',$latestTime);
            $result['time']=date('g:i A',$latestTime);
            $result['received']=$result['date'].' at '.$result['time'];
        }
        if (isset($latestData['duration']) && ctype_digit((string)$latestData['duration'])) {
            $seconds=(int)$latestData['duration'];
            $result['length']=sprintf('%d:%02d',intdiv($seconds,60),$seconds%60);
        }
        return $result;
    }

    private function formatCaller($callerId,$fallback) {
        $callerId=trim($callerId);
        if (preg_match('/^"([^"]+)"\\s*<[^>]*>$/',$callerId,$m) && trim($m[1])!=='')
            return trim($m[1]);
        if (preg_match('/^([^<]+)\\s*<[^>]*>$/',$callerId,$m) && trim($m[1])!=='')
            return trim($m[1]);
        return $fallback!==''?$fallback:'Unknown caller';
    }

    private function voicemailCounts() {
        $counts=[];
        foreach (glob('/var/spool/asterisk/voicemail/*/*/INBOX',GLOB_ONLYDIR)?:[] as $dir) {
            $ext=basename(dirname($dir));
            if (!preg_match('/^[0-9]{1,16}$/D',$ext)) continue;
            $counts[$ext]=count(glob($dir.'/msg*.txt')?:[]);
        }
        return $counts;
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
            $start=date('Y-m-d H:i:s',$now-130);
            $stmt=$db->prepare("SELECT uniqueid,src,dst,clid,disposition,calldate FROM cdr WHERE calldate >= ? AND disposition IN ('NO ANSWER','BUSY') AND dst REGEXP '^[0-9]+$' ORDER BY calldate DESC LIMIT 100");
            $stmt->execute([$start]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('SCCP notification CDR check failed: '.$e->getMessage());
            return [];
        }
    }
}
