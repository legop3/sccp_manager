<?php
/**
 * Experimental Cisco 7945G animation benchmark.
 * Runs detached under Asterisk user after an authenticated media service action.
 * Audio is a regular SCCP call; image frames are HTTP XML pushes.
 * Hard limits: 32 frames, one worker/device, no user-supplied shell input.
 */
ini_set('display_errors','0');
if(PHP_SAPI!=='cli')exit(1);
$device=(string)($argv[1]??'');
if(!preg_match('/^SEP[A-F0-9]{12}$/D',$device))exit(1);
$lockPath='/var/spool/asterisk/sccp-media-test-'.$device.'.lock';
if(is_link($lockPath))exit(1);
$lock=@fopen($lockPath,'c+');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))exit(0);
try {
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    $module=\FreePBX::create()->Sccp_manager;
    $devices=(new \FreePBX\modules\Sccp_manager\notificationDelivery($module))->devices();
    $ip=$devices[$device]??null;
    if(!is_string($ip) || !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))
        throw new RuntimeException('Handset not connected');
    $line=$module->phonevoicemail->lineForDevice($device);
    if(!preg_match('/^[0-9]{1,16}$/D',$line))
        throw new RuntimeException('Invalid media line');
    $root=$module->phonecontent->publicPath();
    $base=$module->phonecontent->baseUrl($device);
    if(!is_dir($root) || is_link($root) || $base==='')
        throw new RuntimeException('Public media directory unavailable');
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
        for($n=0;$n<32;$n++) {
            $im=imagecreatetruecolor(296,156);
            $bg=imagecolorallocate($im,10,22,38);
            $fg=imagecolorallocate($im,70+($n*5)%175,185,240);
            $white=imagecolorallocate($im,245,245,250);
            imagefilledrectangle($im,0,0,295,155,$bg);
            $x=12+($n*8)%260;
            imagefilledellipse($im,$x,76,52,52,$fg);
            imagestring($im,5,16,10,'SCCP VIDEO TEST',$white);
            imagestring($im,4,16,136,'Frame '.($n+1).'/32',$white);
            $prefix='media-demo-'.$run.'-'.$n;
            $png=$root.'/'.$prefix.'.png';$xml=$root.'/'.$prefix.'.xml';
            if(!imagepng($im,$png)){imagedestroy($im);throw new RuntimeException('Unable to publish frame');}
            imagedestroy($im);
            chmod($png,0644);$files[]=$png;
            $doc='<?xml version="1.0" encoding="UTF-8"?>'.
                '<CiscoIPPhoneImageFile><Title>Video Test</Title>'.
                '<LocationX>0</LocationX><LocationY>0</LocationY><URL>'.
                htmlspecialchars($base.'/'.$prefix.'.png',ENT_XML1|ENT_QUOTES,'UTF-8').
                '</URL></CiscoIPPhoneImageFile>';
            if(file_put_contents($xml,$doc,LOCK_EX)===false)throw new RuntimeException('Unable to publish XML');
            chmod($xml,0644);$files[]=$xml;
            $url=$base.'/'.$prefix.'.xml';
            $request='<CiscoIPPhoneExecute><ExecuteItem URL="'.
                htmlspecialchars($url,ENT_XML1|ENT_QUOTES,'UTF-8').'"/></CiscoIPPhoneExecute>';
            $ch=curl_init('http://'.$ip.'/CGI/Execute');
            curl_setopt_array($ch,[CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>http_build_query(['XML'=>$request]),
                CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>'sccpnotify:',
                CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>3,CURLOPT_RETURNTRANSFER=>true]);
            $response=curl_exec($ch);
            $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if($code===200 && is_string($response) &&
                strpos($response,'CiscoIPPhoneError')===false)$sent++;
            // No fixed delay: the handset's CGI response determines the pace.
        }
        $seconds=max(0.001,microtime(true)-$start);
        error_log(sprintf('SCCP media frame test %s: %d/32 accepted in %.2fs (%.2f push/s)',
            $device,$sent,$seconds,$sent/$seconds));
    } finally {
        // Allow the phone time to fetch the final image before cleanup.
        sleep(10);
        foreach($files as $path)if(is_file($path) && !is_link($path))@unlink($path);
    }
} catch(\Throwable $e) {
    error_log('SCCP media frame test: '.$e->getMessage());
} finally {
    flock($lock,LOCK_UN);fclose($lock);
}
