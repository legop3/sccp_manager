<?php
require_once __DIR__.'/../sccpManClasses/serviceRegistry.class.php';
use FreePBX\modules\Sccp_manager\serviceRegistry;

class CatdogFakeStorage {
    public $remaining=0;
    public $claims=0;
    public $refunds=0;
    public function cooldownRemaining($user) {return $this->remaining;}
    public function cooldown($user,$seconds) {
        $this->claims++;
        if($this->remaining>0)return $this->remaining;
        $this->remaining=$seconds;
        return 0;
    }
    public function releaseCooldown($user) {$this->refunds++;$this->remaining=0;}
}
class CatdogFakeNotifications {
    public $accepted=2;
    public $connected=2;
    public $deferred=1;
    public $calls=[];
    public function broadcast($title,$caption,$image,$scope,$requester,$deferRequester=false) {
        $this->calls[]=compact('title','caption','image','scope','requester','deferRequester');
        return [$this->accepted,$this->connected,$this->deferred];
    }
}
function checkCatdog($ok,$message) {
    if(!$ok)throw new RuntimeException($message);
}
$fn=require __DIR__.'/../serviceApps/catdog/Service.php';
$meta=json_decode(file_get_contents(__DIR__.'/../serviceApps/catdog/manifest.json'),true);
$settings=[];
foreach($meta['settings'] as $key=>$field)$settings[$key]=$field['default'];
$storage=new CatdogFakeStorage();
$notifications=new CatdogFakeNotifications();
$ctx=[
    'config'=>$settings,'storage'=>$storage,'notifications'=>$notifications,
    'device'=>'SEP000000000001','user'=>'111',
    'menu'=>[serviceRegistry::class,'menu'],
    'text'=>[serviceRegistry::class,'text'],
    'route'=>static function(array $data=[]) {
        return 'http://localhost/sccp-manager/service.php?'.
            http_build_query(array_merge(['app'=>'catdog'],$data));
    }
];
$menu=function(array $query=[])use($fn,&$ctx) {
    $result=$fn($ctx,$query);
    $doc=new DOMDocument();
    if(!@$doc->loadXML($result,LIBXML_NONET) ||
       $doc->documentElement->tagName!=='CiscoIPPhoneMenu')
        throw new RuntimeException('catdog did not return a valid menu');
    $entries=[];
    foreach($doc->getElementsByTagName('MenuItem') as $item) {
        $entries[]=[
            $item->getElementsByTagName('Name')->item(0)->textContent,
            $item->getElementsByTagName('URL')->item(0)->textContent
        ];
    }
    return $entries;
};
$entries=$menu();
$labels=array_column($entries,0);
checkCatdog(in_array('send a cat',$labels,true) && in_array('send a dog',$labels,true),
    'Both animals should be available initially');
checkCatdog(in_array('refresh status',$labels,true) &&
    in_array('services',$labels,true),'Expected navigation entries missing');
checkCatdog(str_contains(end($entries)[1],'app=home'),
    'Services entry must leave catdog');

$storage->remaining=3730;
$entries=$menu();
checkCatdog(str_contains(implode(' ',array_column($entries,0)),'ready in 1h 3m'),
    'Cooldown must be shown in hours and minutes');
checkCatdog(!in_array('send a cat',array_column($entries,0),true),
    'Cannot offer an action during cooldown');
$menu(['op'=>'cat']);
checkCatdog($storage->claims===1 && !$notifications->calls,
    'An attempt during cooldown must not fetch or deliver an image');

$storage->remaining=0;
$ctx['config']['allow_cats']=false;
$entries=$menu();
checkCatdog(!in_array('send a cat',array_column($entries,0),true) &&
    in_array('send a dog',array_column($entries,0),true),
    'Disabled animals must disappear from the menu');
$menu(['op'=>'cat']);
checkCatdog($storage->claims===1,'Disabled animals must not claim cooldown');
$ctx['config']['allow_cats']=true;

$source=require __DIR__.'/../serviceApps/catdog/AnimalSource.php';
$makePhoto=static function($kind,$width,$height) {
    $image=imagecreatetruecolor($width,$height);
    imagefilledrectangle($image,0,0,$width-1,$height-1,
        imagecolorallocate($image,210,110,60));
    ob_start();
    try {
        if($kind==='png')imagepng($image);
        else imagejpeg($image,null,90);
        return ob_get_contents();
    } finally {ob_end_clean();imagedestroy($image);}
};
$photo=$makePhoto('png',700,600);
$normalized=$source('cat',static function($url,$limit)use($photo) {
    if($url!=='https://cataas.com/cat')throw new RuntimeException('Wrong cat URL');
    return $photo;
});
$info=getimagesizefromstring($normalized);
checkCatdog($info[2]===IMAGETYPE_JPEG && $info[0]===420 && $info[1]===360,
    'Large animal photo must resize and encode to a bounded JPEG');
checkCatdog(strlen($normalized)<1048576,
    'Normalized photo must fit notification image size limit');
$dogPhoto=$makePhoto('jpeg',250,180);
$dog=$source('dog',static function($url,$limit)use($dogPhoto) {
    if($url==='https://dog.ceo/api/breeds/image/random')
        return json_encode(['status'=>'success',
            'message'=>'https://images.dog.ceo/breeds/lab/test.jpg']);
    if($url==='https://images.dog.ceo/breeds/lab/test.jpg')return $dogPhoto;
    throw new RuntimeException('Dog source URL was not validated');
});
checkCatdog(getimagesizefromstring($dog)[2]===IMAGETYPE_JPEG,
    'Dog API images must use the same image normalization');
foreach(['invalid', '<html>not image</html>'] as $bad) {
    try {
        $source('cat',static function()use($bad){return $bad;});
        throw new RuntimeException('Malformed image was accepted');
    } catch(RuntimeException $expected) {
        if(str_contains($expected->getMessage(),'Malformed image was accepted'))throw $expected;
    }
}
try {
    $source('dog',static function($url) {
        return '{"status":"success","message":"http://127.0.0.1/private.jpg"}';
    });
    throw new RuntimeException('Unsafe animal image URL accepted');
} catch(RuntimeException $expected) {
    if(str_contains($expected->getMessage(),'Unsafe animal image URL accepted'))throw $expected;
}
try {
    $source('cat',static function() {return str_repeat('x',6291457);});
    throw new RuntimeException('Oversized animal image accepted');
} catch(RuntimeException $expected) {
    if(str_contains($expected->getMessage(),'Oversized animal image accepted'))throw $expected;
}

$ctx['animalSource']=static function($kind)use($normalized){return $normalized;};
$ctx['config']['delivery']='self';
$storage->remaining=0;
$notifications->accepted=1;
$notifications->connected=1;
$entries=$menu(['op'=>'cat']);
checkCatdog($storage->remaining===3600,'Success must retain cooldown');
checkCatdog(count($notifications->calls)===1,'Only one notification request expected');
checkCatdog($notifications->calls[0]['scope']==='self','Scope setting ignored');
checkCatdog(str_contains(implode(' ',array_column($entries,0)),'yours next'),
    'Sender must be told its popup follows the menu response');
checkCatdog($notifications->calls[0]['deferRequester']===true,
    'catdog must schedule sender popup after its XML response');

$storage->remaining=0;
$notifications->accepted=0;
$notifications->connected=2;
$notifications->deferred=1;
$entries=$menu(['op'=>'dog']);
checkCatdog($storage->refunds===0 && $storage->remaining===3600,
    'Unacknowledged deliveries must not create duplicate retries');
checkCatdog(str_contains(implode(' ',array_column($entries,0)),'yours next'),
    'Sender should still receive deferred popup when other pushes are rejected');

$storage->remaining=0;
$notifications->connected=0;
$entries=$menu(['op'=>'cat']);
checkCatdog($storage->refunds===1 && $storage->remaining===0,
    'No connected target should refund cooldown');

$ctx['animalSource']=static function(){throw new RuntimeException('Offline');};
$notifications->connected=1;
$entries=$menu(['op'=>'dog']);
checkCatdog($storage->refunds===2 && $storage->remaining===0,
    'Failed image source should refund cooldown');
checkCatdog(str_contains(implode(' ',array_column($entries,0)),'try dog again'),
    'Image failure should offer retry');
echo "catdog image, menu and delivery tests passed\n";
