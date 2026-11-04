<?php
require_once __DIR__.'/../sccpManClasses/serviceAppStorage.php';
require_once __DIR__.'/../sccpManClasses/serviceRegistry.class.php';

use FreePBX\modules\Sccp_manager\serviceAppStorage;
use FreePBX\modules\Sccp_manager\serviceRegistry;

function countAssert($yes,$reason) {
    if(!$yes) throw new RuntimeException($reason);
}
$fn=require __DIR__.'/../serviceApps/count/Service.php';
$temp=tempnam(sys_get_temp_dir(),'sccp-count-');
if($temp===false)throw new RuntimeException('No temporary file');
function countStore($path) {
    $storage=new serviceAppStorage('count');
    $property=new ReflectionProperty($storage,'path');
    $property->setAccessible(true);
    $property->setValue($storage,$path);
    return $storage;
}
function countResponse($fn,$storage,$op='') {
    $ctx=[
        'storage'=>$storage,
        'route'=>static fn(array $args=[])=>'http://example.org/service.php?'.http_build_query($args),
        'menu'=>[serviceRegistry::class,'menu'],
        'text'=>[serviceRegistry::class,'text']
    ];
    $raw=$fn($ctx,$op===''?[]:['op'=>$op]);
    $doc=new DOMDocument();
    countAssert(@$doc->loadXML($raw,LIBXML_NONET),'Invalid Cisco XML');
    return [$doc,$raw];
}
try {
    file_put_contents($temp,json_encode(['count'=>19,'other'=>'keep']));
    $one=countStore($temp);
    [$doc,$raw]=countResponse($fn,$one);
    countAssert(str_contains($raw,'count: 19'),'Existing counter was reset');
    $names=[];
    foreach($doc->getElementsByTagName('MenuItem') as $item)
        $names[]=$item->getElementsByTagName('Name')->item(0)->textContent;
    countAssert($names===['add 1','refresh'],'Only Add 1 and Refresh are allowed');
    countAssert(json_decode(file_get_contents($temp),true)['count']===19,
        'Viewing the counter changed its value');
    [, $raw]=countResponse($fn,$one,'increment');
    countAssert(str_contains($raw,'count: 20'),'Increment did not add exactly one');
    $two=countStore($temp);
    [, $raw]=countResponse($fn,$two);
    countAssert(str_contains($raw,'count: 20'),
        'Counter not persistent across storage instances');

    [, $bad]=countResponse($fn,$two,'reset');
    countAssert(str_contains($bad,'unknown counter action'),
        'Reset must not be supported');
    [, $bad]=countResponse($fn,$two,'decrement');
    countAssert(str_contains($bad,'unknown counter action'),
        'Decrease must not be supported');
    countAssert(json_decode(file_get_contents($temp),true)['count']===20,
        'Unsupported actions changed persistent state');

    if(function_exists('pcntl_fork') && function_exists('pcntl_waitpid')) {
        $children=[];
        for($worker=0;$worker<6;$worker++) {
            $pid=pcntl_fork();
            if($pid===-1)throw new RuntimeException('Unable to fork counter worker');
            if($pid===0) {
                try {
                    $own=countStore($temp);
                    for($i=0;$i<12;$i++)countResponse($fn,$own,'increment');
                    exit(0);
                }catch(Throwable $e) {
                    fwrite(STDERR,$e->getMessage()."\n");
                    exit(1);
                }
            }
            $children[]=$pid;
        }
        foreach($children as $pid) {
            pcntl_waitpid($pid,$status);
            countAssert(pcntl_wifexited($status) && pcntl_wexitstatus($status)===0,
                'Concurrent increment worker failed');
        }
        $two=countStore($temp);
        [, $raw]=countResponse($fn,$two);
        countAssert(str_contains($raw,'count: 92'),
            'Concurrent increments were lost');
    }

    // Bad data must fail without rewriting or resetting the counter.
    file_put_contents($temp,'{"count":"9223372036854775808"}');
    $before=file_get_contents($temp);
    try {
        countResponse($fn,countStore($temp),'increment');
        throw new RuntimeException('Overflow value accepted');
    }catch(RuntimeException $e) {
        if($e->getMessage()==='Overflow value accepted')throw $e;
    }
    countAssert(file_get_contents($temp)===$before,
        'Malformed persistent counter was overwritten');
} finally {
    @unlink($temp);
}
echo "Count persistence, increment-only menu, concurrent writes and no-reset checks passed\n";
