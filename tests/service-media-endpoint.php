<?php
/** The production frame endpoint must serve a real session ticket to a reader. */
$source=file_get_contents(__DIR__.'/../services/media-frame.php');
$player=file_get_contents(__DIR__.'/../scripts/phone-media-play.php');
$images=file_get_contents(__DIR__.'/../scripts/phone-media-images.php');
function checkFrame($ok,$reason){if(!$ok)throw new RuntimeException($reason);}
checkFrame(str_contains($player,'!chmod($tmp,0644)') &&
    str_contains($player,"'key_hash'=>hash('sha256',$secret)") &&
    str_contains($images,"hash_equals($meta['key_hash'],hash('sha256',$key))") &&
    str_contains($source,"hash_equals($meta['key_hash'],hash('sha256',$key))"),
    'Player session ticket must be readable by Apache without disclosing URL key');
$dir=sys_get_temp_dir().'/sccp-frame-'.bin2hex(random_bytes(6));
checkFrame(mkdir($dir,0700),'unable to make test directory');
$device='SEP001122334455';
$run=bin2hex(random_bytes(8));
$key=bin2hex(random_bytes(16));
$metaPath=$dir.'/sccp-media-'.$device.'.json';
$framePath=$dir.'/sccp-media-'.$device.'-'.$run.'.png';
$ackPath=$dir.'/sccp-media-'.$device.'-'.$run.'.ack';
$script=$dir.'/endpoint.php';
$runner=$dir.'/run.php';
try {
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lWkAAAAASUVORK5CYII=');
    file_put_contents($framePath,$png);
    $modified=str_replace("$"."root='/var/spool/asterisk';",
        "$"."root=".var_export($dir,true).";",$source);
    checkFrame($modified!==$source,'Cannot override test frame root');
    file_put_contents($script,$modified);
    foreach(['hash','legacy'] as $mode) {
        $state=['run'=>$run,'expires'=>time()+120];
        if($mode==='hash')$state['key_hash']=hash('sha256',$key);
        else $state['key']=$key;
        file_put_contents($metaPath,json_encode($state));
        chmod($metaPath,0644);
        foreach([$key,str_repeat('0',32)] as $requestKey) {
            $params=['phone'=>$device,'session'=>$run,'key'=>$requestKey];
            file_put_contents($runner,"<?php\n".'$'."_"."GET=".
                var_export($params,true).";\nrequire ".var_export($script,true).";\n");
            $proc=proc_open([PHP_BINARY,$runner],[0=>['file','/dev/null','r'],
                1=>['pipe','w'],2=>['pipe','w']],$pipes);
            checkFrame(is_resource($proc),'Cannot launch endpoint test');
            $body=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);
            checkFrame(proc_close($proc)===0,'Endpoint script failed: '.$stderr);
            if($requestKey===$key)
                checkFrame($body===$png && is_file($ackPath),
                    'Valid '.$mode.' session did not serve and ACK the image');
            else
                checkFrame($body==='', 'Incorrect URL key served image');
            @unlink($ackPath);
        }
    }
}finally{
    foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);
}
echo "Frame endpoint serves PNG with hashed and legacy sessions and refuses wrong keys\n";
