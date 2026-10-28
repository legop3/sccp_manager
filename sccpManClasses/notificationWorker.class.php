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
                    $this->delivery->send('voicemail',[
                        'count'=>$count,'extension'=>$extension,
                        'time'=>date('g:i A',$now)
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
                $this->delivery->send('missed',[
                    'caller'=>(string)$call['src'],
                    'extension'=>(string)$call['dst'],
                    'time'=>(string)$call['calldate']
                ],[(string)$call['dst']]);
            }
            $state['seen']=array_filter($seen,static function($time)use($now){return (int)$time>$now-3600;});
            $dirty=true;
        }
        if ($dirty) $this->module->setConfig('phonecontent_notification_state',$state);
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
            $stmt=$db->prepare("SELECT uniqueid,src,dst,calldate FROM cdr WHERE calldate >= ? AND disposition IN ('NO ANSWER','BUSY') AND dst REGEXP '^[0-9]+$' ORDER BY calldate DESC LIMIT 100");
            $stmt->execute([$start]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('SCCP notification CDR check failed: '.$e->getMessage());
            return [];
        }
    }
}
