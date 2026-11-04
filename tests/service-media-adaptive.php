<?php
/** Fixed-URL adaptive media service regression checks. */
require_once __DIR__.'/../sccpManClasses/serviceAppScreens.php';
use FreePBX\modules\Sccp_manager\serviceAppScreens;

function mediaCheck($ok,$msg) {if(!$ok)throw new RuntimeException($msg);}
$url='http://10.254.77.1/sccp-manager/media-frame.php?'.
    'phone=SEP001122334455&session=0123456789abcdef&key='.
    '0123456789abcdef0123456789abcdef';
$one=serviceAppScreens::document($url);
$two=serviceAppScreens::document($url);
mediaCheck($one===$two,'Same URL must be reused for every frame');
$doc=new DOMDocument();
mediaCheck(@$doc->loadXML($one,LIBXML_NONET),'Malformed media XML');
mediaCheck($doc->getElementsByTagName('URL')->item(0)->textContent===$url,
    'Media URL was altered for cache busting');
foreach([$url.'&seq=2',
         'https://10.254.77.1/sccp-manager/media-frame.php',
         'http://10.254.77.1/sccp-manager/media-frame.php?phone=BAD'] as $bad) {
    try {serviceAppScreens::document($bad);throw new RuntimeException('Invalid media URL accepted');}
    catch(InvalidArgumentException $expected){}
}
$worker=file_get_contents(__DIR__.'/../scripts/phone-media-adaptive.php');
$endpoint=file_get_contents(__DIR__.'/../services/media-frame.php');
$publisher=file_get_contents(__DIR__.'/../sccpManClasses/phonecontent.class.php');
$media=file_get_contents(__DIR__.'/../serviceApps/media-player/Service.php');
mediaCheck(str_contains($worker,"'-atomic_writing','1'") &&
    str_contains($worker,"'-update','1'") &&
    str_contains($worker,'if(is_file($ack))') &&
    str_contains($worker,'$screens->push($url,$stopUrl,$menuUrl)'),
    'Adaptive worker must wait for GET and use atomic latest-frame update');
mediaCheck(str_contains($worker,'testsrc2=size=296x156:rate=120') &&
    !str_contains($worker,"testsrc2=size=296x156:rate=12'") &&
    !str_contains($worker,'usleep(750000)') &&
    !str_contains($worker,'seq=') &&
    !str_contains($worker,'$lastInode') &&
    !str_contains($worker,'fileinode($frame)') &&
    !str_contains($worker,'if($inode==='),
    'Adaptive worker must not wait for new frame generation or use a fixed push interval');
mediaCheck(str_contains($endpoint,"header('Cache-Control: no-store") &&
    str_contains($endpoint,"header('Content-Type: image/png')") &&
    str_contains($endpoint,'hash_equals($meta[\'key\'],$key)') &&
    !str_contains($endpoint,'hash_equals($meta[\'ip\']'),
    'Image endpoint must serve real PNG bytes with session validation');
$installer=file_get_contents(__DIR__.'/../install.php');
mediaCheck(str_contains($installer,"$".'frameTarget=$dir.\'/media-frame.php\'') &&
    str_contains($installer,'@rename($staged,$frameTarget)'),
    'Module upgrade must install the HTTP image endpoint');
mediaCheck(str_contains($worker,'Media image endpoint failed HTTP preflight:'),
    'Worker must check published image endpoint before pushing');
mediaCheck(str_contains($publisher,"'/media-frame.php'"),
    'Phone content publisher must install the live media endpoint');
mediaCheck(str_contains($media,"'op'=>'play'") && str_contains($media,"'op'=>'stop'"),
    'Services menu must expose real media playback and stop');
if(is_executable('/usr/bin/ffmpeg')) {
    $tmp=tempnam(sys_get_temp_dir(),'sccp-media-test-');
    unlink($tmp);
    $path=$tmp.'.png';
    $cmd=['/usr/bin/ffmpeg','-hide_banner','-loglevel','error','-nostdin',
        '-f','lavfi','-i','testsrc2=size=296x156:rate=120',
        '-frames:v','2','-an','-c:v','png','-f','image2','-update','1',
        '-atomic_writing','1','-y',$path];
    $proc=proc_open($cmd,[0=>['file','/dev/null','r'],
        1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    mediaCheck(is_resource($proc),'FFmpeg test could not launch');
    $status=proc_close($proc);
    mediaCheck($status===0 && is_file($path) &&
        substr(file_get_contents($path),0,8)==="\x89PNG\r\n\x1a\n",
        'FFmpeg could not atomically replace a live PNG');
    unlink($path);
}
echo "Adaptive frame URL, session auth, GET pacing, FFmpeg options and publishing passed\n";
