<?php
/**
 * Called from the SCCP Manager cron entry. Samples every five seconds for
 * near-live voicemail and missed-call alerts without a permanent daemon.
 */
if (PHP_SAPI!=='cli') {http_response_code(403);exit(1);}
ini_set('display_errors','0');
try {
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    $module=\FreePBX::create()->Sccp_manager;
    $lockDir=is_dir('/run/asterisk')?'/run/asterisk':'/var/lib/asterisk';
    $lock=@fopen($lockDir.'/sccp-manager-notifications.lock','c');
    if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) exit(0);
    $once=in_array('--once',$argv,true);
    $loops=$once?1:11;
    for ($n=0;$n<$loops;$n++) {
        try {
            $module->notificationWorker->tick();
        } catch (\Throwable $e) {
            error_log('SCCP Manager notifications: '.$e->getMessage());
        }
        if ($n+1<$loops) sleep(5);
    }
    flock($lock,LOCK_UN);
    fclose($lock);
} catch (\Throwable $e) {
    error_log('SCCP Manager notification startup: '.$e->getMessage());
    exit(1);
}
