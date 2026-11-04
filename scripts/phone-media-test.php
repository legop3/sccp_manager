<?php
/**
 * Experimental Cisco 7945G animation benchmark.
 * Runs detached under Asterisk user after an authenticated media service action.
 * Audio is a regular SCCP call; image frames are HTTP XML pushes.
 * Hard limits: 120 frames, one worker/device, no user-supplied shell input.
 */
ini_set('display_errors','0');
if(PHP_SAPI!=='cli')exit(1);
$device=(string)($argv[1]??'');
if(!preg_match('/^SEP[A-F0-9]{12}$/D',$device))exit(1);
$frames=filter_var($argv[2]??32,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>120]]);
if($frames===false)exit(1);
$lockPath='/var/spool/asterisk/sccp-media-test-'.$device.'.lock';
if(is_link($lockPath))exit(1);
$lock=@fopen($lockPath,'c+');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))exit(0);
try {
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    $module=\FreePBX::create()->Sccp_manager;
    require_once __DIR__.'/../sccpManClasses/serviceAppScreens.php';
    $screens=new \FreePBX\modules\Sccp_manager\serviceAppScreens($module,$device);
    $line=$module->phonevoicemail->lineForDevice($device);
    if(!preg_match('/^[0-9]{1,16}$/D',$line))
        throw new RuntimeException('Invalid media line');
    if(!function_exists('imagecreatetruecolor'))
        throw new RuntimeException('PHP GD is required');
    $run=bin2hex(random_bytes(8));
    $files=[];
    $command='channel originate SCCP/'.$line.
        '/aa=1w/ringer=silent extension s@sccp-manager-services-media-test';
    // Start audio without waiting for playback to finish, then push frames.
    exec('/usr/sbin/asterisk -rx '.escapeshellarg($command).' >/dev/null 2>&1 </dev/null &');
    $start=microtime(true);
    $sent=0;
    try {
        for($n=0;$n<$frames;$n++) {
            $im=imagecreatetruecolor(296,156);
            $bg=imagecolorallocate($im,10,22,38);
            $fg=imagecolorallocate($im,70+($n*5)%175,185,240);
            $white=imagecolorallocate($im,245,245,250);
            imagefilledrectangle($im,0,0,295,155,$bg);
            $x=12+($n*8)%260;
            imagefilledellipse($im,$x,76,52,52,$fg);
            imagestring($im,5,16,10,'media test',$white);
            imagestring($im,4,16,136,'frame '.($n+1).'/'.$frames,$white);
            ob_start();
            try { $ok=imagepng($im);$frame=ob_get_contents(); }
            finally { ob_end_clean(); imagedestroy($im); }
            if(!$ok || !is_string($frame))
                throw new RuntimeException('Unable to encode frame');
            if(!$screens->frame('media-demo-'.$run.'-'.$n,$frame,$files)) {
                // An accepted CGI request does not prove the image rendered.
                // Stop on failure instead of flooding the screen with errors.
                error_log('SCCP media test: stopping after rejected push at frame '.($n+1));
                break;
            }
            ++$sent;
            // 7945G fetches and renders PNGs asynchronously. CGI acceptance
            // alone is NOT backpressure; do not overwhelm its HTTP client.
            if($n+1<$frames)usleep(750000);
        }
        $seconds=max(0.001,microtime(true)-$start);
        error_log(sprintf('SCCP media frame test %s: %d/%d accepted in %.2fs (%.2f push/s)',
            $device,$sent,$frames,$seconds,$sent/$seconds));
    } finally {
        // Allow the phone time to fetch the final image before cleanup.
        sleep(30);
        $screens->cleanup($files);
    }
} catch(\Throwable $e) {
    error_log('SCCP media frame test: '.$e->getMessage());
} finally {
    flock($lock,LOCK_UN);fclose($lock);
}
