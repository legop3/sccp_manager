<?php
require_once __DIR__.'/../sccpManClasses/serviceRegistry.class.php';
use FreePBX\modules\Sccp_manager\serviceRegistry;
$r=new serviceRegistry();
$a=$r->manifests();
foreach(['catdog','carrot-try','count','talking-clock','media-player'] as $id)
    if(!isset($a[$id]))throw new RuntimeException('Missing service '.$id);
$menu=serviceRegistry::menu('Services',[['A & B','http://example.org/?x=1&y=2']]);
$xml=new DOMDocument();
if(!@$xml->loadXML($menu,LIBXML_NONET))throw new RuntimeException('Bad menu XML');
if($xml->getElementsByTagName('URL')->item(0)->textContent!=='http://example.org/?x=1&y=2')
    throw new RuntimeException('URL escaping failed');
foreach(['catdog','carrot-try','talking-clock'] as $id){
    $fn=require __DIR__.'/../serviceApps/'.$id.'/Service.php';
    $ctx=['route'=>static function($v=[]){return 'http://localhost/?'.http_build_query($v);},
          'menu'=>[serviceRegistry::class,'menu'],
          'text'=>[serviceRegistry::class,'text']];
    $doc=new DOMDocument();
    if(!@$doc->loadXML($fn($ctx,[]),LIBXML_NONET))
        throw new RuntimeException('Invalid '.$id.' home menu');
}
echo "Service registry and app menus OK\n";

foreach(['serviceAppStorage','serviceAppNotifications','serviceAppAudio',
         'serviceAppMedia','serviceAppInput','serviceAppScreens'] as $name) {
    require_once __DIR__.'/../sccpManClasses/'.$name.'.php';
    $class='FreePBX\\modules\\Sccp_manager\\'.$name;
    if(!class_exists($class))throw new RuntimeException('Missing capability '.$name);
}
foreach(['animal','clockCall','mediaTest','broadcast','cooldown','transaction'] as $method) {
    if(method_exists(serviceRegistry::class,$method))
        throw new RuntimeException('Registry still owns application-specific logic '.$method);
}
$media=file_get_contents(__DIR__.'/../serviceApps/media-player/Service.php');
if(strpos($media,'cooldown')!==false)
    throw new RuntimeException('Media benchmark must not have a cooldown');
$inputs=new \FreePBX\modules\Sccp_manager\serviceAppInput();
$xml=new DOMDocument();
if(!@$xml->loadXML($inputs->prompt('Example','Enter value','http://localhost/','Value','value'),LIBXML_NONET))
    throw new RuntimeException('Invalid Cisco input document');
echo "Service capabilities and cooldown regression checks passed\n";

/* Exercise the actual registry dispatch path, not only direct app callbacks. */
require_once __DIR__.'/../sccpManClasses/phonevoicemail.class.php';
$fakeModule=new stdClass();
$registry=new serviceRegistry($fakeModule);
foreach(['catdog','carrot-try','talking-clock','media-player'] as $id) {
    ob_start();
    try {
        $registry->handle($id,[], 'SEP000000000001',
            ['line'=>'150','extension'=>'150'],
            static function(array $q){return 'http://localhost/service.php?'.http_build_query($q);});
        $result=ob_get_clean();
    }catch(Throwable $e){ob_end_clean();throw $e;}
    $document=new DOMDocument();
    if(!@$document->loadXML($result,LIBXML_NONET))
        throw new RuntimeException('Registry cannot dispatch '.$id);
}
$storage=new \FreePBX\modules\Sccp_manager\serviceAppStorage('count');
$temp=tempnam(sys_get_temp_dir(),'sccp-app-test-');
if($temp===false)throw new RuntimeException('Cannot initialize test storage');
try {
    $property=new ReflectionProperty($storage,'path');
    $property->setAccessible(true);
    $property->setValue($storage,$temp);
    $first=$storage->transaction(static function(array &$state){
        $state['count']=(int)($state['count']??0)+1;
        return $state['count'];
    });
    $second=$storage->transaction(static function(array &$state){
        $state['count']=(int)($state['count']??0)+1;
        return $state['count'];
    });
    if($first!==1 || $second!==2)
        throw new RuntimeException('Shared service state did not persist');
    if($storage->cooldown('user111',3600)!==0 ||
        $storage->cooldown('user111',3600)<=0)
        throw new RuntimeException('Cooldown did not apply');
    $storage->releaseCooldown('user111');
    if($storage->cooldown('user111',3600)!==0)
        throw new RuntimeException('Failed cooldown cannot be retried');
} finally { @unlink($temp); }
foreach(['carrot-try','talking-clock','media-player'] as $id) {
    $body=file_get_contents(__DIR__.'/../serviceApps/'.$id.'/Service.php');
    if(strpos($body,'cooldown(')!==false)
        throw new RuntimeException('Unexpected cooldown in '.$id);
}
echo "Registry runtime dispatch and storage checks passed\n";
