<?php
/**
 * Adaptive single-URL frame experiment for Cisco 7945G.
 *
 * FFmpeg advances the video timeline in real time and atomically replaces one
 * private PNG. Each completed handset GET releases ONE subsequent CGI push.
 * There is no push interval, request sequence parameter or frame backlog.
 */
ini_set('display_errors','0');
if(PHP_SAPI!=='cli')exit(1);
$device=(string)($argv[1]??'');
if(!preg_match('/^SEP[A-F0-9]{12}$/D',$device))exit(1);
$root='/var/spool/asterisk';
$lockPath=$root.'/sccp-media-test-'.$device.'.lock';
if(is_link($lockPath))exit(1);
$lock=@fopen($lockPath,'c+');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))exit(0);
$process=null;$frame='';$meta='';$ack='';
try {
    if(!is_executable('/usr/bin/ffmpeg'))
        throw new RuntimeException('ffmpeg is not installed at /usr/bin/ffmpeg');
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    $module=\FreePBX::create()->Sccp_manager;
    require_once __DIR__.'/../sccpManClasses/serviceAppScreens.php';
    $screens=new \FreePBX\modules\Sccp_manager\serviceAppScreens($module,$device);
    $run=bin2hex(random_bytes(8));
    $secret=bin2hex(random_bytes(16));
    $meta=$root.'/sccp-media-'.$device.'.json';
    $frame=$root.'/sccp-media-'.$device.'-'.$run.'.png';
    $ack=$root.'/sccp-media-'.$device.'-'.$run.'.ack';
    if(is_link($frame) || is_link($meta) || is_link($ack))
        throw new RuntimeException('Unsafe media file path');
    $state=json_encode(['run'=>$run,'key'=>$secret,'ip'=>$screens->ip(),
        'expires'=>time()+40],JSON_UNESCAPED_SLASHES);
    $tmp=$meta.'.'.$run.'.tmp';
    if(file_put_contents($tmp,$state,LOCK_EX)!==strlen($state) ||
        !chmod($tmp,0600) || !rename($tmp,$meta))
        throw new RuntimeException('Unable to create media session');
    // FFmpeg's image2 muxer atomically renames a complete PNG over the same
    // destination while -re keeps the source's real-time 12 fps timeline.
    $cmd=['/usr/bin/ffmpeg','-hide_banner','-loglevel','error','-nostdin',
        '-re','-f','lavfi','-i','testsrc2=size=296x156:rate=12',
        '-t','18','-an','-c:v','png','-f','image2',
        '-update','1','-atomic_writing','1','-y',$frame];
    $process=proc_open($cmd,[
        0=>['file','/dev/null','r'],
        1=>['file','/dev/null','w'],
        2=>['file','/dev/null','w']
    ],$pipes);
    if(!is_resource($process))throw new RuntimeException('Unable to start ffmpeg');
    $deadline=microtime(true)+23;
    while(microtime(true)<$deadline && !is_file($frame))usleep(20000);
    if(!is_file($frame))throw new RuntimeException('ffmpeg produced no frames');
    $base=$module->phonecontent->baseUrl($device);
    $url=$base.'/media-frame.php?'.http_build_query([
        'phone'=>$device,'session'=>$run,'key'=>$secret
    ],'','&',PHP_QUERY_RFC3986);
    // Verify that this update actually published the HTTP frame endpoint.
    // A missing endpoint previously caused one 404 and no subsequent frames.
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is unavailable');
    $probe=curl_init($url);
    if(!$probe)throw new RuntimeException('Cannot check media frame endpoint');
    try {
        curl_setopt_array($probe,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>2,
            CURLOPT_TIMEOUT=>4,
            CURLOPT_FOLLOWLOCATION=>false
        ]);
        $body=curl_exec($probe);
        $http=(int)curl_getinfo($probe,CURLINFO_RESPONSE_CODE);
    } finally {curl_close($probe);}
    if($http!==200 || !is_string($body) ||
        substr($body,0,8)!=="\x89PNG\r\n\x1a\n")
        throw new RuntimeException('Media image endpoint failed HTTP preflight: '.$http);
    // The preflight GET is not a handset fetch.
    if(is_file($ack) && !is_link($ack))@unlink($ack);
    $sent=0;$loaded=0;$lastInode=0;
    while(microtime(true)<$deadline) {
        clearstatcache(true,$frame);
        $inode=@fileinode($frame);
        if(!$inode)break;
        if($inode===$lastInode) {
            // Poll FFmpeg's latest-frame generation, not the handset's cadence.
            $status=proc_get_status($process);
            if(!$status['running'])break;
            usleep(10000);
            continue;
        }
        // No second push can occur while the preceding image GET is in flight.
        if(is_file($ack) && !is_link($ack))@unlink($ack);
        if(!$screens->push($url)) {
            error_log('SCCP adaptive media: CGI push rejected');
            break;
        }
        $lastInode=$inode;
        ++$sent;
        $until=min($deadline,microtime(true)+5);
        $received=false;
        while(microtime(true)<$until) {
            if(is_file($ack)) {
                $received=true;
                @unlink($ack);
                ++$loaded;
                break;
            }
            usleep(10000); // ACK polling, NOT a fixed push frame interval.
        }
        if(!$received) {
            error_log('SCCP adaptive media: no image GET completion; same-URL cache or fetch failure possible');
            break;
        }
    }
    error_log(sprintf('SCCP adaptive media %s: %d pushes, %d completed image GETs',
        $device,$sent,$loaded));
} catch(\Throwable $e) {
    error_log('SCCP adaptive media: '.$e->getMessage());
} finally {
    if(is_resource($process)) {
        $status=proc_get_status($process);
        if($status['running'])proc_terminate($process);
        proc_close($process);
    }
    if($meta!=='' && is_file($meta) && !is_link($meta)) {
        $saved=json_decode((string)@file_get_contents($meta),true);
        if(is_array($saved) && isset($run) && ($saved['run']??'')===$run)
            @unlink($meta);
    }
    foreach([$ack,$frame] as $file)
        if($file!=='' && is_file($file) && !is_link($file))@unlink($file);
    flock($lock,LOCK_UN);
    fclose($lock);
}
