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
// The player supplies a live run and key; the synthetic benchmark supplies neither.
$live=isset($argv[2],$argv[3]);
$liveRun=(string)($argv[2]??'');
$liveKey=(string)($argv[3]??'');
if($live && (!preg_match('/^[a-f0-9]{16}$/D',$liveRun) ||
   !preg_match('/^[a-f0-9]{32}$/D',$liveKey)))exit(1);
$root='/var/spool/asterisk';
$lockPath=$root.'/sccp-media-test-'.$device.'.lock';
if(!$live) {
    if(is_link($lockPath))exit(1);
    $lock=@fopen($lockPath,'c+');
    if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))exit(0);
}
$process=null;$frame='';$meta='';$ack='';
$stop=$root.'/sccp-media-'.$device.'-'.$liveRun.'.stop';
$statusFile=$root.'/sccp-media-'.$device.'-images.json';
$record=static function($phase,$detail='')use($live,$statusFile,$liveRun) {
    if(!$live || is_link($statusFile))return;
    $payload=json_encode(['run'=>$liveRun,'phase'=>$phase,'detail'=>substr((string)$detail,0,180),'at'=>time()]);
    if(is_string($payload))@file_put_contents($statusFile,$payload,LOCK_EX);
};
try {
    if(!is_executable('/usr/bin/ffmpeg'))
        throw new RuntimeException('ffmpeg is not installed at /usr/bin/ffmpeg');
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    $module=\FreePBX::create()->Sccp_manager;
    require_once __DIR__.'/../sccpManClasses/serviceAppScreens.php';
    $screens=new \FreePBX\modules\Sccp_manager\serviceAppScreens($module,$device);
    $run=$live?$liveRun:bin2hex(random_bytes(8));
    $secret=$live?$liveKey:bin2hex(random_bytes(16));
    $publicRoot=$module->phonecontent->publicPath();
    if(!is_dir($publicRoot) || is_link($publicRoot) || !is_writable($publicRoot))
        throw new RuntimeException('HTTP frame directory unavailable');
    $meta=$publicRoot.'/sccp-media-'.$device.'.json';
    $frame=$publicRoot.'/sccp-media-'.$device.'-'.$run.'.png';
    $ack=$publicRoot.'/sccp-media-'.$device.'-'.$run.'.ack';
    if(is_link($frame) || is_link($meta) || is_link($ack))
        throw new RuntimeException('Unsafe media file path');
    if($live) {
        $state=json_decode((string)@file_get_contents($meta),true);
        if(!is_array($state) || ($state['run']??null)!==$run ||
            !hash_equals((string)($state['key_hash']??''),hash('sha256',$secret)))
            throw new RuntimeException('Player session does not match adaptive transport');
    } else {
        $state=json_encode(['run'=>$run,'key_hash'=>hash('sha256',$secret),'ip'=>$screens->ip(),
            'expires'=>time()+40],JSON_UNESCAPED_SLASHES);
        $tmp=$meta.'.'.$run.'.tmp';
        if(file_put_contents($tmp,$state,LOCK_EX)!==strlen($state) ||
            !chmod($tmp,0644) || !rename($tmp,$meta))
            throw new RuntimeException('Unable to create media session');
    }
    // FFmpeg's image2 muxer atomically renames a complete PNG over the same
    // destination while -re preserves real-time source timing. The synthetic
    // test source runs at a high rate; phone pushes have no FPS limit.
    if(!$live) {
        $cmd=['/usr/bin/ffmpeg','-hide_banner','-loglevel','error','-nostdin',
            '-re','-f','lavfi','-i','testsrc2=size=296x156:rate=120',
            '-t','18','-an','-c:v','png','-f','image2',
            '-update','1','-atomic_writing','1','-y',$frame];
        $process=proc_open($cmd,[
            0=>['file','/dev/null','r'],
            1=>['file','/dev/null','w'],
            2=>['file','/dev/null','w']
        ],$pipes);
        if(!is_resource($process))throw new RuntimeException('Unable to start ffmpeg');
    }
    $deadline=$live?(float)($state['expires']??time()):microtime(true)+23;
    $record('waiting for first frame');
    while(microtime(true)<$deadline && !is_file($frame) && (!$live || !is_file($stop)))usleep(20000);
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
    $record('pushing images');
    // The preflight GET is not a handset fetch.
    if(is_file($ack) && !is_link($ack))@unlink($ack);
    $sent=0;$loaded=0;$retried=0;$rejected=0;
    $exitReason='unknown';
    while(true) {
        // Capture the exact condition that ends delivery, at the moment it happens.
        if(microtime(true)>=$deadline) {$exitReason='deadline';break;}
        if($live && is_file($stop)) {$exitReason='stop_file';break;}
        if($live && !is_file($meta)) {$exitReason='session_metadata_missing';break;}
        // Each completed handset GET permits the next push, even if FFmpeg
        // has not replaced the latest PNG yet. Never wait for a new inode.
        // No second push can occur while the preceding image GET is in flight.
        if(is_file($ack) && !is_link($ack))@unlink($ack);
        if(!$screens->push($url)) {
            // A single rejected CGI request must not permanently terminate video
            // while AudioSocket and FFmpeg are still running. Give the phone
            // time to recover, then retry the same direct image push.
            ++$rejected;
            if($rejected===1 || $rejected%10===0)
                $record('recovering CGI image push',$rejected.' rejected pushes');
            continue;
        }
        ++$sent;
        if($sent===1)$record('first push accepted; waiting for phone GET');
        // Keep the GET-driven fast path, but don't stall for up to five seconds
        // or abandon the rest of playback when an ACK is lost.
        $until=min($deadline,microtime(true)+1.0);
        $received=false;
        while(microtime(true)<$until) {
            if(is_file($ack)) {
                $received=true;
                @unlink($ack);
                ++$loaded;
                if($loaded===1 || $loaded%50===0)$record('phone fetching images',$loaded.' GETs');
                break;
            }
            usleep(10000); // ACK polling, NOT a fixed push frame interval.
        }
        if(!$received) {
            // No ACK within one second: force another push of the current
            // latest-frame URL. Do not interrupt the separate audio stream.
            if($live && (is_file($stop) || !is_file($meta)))continue;
            ++$retried;
            if($retried===1 || $retried%20===0)
                $record('retrying image delivery',$retried.' timed-out GET waits');
            continue;
        }
    }
    $record('finished',$sent.' pushes; '.$loaded.' GETs; '.$retried.' retries; '.$rejected.' rejected pushes; reason='.$exitReason);
    error_log(sprintf('SCCP adaptive media %s: %d pushes, %d completed image GETs',
        $device,$sent,$loaded));
} catch(\Throwable $e) {
    $record('error',$e->getMessage());
    error_log('SCCP adaptive media: '.$e->getMessage());
} finally {
    if(is_resource($process)) {
        $status=proc_get_status($process);
        if($status['running'])proc_terminate($process);
        proc_close($process);
    }
    if(!$live && $meta!=='' && is_file($meta) && !is_link($meta)) {
        $saved=json_decode((string)@file_get_contents($meta),true);
        if(is_array($saved) && isset($run) && ($saved['run']??'')===$run)
            @unlink($meta);
    }
    if(!$live) {
        foreach([$ack,$frame] as $file)
            if($file!=='' && is_file($file) && !is_link($file))@unlink($file);
        flock($lock,LOCK_UN);
        fclose($lock);
    }
}
