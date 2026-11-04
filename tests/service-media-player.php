<?php
/** Media player file, decoding and AudioSocket integration regression tests. */
require_once __DIR__.'/../sccpManClasses/serviceMediaLibrary.php';
use FreePBX\modules\Sccp_manager\serviceMediaLibrary as Library;
function playerCheck($ok,$msg) {if(!$ok)throw new RuntimeException($msg);}
foreach(['../admin','123','/etc/passwd','abcdef'] as $bad) {
    try {Library::path($bad);throw new RuntimeException('unsafe file id accepted');}
    catch(InvalidArgumentException $expected){}
}
$player=file_get_contents(__DIR__.'/../scripts/phone-media-play.php');
$images=file_get_contents(__DIR__.'/../scripts/phone-media-images.php');
$library=file_get_contents(__DIR__.'/../sccpManClasses/serviceMediaLibrary.php');
$service=file_get_contents(__DIR__.'/../serviceApps/media-player/Service.php');
$admin=file_get_contents(__DIR__.'/../views/content.services.php');
$page=file_get_contents(__DIR__.'/../page.sccp_content.php');
$install=file_get_contents(__DIR__.'/../install.php');
$ajax=file_get_contents(__DIR__.'/../sccpManTraits/ajaxHelper.php');
playerCheck(str_contains($player,'application AudioSocket ') &&
    str_contains($player,"'pipe:1'") &&
    str_contains($player,"'-atomic_writing','1'") &&
    str_contains($player,"'-update','1'") &&
    str_contains($player,"showfreqs=s=296x156") &&
    str_contains($player,'format=pal8') &&
    str_contains($player,'phone-media-images.php') &&
    !str_contains($player,'$screens->push($url)') &&
    str_contains($images,'$screens->push($url)') &&
    str_contains($images,'is_file($ack)') &&
    str_contains($images,'image URL unavailable') &&
    str_contains($images,"$"+"record('error'") &&
    str_contains($images,"$"+"record('first push accepted") &&
    str_contains($player,"$"+"imageProcess=proc_open") &&
    !str_contains($player,"$"+"imageCmd.' >/dev/null") &&
    str_contains($player,'unpack(\'n\',substr($incomingBuffer,1,2))'),
    'One-process live audio/video and fetch-driven display must be preserved');
playerCheck(str_contains($library,'is_uploaded_file') &&
    str_contains($library,'move_uploaded_file') &&
    str_contains($library,'ffprobe') &&
    str_contains($library,'public static function chunk(') &&
    str_contains($library,'upload session mismatch') &&
    !str_contains($library,'262144000') &&
    !str_contains($library,'/var/www/html'),
    'Media uploads must remain private and validated');
playerCheck(str_contains($service,"'op'=>'play'") &&
    str_contains($service,"'op'=>'stop'") &&
    str_contains($service,"$"+"media->imageStatus()") &&
    str_contains($admin,'command=media_chunk') &&
    str_contains($admin,'file.slice(') &&
    str_contains($ajax,"case 'media_chunk':") &&
    str_contains($admin,'media_delete') &&
    str_contains($page,'media_upload') &&
    str_contains($install,'sccp-manager-media'),
    'Phone and admin media actions or install are missing');
if(is_executable('/usr/bin/ffmpeg')) {
    $dir=sys_get_temp_dir().'/sccp-player-'.bin2hex(random_bytes(5));
    mkdir($dir,0700);
    try {
        $audio=$dir.'/audio.wav';
        $video=$dir.'/video.mkv';
        $commands=[
            ['/usr/bin/ffmpeg','-hide_banner','-loglevel','error',
                '-f','lavfi','-i','sine=frequency=440:sample_rate=44100',
                '-t','0.5','-y',$audio],
            ['/usr/bin/ffmpeg','-hide_banner','-loglevel','error',
                '-f','lavfi','-i','testsrc2=size=296x156:rate=24',
                '-f','lavfi','-i','sine=frequency=440:sample_rate=44100',
                '-t','0.5','-c:v','ffv1','-c:a','pcm_s16le','-y',$video]
        ];
        foreach($commands as $cmd) {
            $p=proc_open($cmd,[0=>['file','/dev/null','r'],
                1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
            playerCheck(is_resource($p) && proc_close($p)===0,'Synthetic media creation failed');
        }
        foreach([[$audio,false],[$video,true]] as [$source,$hasVideo]) {
            $png=$dir.'/latest.png';$pcm=$dir.'/audio.raw';
            $cmd=['/usr/bin/ffmpeg','-hide_banner','-loglevel','error',
                '-protocol_whitelist','file,pipe','-i',$source];
            if($hasVideo)$cmd=array_merge($cmd,
                ['-map','0:a:0','-vn','-ac','1','-ar','8000','-c:a','pcm_s16le',
                 '-f','s16le',$pcm,'-map','0:v:0','-an','-vf',
                 'scale=296:156:force_original_aspect_ratio=decrease,pad=296:156:(ow-iw)/2:(oh-ih)/2,format=pal8',
                 '-c:v','png','-f','image2','-update','1','-atomic_writing','1','-y',$png]);
            else $cmd=array_merge($cmd,
                ['-filter_complex',
                '[0:a:0]asplit=2[pcm][visual];[visual]showfreqs=s=296x156:mode=bar:rate=30,format=pal8[v]',
                 '-map','[pcm]','-ac','1','-ar','8000','-c:a','pcm_s16le',
                 '-f','s16le',$pcm,'-map','[v]','-c:v','png','-f','image2',
                 '-update','1','-atomic_writing','1','-y',$png]);
            $p=proc_open($cmd,[0=>['file','/dev/null','r'],
                1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
            playerCheck(is_resource($p) && proc_close($p)===0,'FFmpeg dual-output failed');
            playerCheck(is_file($png) && substr(file_get_contents($png),0,8)==="\x89PNG\r\n\x1a\n" &&
                ord(file_get_contents($png)[25])===3 &&
                is_file($pcm) && filesize($pcm)>=6400,
                'FFmpeg must produce PCM and PNG simultaneously');
        }
    }finally{
        foreach(glob($dir.'/*')?:[] as $p)@unlink($p);
        @rmdir($dir);
    }
}
echo "Media player library, phone UI, AudioSocket and single FFmpeg dual outputs passed\n";
