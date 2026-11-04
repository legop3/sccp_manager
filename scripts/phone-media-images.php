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
    $base=$module->phonecontent->baseUrl($device);
    $url=$base.'/media-frame.php?'.http_build_query([
        'phone'=>$device,'session'=>$run,'key'=>$key
    ],'','&',PHP_QUERY_RFC3986);
    // Reject a missing frame or broken HTTP endpoint before pushing the
    // phone into a permanently "requesting..." screen. Same check as the
    // previously proven synthetic adaptive test.
    $record('waiting for first frame');
    $ready=microtime(true)+15;
    while($active() && !is_file($frame) && microtime(true)<$ready)usleep(10000);
    if(!$active() || !is_file($frame))
        throw new RuntimeException('first video frame not generated');
    if(!function_exists('curl_init'))throw new RuntimeException('cURL unavailable');
    $record('checking frame URL');
    $check=curl_init($url);
    if(!$check)throw new RuntimeException('cannot check frame URL');
    try {
        curl_setopt_array($check,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>2,
            CURLOPT_TIMEOUT=>4,
            CURLOPT_FOLLOWLOCATION=>false
        ]);
        $bytes=curl_exec($check);
        $status=(int)curl_getinfo($check,CURLINFO_RESPONSE_CODE);
    }finally{curl_close($check);}
    if($status!==200 || !is_string($bytes) ||
       substr($bytes,0,8)!=="\x89PNG\r\n\x1a\n")
        throw new RuntimeException('image URL unavailable (HTTP '.$status.')');
    // Probe's ACK is not a real handset GET.
    if(is_file($ack) && !is_link($ack))@unlink($ack);
    $sent=0;$received=0;
    $record('pushing images');
    while($active()) {
        if(is_file($ack) && !is_link($ack))@unlink($ack);
        if(!$screens->push($url))
            throw new RuntimeException('handset rejected media image push');
        $sent++;
        if($sent===1)$record('first push accepted; waiting for phone GET');
        $until=microtime(true)+5;
        $loaded=false;
        while($active() && microtime(true)<$until) {
            if(is_file($ack) && !is_link($ack)) {
                @unlink($ack);$received++;$loaded=true;
                if($received===1 || $received%50===0)$record('phone fetching images', $received.' completed GETs');
                break;
            }
            usleep(10000); // ACK polling only; no image FPS limiter.
        }
        if(!$loaded) {
            if($active())throw new RuntimeException('handset never completed image GET');
            break;
        }
    }
    $record('finished',$sent.' pushes, '.$received.' completed GETs');
    error_log(sprintf('SCCP media images %s: %d pushes, %d GETs',$device,$sent,$received));
}catch(\Throwable $e){
    $record('error',$e->getMessage());
    error_log('SCCP media images '.$device.': '.$e->getMessage());
}
