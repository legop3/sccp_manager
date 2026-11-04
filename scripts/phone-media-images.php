<?php
/**
 * Cisco LCD transport for a live media session.
 * Intentionally separate from the AudioSocket PCM pump: HTTP/CGI latency
 * must never block real-time audio. This does NOT launch another FFmpeg.
 * Keep one URL and wait for each handset GET completion before re-pushing.
 */
ini_set('display_errors','0');
if(PHP_SAPI!=='cli')exit(1);
$device=(string)($argv[1]??'');
$run=(string)($argv[2]??'');
$key=(string)($argv[3]??'');
if(!preg_match('/^SEP[A-F0-9]{12}$/D',$device) ||
   !preg_match('/^[a-f0-9]{16}$/D',$run) ||
   !preg_match('/^[a-f0-9]{32}$/D',$key))exit(1);
$root='/var/spool/asterisk';
$metaPath=$root.'/sccp-media-'.$device.'.json';
$frame=$root.'/sccp-media-'.$device.'-'.$run.'.png';
$ack=$root.'/sccp-media-'.$device.'-'.$run.'.ack';
$stop=$root.'/sccp-media-'.$device.'-'.$run.'.stop';
$statusFile=$root.'/sccp-media-'.$device.'-images.json';
$record=static function($phase,$detail='')use($statusFile,$run) {
    if(is_link($statusFile))return;
    $json=json_encode(['run'=>$run,'phase'=>$phase,'detail'=>substr((string)$detail,0,180),
        'at'=>time()],JSON_UNESCAPED_SLASHES);
    if(is_string($json)) {
        @file_put_contents($statusFile,$json,LOCK_EX);
        @chmod($statusFile,0600);
    }
};
$active=static function()use($metaPath,$run,$key,$stop) {
    if(is_file($stop) || !is_file($metaPath) || is_link($metaPath))return false;
    $meta=json_decode((string)@file_get_contents($metaPath),true);
    return is_array($meta) && ($meta['run']??null)===$run &&
        ((is_string($meta['key_hash']??null) &&
          hash_equals($meta['key_hash'],hash('sha256',$key))) ||
         (is_string($meta['key']??null) && hash_equals($meta['key'],$key))) &&
        (int)($meta['expires']??0)>=time();
};
try {
    $record('starting');
    if(!$active())throw new RuntimeException('session ended before image delivery began');
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    $module=\FreePBX::create()->Sccp_manager;
    require_once __DIR__.'/../sccpManClasses/serviceAppScreens.php';
    $screens=new \FreePBX\modules\Sccp_manager\serviceAppScreens($module,$device);
    // Use the exact static PNG publication/CGI push path proven by
    // phone-media-test.php. Do not use the dynamic media-frame.php endpoint.
    // Audio stays in the independent AudioSocket worker.
    $record('waiting for first frame');
    $ready=microtime(true)+15;
    while($active() && !is_file($frame) && microtime(true)<$ready)usleep(10000);
    if(!$active() || !is_file($frame))
        throw new RuntimeException('first video frame not generated');
    $record('pushing images via tested static PNG transport');
    $sent=0;
    $lastHash='';
    $files=[];
    $lastPush=0.0;
    while($active()) {
        $png=@file_get_contents($frame);
        if(!is_string($png) || strlen($png)<8 ||
           substr($png,0,8)!=="\\x89PNG\\r\\n\\x1a\\n") {
            usleep(50000);
            continue;
        }
        $hash=hash('sha256',$png);
        if($hash===$lastHash) { usleep(50000); continue; }
        // Preserve the original working test's 750 ms phone-fetch pacing.
        $elapsed=microtime(true)-$lastPush;
        if($lastPush>0 && $elapsed<0.75) {
            usleep((int)((0.75-$elapsed)*1000000));
            continue;
        }
        $name='media-demo-'.$run.'-'.$sent;
        if(!$screens->frame($name,$png,$files))
            throw new RuntimeException('phone rejected static image push');
        $sent++;
        $lastHash=$hash;
        $lastPush=microtime(true);
        if($sent===1 || $sent%50===0)
            $record('phone image pushes accepted',$sent.' static frames pushed');
        // Retain two previous URLs for the 7945G's asynchronous PNG fetch,
        // but prevent a long playback from filling the public directory.
        while(count($files)>3) {
            $old=array_shift($files);
            $screens->cleanup([$old]);
        }
    }
    $record('finished',$sent.' static frames pushed');
    error_log(sprintf('SCCP media images %s: %d static PNG pushes',$device,$sent));
} catch(\Throwable $e) {
    $record('error',$e->getMessage());
    error_log('SCCP media images '.$device.': '.$e->getMessage());
} finally {
    // Keep final URLs briefly: accepted CGI push is not a completed GET.
    if(isset($files) && is_array($files) && isset($screens)) {
        sleep(3);
        $screens->cleanup($files);
    }
}
