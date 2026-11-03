<?php
require_once __DIR__.'/../sccpManClasses/serviceRegistry.class.php';
use FreePBX\modules\Sccp_manager\serviceRegistry;
$r=new serviceRegistry();
$a=$r->manifests();
foreach(['random-animals','carrot-roulette','shared-counter','talking-clock','media-player'] as $id)
    if(!isset($a[$id]))throw new RuntimeException('Missing service '.$id);
$menu=serviceRegistry::menu('Services',[['A & B','http://example.org/?x=1&y=2']]);
$xml=new DOMDocument();
if(!@$xml->loadXML($menu,LIBXML_NONET))throw new RuntimeException('Bad menu XML');
if($xml->getElementsByTagName('URL')->item(0)->textContent!=='http://example.org/?x=1&y=2')
    throw new RuntimeException('URL escaping failed');
foreach(['random-animals','carrot-roulette','talking-clock'] as $id){
    $fn=require __DIR__.'/../serviceApps/'.$id.'/Service.php';
    $ctx=['route'=>static function($v=[]){return 'http://localhost/?'.http_build_query($v);},
          'menu'=>[serviceRegistry::class,'menu'],
          'text'=>[serviceRegistry::class,'text']];
    $doc=new DOMDocument();
    if(!@$doc->loadXML($fn($ctx,[]),LIBXML_NONET))
        throw new RuntimeException('Invalid '.$id.' home menu');
}
echo "Service registry and app menus OK\n";
