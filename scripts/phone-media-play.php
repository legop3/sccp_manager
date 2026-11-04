<?php
/**
 * One FFmpeg process: decoded PCM on stdout and the latest PNG via image2.
 * AudioSocket is a per-session loopback TCP listener, not a public daemon.
 */
ini_set('display_errors','0');
if(PHP_SAPI!=='cli')exit(1);
$device=(string)($argv[1]??'');
$id=(string)($argv[2]??'');
$line=(string)($argv[3]??'');
$stopUrl=(string)($argv[4]??'');
$menuUrl=(string)($argv[5]??'');
if(!preg_match('/^SEP[A-F0-9]{12}$/D',$device) ||
   !preg_match('/^[a-f0-9]{24}$/D',$id) ||
   !preg_match('/^[0-9]{1,16}$/D',$line) ||
   strlen($stopUrl)>1024 || strlen($menuUrl)>1024 ||
   !str_starts_with($stopUrl,'http://') || !str_starts_with($menuUrl,'http://'))exit(1);
require_once __DIR__.'/../sccpManClasses/serviceMediaLibrary.php';
use FreePBX\modules\Sccp_manager\serviceMediaLibrary as Library;
$root='/var/spool/asterisk';
$lock=@fopen($root.'/sccp-media-test-'.$device.'.lock','c+');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))exit(0);
$ff=null;$imageProcess=null;$socket=null;$server=null;$meta='';$publicMeta='';$frame='';$ack='';$stop='';
try {
    $entry=Library::find($id);
    $source=Library::path($id);
    if(!is_executable('/usr/bin/ffmpeg'))throw new RuntimeException('ffmpeg missing');
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    $module=\FreePBX::create()->Sccp_manager;
    $run=bin2hex(random_bytes(8));
    $secret=bin2hex(random_bytes(16));
    $uuid=bin2hex(random_bytes(16));
    $uuid=substr($uuid,0,8).'-'.substr($uuid,8,4).'-'.substr($uuid,12,4).'-'.substr($uuid,16,4).'-'.substr($uuid,20);
    $uuidBytes=hex2bin(str_replace('-','',$uuid));
    $meta=$root.'/sccp-media-'.$device.'.json';
    // HTTP-facing assets must reside where Apache can read and write them.
    // The private media library and now-playing metadata remain in Asterisk spool.
    $publicRoot=$module->phonecontent->publicPath();
    if(!is_dir($publicRoot) || is_link($publicRoot) || !is_writable($publicRoot))
        throw new RuntimeException('public media frame directory unavailable');
    $publicMeta=$publicRoot.'/sccp-media-'.$device.'.json';
    $frame=$publicRoot.'/sccp-media-'.$device.'-'.$run.'.png';
    $ack=$publicRoot.'/sccp-media-'.$device.'-'.$run.'.ack';
    $stop=$root.'/sccp-media-'.$device.'-'.$run.'.stop';
    if(is_link($meta) || is_link($publicMeta) || is_link($frame) || is_link($ack) || is_link($stop))
        throw new RuntimeException('unsafe media session path');
    $state=json_encode(['run'=>$run,'key_hash'=>hash('sha256',$secret),'expires'=>time()+max(90,(int)$entry['duration']+45),
        'file'=>$id,'name'=>$entry['name'],'started'=>time(),'device'=>$device],
        JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $tmp=$meta.'.'.$run.'.tmp';
    if(file_put_contents($tmp,$state,LOCK_EX)!==strlen($state) ||
       !chmod($tmp,0644) || !rename($tmp,$meta))
        throw new RuntimeException('cannot register media session');
    // Minimal HTTP ticket: no media filename, line, caller or raw URL key.
    $httpState=json_encode(['run'=>$run,'key_hash'=>hash('sha256',$secret),
        'expires'=>time()+max(90,(int)$entry['duration']+45)],JSON_THROW_ON_ERROR);
    $httpTemp=$publicMeta.'.'.$run.'.tmp';
    if(file_put_contents($httpTemp,$httpState,LOCK_EX)!==strlen($httpState) ||
       !chmod($httpTemp,0644) || !rename($httpTemp,$publicMeta))
        throw new RuntimeException('cannot publish HTTP media ticket');
    $server=@stream_socket_server('tcp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
    if(!$server)throw new RuntimeException('cannot open local audio socket: '.$error);
    $address=stream_socket_get_name($server,false);
    $port=(int)substr(strrchr($address,':'),1);
    if($port<1024 || $port>65535)throw new RuntimeException('invalid audio socket port');
    $cmd='channel originate SCCP/'.$line.'/aa=1w/ringer=silent application AudioSocket '.
        $uuid.',127.0.0.1:'.$port;
    exec('/usr/sbin/asterisk -rx '.escapeshellarg($cmd).' >/dev/null 2>&1 </dev/null &');
    $socket=@stream_socket_accept($server,25);
    if(!$socket)throw new RuntimeException('phone did not connect audio call');
    stream_set_timeout($socket,3);
    $handshake='';
    while(strlen($handshake)<19 && !feof($socket)) {
        $part=fread($socket,19-strlen($handshake));
        if($part===false || $part==='')break;
        $handshake.=$part;
    }
    if(strlen($handshake)!==19 || ord($handshake[0])!==1 ||
       substr($handshake,1,2)!=="\x00\x10" ||
       !hash_equals($uuidBytes,substr($handshake,3,16)))
        throw new RuntimeException('AudioSocket handshake mismatch');
    stream_set_blocking($socket,false);
    $cmd=['/usr/bin/ffmpeg','-hide_banner','-loglevel','error','-nostdin',
        '-re','-protocol_whitelist','file,pipe','-i',$source];
    if(!empty($entry['video'])) {
        $cmd=array_merge($cmd,['-map','0:a:0','-vn','-ac','1','-ar','8000',
            '-c:a','pcm_s16le','-f','s16le','pipe:1',
            '-map','0:v:0','-an','-vf',
            'scale=296:156:force_original_aspect_ratio=decrease,pad=296:156:(ow-iw)/2:(oh-ih)/2,format=pal8',
            '-c:v','png','-f','image2','-update','1','-atomic_writing','1','-y',$frame]);
    } else {
        $cmd=array_merge($cmd,['-filter_complex',
            '[0:a:0]asplit=2[pcm][visual];[visual]showfreqs=s=296x156:mode=bar:rate=30,format=pal8[v]',
            '-map','[pcm]','-ac','1','-ar','8000','-c:a','pcm_s16le','-f','s16le','pipe:1',
            '-map','[v]','-c:v','png','-f','image2','-update','1','-atomic_writing','1','-y',$frame]);
    }
    $ff=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],
        2=>['file','/dev/null','w']],$pipes);
    if(!is_resource($ff))throw new RuntimeException('could not start ffmpeg');
    stream_set_blocking($pipes[1],false);
    // Run the exact proven adaptive test's transport against real FFmpeg PNGs.
    // Start independent PHP LCD transport. HTTP pushes can take seconds;
    // they must never run in the 20 ms AudioSocket PCM send loop.
    // FFmpeg remains ONE process producing both PCM and the latest PNG.
    $imageWorker=__DIR__.'/phone-media-adaptive.php';
    if(!is_file($imageWorker))
        throw new RuntimeException('LCD transport worker missing');
    $imageStatus=$root.'/sccp-media-'.$device.'-images.json';
    if(is_link($imageStatus))throw new RuntimeException('unsafe image status path');
    @unlink($imageStatus); // retire the prior session's result
    $imageProcess=proc_open(['/usr/bin/php',$imageWorker,$device,$run,$secret],[
        0=>['file','/dev/null','r'],
        1=>['file','/dev/null','w'],
        2=>['file','/dev/null','w']
    ],$imagePipes);
    if(!is_resource($imageProcess))
        throw new RuntimeException('failed to launch image delivery worker');
    $limit=microtime(true)+min(14400,(float)$entry['duration'])+15;
    $next=microtime(true);
    $buffer='';
    $pending='';
    $incomingBuffer='';
    while(microtime(true)<$limit && !is_file($stop)) {
        // AudioSocket is framed. TCP reads can split packets or begin inside
        // arbitrary PCM samples, so never identify a hangup from raw byte 0.
        $incoming=@fread($socket,4096);
        if($incoming===false || feof($socket))break;
        if($incoming!=='')$incomingBuffer.=$incoming;
        $hangup=false;
        while(strlen($incomingBuffer)>=3) {
            $kind=ord($incomingBuffer[0]);
            $length=unpack('n',substr($incomingBuffer,1,2))[1];
            if(strlen($incomingBuffer)<3+$length)break;
            $message=substr($incomingBuffer,3,$length);
            $incomingBuffer=substr($incomingBuffer,3+$length);
            if($kind===0 || $kind===255 ||
                ($kind===3 && $message==='#')) {
                $hangup=true;
                break;
            }
        }
        if($hangup || strlen($incomingBuffer)>131072)break;
        // Keep a short bounded PCM queue; no audio file or unbounded buffering.
        if(strlen($buffer)<3200 && !feof($pipes[1])) {
            $block=@fread($pipes[1],min(1280,3200-strlen($buffer)));
            if(is_string($block) && $block!=='')$buffer.=$block;
        }
        $now=microtime(true);
        if($pending==='' && strlen($buffer)>=320 && $now>=$next) {
            $payload=substr($buffer,0,320);
            $buffer=substr($buffer,320);
            $pending="\x10".pack('n',320).$payload;
            $next=max($next+0.020,$now);
        }
        if($pending!=='') {
            $written=@fwrite($socket,$pending);
            if($written===false)break;
            if($written>0)$pending=substr($pending,$written);
        }
        $status=proc_get_status($ff);
        if(!$status['running'] && feof($pipes[1]) && strlen($buffer)<320 && $pending==='')break;
        usleep(2000); // worker I/O polling, never an image push interval
    }
    error_log('SCCP media playback finished '.$device.' file='.$id);
} catch(\Throwable $e) {
    error_log('SCCP media playback: '.$e->getMessage());
} finally {
    if(is_resource($ff)) {
        $status=proc_get_status($ff);
        if($status['running'])proc_terminate($ff);
        if(isset($pipes[1]) && is_resource($pipes[1]))fclose($pipes[1]);
        proc_close($ff);
    }
    if(is_resource($imageProcess)) {
        $imageState=proc_get_status($imageProcess);
        if($imageState['running'])proc_terminate($imageProcess);
        proc_close($imageProcess);
    }
    if(is_resource($socket))fclose($socket);
    if(is_resource($server))fclose($server);
    if($meta!=='' && is_file($meta) && !is_link($meta)) {
        $saved=json_decode((string)@file_get_contents($meta),true);
        if(is_array($saved) && isset($run) && ($saved['run']??null)===$run)
            @unlink($meta);
    }
    if($publicMeta!=='' && is_file($publicMeta) && !is_link($publicMeta)) {
        $saved=json_decode((string)@file_get_contents($publicMeta),true);
        if(is_array($saved) && isset($run) && ($saved['run']??null)===$run)
            @unlink($publicMeta);
    }
    foreach([$frame,$ack,$stop] as $p)
        if($p!=='' && is_file($p) && !is_link($p))@unlink($p);
    flock($lock,LOCK_UN);fclose($lock);
}
