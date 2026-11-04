<?php
/** Service names, menu labels, informational text and popups use lowercase. */
require_once __DIR__.'/../sccpManClasses/serviceRegistry.class.php';
use FreePBX\modules\Sccp_manager\serviceRegistry;

function lowercaseCheck($ok,$reason) {
    if(!$ok)throw new RuntimeException($reason);
}
function assertLowerText($xml,$context) {
    $doc=new DOMDocument();
    lowercaseCheck(@$doc->loadXML($xml,LIBXML_NONET),'Invalid XML for '.$context);
    $xpath=new DOMXPath($doc);
    foreach($xpath->query('//Title|//Prompt|//Text|//MenuItem/Name') as $node) {
        $value=$node->textContent;
        lowercaseCheck($value===strtolower($value),
            'Uppercase text in '.$context.': '.$value);
    }
}
$registry=new serviceRegistry();
$manifests=$registry->manifests();
foreach($manifests as $id=>$manifest) {
    lowercaseCheck($manifest['name']===strtolower($manifest['name']),
        'Service name not lowercase: '.$id);
    foreach(($manifest['settings']??[]) as $field) {
        lowercaseCheck($field['label']===strtolower($field['label']),
            'Settings label not lowercase: '.$id);
    }
}
assertLowerText(serviceRegistry::menu('UPPER',[
    ['GO NOW','http://example.org/?case=KeepUpper']
]),'shared service menu');
assertLowerText(serviceRegistry::text('UPPER','MIXED Content'),'shared service text');

$cat=require __DIR__.'/../serviceApps/catdog/Service.php';
$photoSource=static fn($type)=>'image-bytes';
$notifications=new class {
    public $fields=[];
    public function broadcast($title,$caption,$image,$scope,$requester,$defer) {
        $this->fields[]=compact('title','caption','scope','requester','defer');
        return [1,1,0];
    }
};
$storage=new class {
    public function cooldownRemaining($user){return 0;}
    public function cooldown($user,$seconds){return 0;}
    public function releaseCooldown($user){}
};
$ctx=[
    'config'=>['cooldown_minutes'=>60,'allow_cats'=>true,'allow_dogs'=>true,'delivery'=>'all'],
    'route'=>static fn(array $args=[])=>'http://example.org/?'.http_build_query($args),
    'menu'=>[serviceRegistry::class,'menu'],
    'text'=>[serviceRegistry::class,'text'],
    'user'=>'111','device'=>'SEP000000000001',
    'storage'=>$storage,'notifications'=>$notifications,'animalSource'=>$photoSource
];
assertLowerText($cat($ctx,[]),'catdog menu');
assertLowerText($cat($ctx,['op'=>'cat']),'catdog send');
assertLowerText($cat($ctx,['op'=>'dog']),'catdog dog send');
lowercaseCheck($notifications->fields[0]['caption']==='a new cat is here',
    'Cat popup message must match requested copy');
lowercaseCheck($notifications->fields[1]['caption']==='a new dog is here',
    'Dog popup message should follow matching style');
foreach($notifications->fields as $fields)
    lowercaseCheck($fields['title']===strtolower($fields['title']),
        'Animal popup title must be lowercase');
$source=file_get_contents(__DIR__.'/../sccpManClasses/phoneservices.class.php');
lowercaseCheck(str_contains($source,"'screen off'") &&
    str_contains($source,"'services'"),'Main Services menu must be lowercase');
$adapter=file_get_contents(__DIR__.'/../sccpManClasses/serviceAppNotifications.php');
lowercaseCheck(str_contains($adapter,"['open'=>" ) &&
    str_contains($adapter,"strtolower(date('m/d/Y g:i A'))"),
    'Service notification softkey and timestamp must use lowercase');
echo "All service names, menu copy, and catdog notification text are lowercase\n";
