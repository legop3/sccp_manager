<?php
/** Existing alarms behavior, now owned by the manifest-based service. */
return static function(array $ctx,array $q) {
    $legacy=$ctx['legacy'];
    $device=$ctx['device'];
    $line=$ctx['user'];
    $route=$ctx['route'];
    $app='alarms';
    $op=(string)($q['op']??'');
    ob_start();
    try {
        $render=static function()use($legacy,$device,$line,$route,$app,$op,$q) {
        if($app==='alarms'){
            if($op==='set'){
                $legacy->form('New Alarm',$route(['app'=>'alarms','op'=>'save']),'Time HHMM (24h)','time','N');return;
            }
            if($op==='reminder'){
                $legacy->form('New Reminder',$route(['app'=>'alarms','op'=>'reminder-time']),
                    'Reminder text','note','A');return;
            }
            if($op==='reminder-time') {
                $note=trim((string)($q['note']??''));
                if($note==='' || strlen($note)>120 || preg_match('/[\x00-\x1F\x7F]/',$note)){
                    $legacy->xml('Reminder','Text must be 1-120 characters.');return;
                }
                $legacy->form('Reminder Time',$route(['app'=>'alarms','op'=>'save','note'=>$note]),
                    'Time HHMM (24h)','time','N');return;
            }
            if($op==='save'){
                $time=(string)($q['time']??'');
                $note=trim((string)($q['note']??'Wake-up alarm'));
                if(!preg_match('/^([01][0-9]|2[0-3])([0-5][0-9])$/D',$time) ||
                    $note==='' || strlen($note)>120 || preg_match('/[\x00-\x1F\x7F]/',$note)){
                    $legacy->xml('Alarm','Invalid time or reminder. Use HHMM.');return;
                }
                $when=strtotime('today '.substr($time,0,2).':'.substr($time,2,2));
                if($when<=time())$when=strtotime('tomorrow '.substr($time,0,2).':'.substr($time,2,2));
                $id=bin2hex(random_bytes(6));
                $ok=$legacy->transact(function(&$s)use($device,$line,$when,$note,$id){
                    if(!isset($s['alarms'][$device]) || !is_array($s['alarms'][$device]))
                        $s['alarms'][$device]=[];
                    if(count($s['alarms'][$device])>=15)return false;
                    $s['alarms'][$device][$id]=['line'=>$line,'when'=>$when,'note'=>$note,'id'=>$id];
                    return true;
                });
                $legacy->xml('Alarm',$ok?'Scheduled for '.date('M j g:i A',$when):'Maximum of 15 alarms.');return;
            }
            if($op==='notice'){
                $legacy->xml('Reminder',substr((string)($q['note']??'Alarm'),0,120));return;
            }
            if($op==='cancel') {
                $id=(string)($q['id']??'');
                $legacy->transact(function(&$s)use($device,$id){
                    if(preg_match('/^[a-f0-9]{12}$/D',$id))unset($s['alarms'][$device][$id]);
                });
                $legacy->xml('Alarms','Alarm cancelled.');return;
            }
            $alarms=$legacy->transact(function(&$s)use($device){return $s['alarms'][$device]??[];});
            $entries=[
                ['Set Alarm',$route(['app'=>'alarms','op'=>'set'])],
                ['Set Reminder',$route(['app'=>'alarms','op'=>'reminder'])]
            ];
            foreach(array_slice((array)$alarms,0,15) as $id=>$alarm) {
                if(!is_array($alarm)||!isset($alarm['when']))continue;
                $entries[]=['Cancel '.date('M j g:i A',$alarm['when']).' '.substr((string)($alarm['note']??''),0,14),
                    $route(['app'=>'alarms','op'=>'cancel','id'=>$id])];
            }
            $legacy->menu('Alarms & Reminders',$entries);return;
        }
        };
        $render();
        return ob_get_clean();
    } catch(\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
};
