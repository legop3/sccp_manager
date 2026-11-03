<?php
namespace FreePBX\modules\Sccp_manager;
/** Alarm scheduler entrypoint; existing saved alarm state is unchanged. */
return static function($module) {
    require_once __DIR__.'/../../sccpManClasses/serviceLegacySupport.php';
    $legacy=new serviceLegacySupport($module);
    require_once __DIR__.'/../../sccpManClasses/serviceAppNotifications.php';
    $notifications=new serviceAppNotifications($module,'alarms');
    $run=static function()use($legacy,$notifications) {
        $due=$legacy->transact(function(&$s){
            $due=[];
            foreach(($s['alarms']??[]) as $device=>$alarms) {
                if(!phonevoicemail::validDevice($device)||!is_array($alarms))continue;
                foreach($alarms as $id=>$alarm){
                    if(($alarm['when']??PHP_INT_MAX)<=time()){
                        if(($alarm['when']??0)>=time()-600)$due[]=array_merge($alarm,['device'=>$device]);
                        unset($s['alarms'][$device][$id]);
                    }
                }
            }
            return $due;
        });
        foreach($due as $alarm) {
            $device=$alarm['device'];
            try {
                // Alarms target the physical handset, not a shared public number.
                $line=$legacy->module->phonevoicemail->lineForDevice($device);
                if(!preg_match('/^[0-9]{1,16}$/D',$line))continue;
                $message=(string)($alarm['note']??'Wake-up alarm');
                $notifications->send(
                    ['title'=>'Reminder','primary'=>$message],$device,
                    static function($phone)use($legacy,$message) {
                        return ['Open'=>$legacy->url($phone).
                            '&app=alarms&op=notice&note='.rawurlencode($message)];
                    }
                );
                $cmd='/usr/sbin/asterisk -rx '.escapeshellarg(
                    'channel originate Local/'.$line.'@from-internal extension s@sccp-manager-services-alarm');
                $out=[];$status=0;exec($cmd.' 2>&1',$out,$status);
                if($status!==0)error_log('SCCP alarm originate failed for '.$line.': '.implode(' ',array_slice($out,-3)));
            } catch(\Throwable $e) {
                error_log('SCCP alarm notification: '.$e->getMessage());
            }
        }
    };
    $run();
};
