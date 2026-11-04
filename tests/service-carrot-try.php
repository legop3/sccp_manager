<?php
require_once __DIR__.'/../sccpManClasses/serviceRegistry.class.php';
use FreePBX\modules\Sccp_manager\serviceRegistry;

$fn=require __DIR__.'/../serviceApps/carrot-try/Service.php';
$delivery=new class {
    public $calls=[];
    public $reply=[1,2,1];
    public function broadcast($title,$message,$image,$scope,$sender,$defer=false) {
        $this->calls[]=compact('title','message','image','scope','sender','defer');
        return $this->reply;
    }
};
$ctx=[
    'config'=>['one_in'=>235],
    'device'=>'SEP000000000001',
    'notifications'=>$delivery,
    'route'=>static fn(array $params=[])=>'http://example.org/?'.http_build_query($params),
    'text'=>[serviceRegistry::class,'text'],
    'menu'=>[serviceRegistry::class,'menu'],
    'randomInt'=>static fn($low,$high)=>1
];
function checkCarrot($yes,$reason) {if(!$yes)throw new RuntimeException($reason);}
$result=$fn($ctx,['op'=>'roll']);
$xml=new DOMDocument();
checkCarrot(@$xml->loadXML($result,LIBXML_NONET),'Winning result must be valid phone XML');
checkCarrot(count($delivery->calls)===1,'Win must create one broadcast');
$call=$delivery->calls[0];
checkCarrot($call['scope']==='all','Winning carrot must reach every connected handset');
checkCarrot($call['sender']==='SEP000000000001' && $call['defer']===true,
    'Winning carrot must include originating handset after menu response');
checkCarrot(str_contains($result,'you will see it too'),
    'Sender should get confirmation that their notification will display');
checkCarrot(substr($call['image'],0,8)==="\x89PNG\r\n\x1a\n",
    'Carrot should still supply its image to the existing notification renderer');
$ctx['randomInt']=static fn($low,$high)=>$high;
$lose=$fn($ctx,['op'=>'roll']);
checkCarrot(str_contains($lose,'no carrot this time') && count($delivery->calls)===1,
    'Losing rolls must not send notifications');
$delivery->reply=[0,0,0];
$ctx['randomInt']=static fn($low,$high)=>1;
$empty=$fn($ctx,['op'=>'roll']);
checkCarrot(str_contains($empty,'no connected phones found'),
    'Disconnected phone result must not imply delivery');
echo "carrot try broadcasts to everyone including the sender; wins and losses verified\n";
