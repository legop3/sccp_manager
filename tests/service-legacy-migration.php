<?php
/** Regression checks for migrating the five original phone services. */
require_once __DIR__.'/../sccpManClasses/serviceRegistry.class.php';
require_once __DIR__.'/../sccpManClasses/serviceLegacySupport.php';
require_once __DIR__.'/../sccpManClasses/phonevoicemail.class.php';
use FreePBX\modules\Sccp_manager\serviceRegistry;
$fixtureModule=new class {
    public function getConfig($key){return null;}
    public function setConfig($key,$value){}
};
$registry=new serviceRegistry($fixtureModule);
$manifests=$registry->manifests();
$expected=['weather','messages','alarms','news','games'];
if(array_slice(array_keys($manifests),0,5)!==$expected)
    throw new RuntimeException('Legacy service order changed');
foreach($expected as $id) {
    $metadata=$manifests[$id]??null;
    if(!is_array($metadata) || empty($metadata['builtin']))
        throw new RuntimeException('Not registered as a built-in app: '.$id);
    $app=require __DIR__.'/../serviceApps/'.$id.'/Service.php';
    if(!is_callable($app))throw new RuntimeException('Invalid '.$id.' service');
}
$legacy= new \FreePBX\modules\Sccp_manager\serviceLegacySupport(new stdClass());
$fixture=[
    'device'=>'SEP000000000001','user'=>'150','legacy'=>$legacy,
    'route'=>static function(array $q=[]) {
        return 'http://localhost/service.php?'.http_build_query($q);
    }
];
// These routes don't call FreePBX, the network, or Asterisk.
foreach([['games',[]],['games',['op'=>'trivia','id'=>0]],
         ['messages',[]],['alarms',['op'=>'set']]] as [$id,$query]) {
    ob_start();
    try {
        $registry->handle($id,$query,$fixture['device'],
            ['line'=>'150','extension'=>'150'],$fixture['route']);
        $xml=ob_get_clean();
    }catch(Throwable $e){ob_end_clean();throw $e;}
    $doc=new DOMDocument();
    if(!@$doc->loadXML($xml,LIBXML_NONET))
        throw new RuntimeException('Registry did not render '.$id);
}
$services=file_get_contents(__DIR__.'/../sccpManClasses/phoneservices.class.php');
foreach($expected as $id)
    if(strpos($services,"if(\$app==='".$id."'")!==false)
        throw new RuntimeException('Duplicate legacy '.$id.' handler remains');
$ticker=require __DIR__.'/../serviceApps/alarms/Tick.php';
if(!is_callable($ticker))throw new RuntimeException('Alarms scheduler missing');
$source=file_get_contents(__DIR__.'/../sccpManClasses/serviceLegacySupport.php');
if(strpos($source,"sccp-manager-services.json")===false)
    throw new RuntimeException('Shared inbox/alarm/game state path changed');
echo "Legacy service registry, dispatch, and scheduler checks passed\n";
