<?php
require_once __DIR__.'/../sccpManClasses/serviceAppConfig.php';
use FreePBX\modules\Sccp_manager\serviceAppConfig;
class ServiceSettingsFixture {
    public $saved=[];
    public function getConfig($key){return $this->saved[$key]??null;}
    public function setConfig($key,$value){$this->saved[$key]=$value;}
}
function settingsAssert($ok,$message) {
    if(!$ok)throw new RuntimeException($message);
}
$m=new ServiceSettingsFixture();
$m->saved['phonecontent_weather']=['name'=>'My Town','lat'=>40.124,'lon'=>-83.42];
$m->saved['phonecontent_news_feed']='https://feeds.npr.org/1001/rss.xml';
$config=new serviceAppConfig($m);
$defs=$config->definitions();
foreach(['weather','news','catdog','carrot-try','talking-clock','media-player'] as $id)
    settingsAssert(!empty($defs[$id]),'Missing declared options: '.$id);
settingsAssert($config->get('weather')['name']==='My Town','Weather location not preserved');
settingsAssert($config->get('news')['feed']==='https://feeds.npr.org/1001/rss.xml','News source not preserved');
settingsAssert($config->get('catdog')['cooldown_minutes']===60,'Default cooldown missing');
$post=[];
foreach($defs as $id=>$fields) {
    if(!$fields)continue;
    $post[$id]=[];
    foreach($config->get($id) as $key=>$value) {
        // HTML checkbox false is represented by an omitted field.
        if($value===false)continue;
        $post[$id][$key]=$value===true?'1':(string)$value;
    }
}
$post['weather']['name']='Somewhere Else';
$post['catdog']['cooldown_minutes']='12';
$post['catdog']['allow_dogs']='1';
$post['carrot-try']['one_in']='500';
$post['talking-clock']['format']='24h';
$post['media-player']['test_frames']='48';
$prepared=$config->prepare($post);
settingsAssert($prepared['catdog']['cooldown_minutes']===12,'Integer setting not validated');
settingsAssert($prepared['carrot-try']['one_in']===500,'Odds setting not validated');
settingsAssert($prepared['talking-clock']['format']==='24h','Format setting not validated');
$old=$config->apply($prepared);
settingsAssert($m->saved['phonecontent_weather']['name']==='Somewhere Else',
    'Weather app did not save to original config key');
settingsAssert($m->saved['phonecontent_news_feed']==='https://feeds.npr.org/1001/rss.xml',
    'News app did not retain original saved feed');
settingsAssert($config->get('media-player')['test_frames']===48,'Media benchmark option not saved');
settingsAssert($config->get('catdog')['cooldown_minutes']===12,'App option not persisted');
$config->restore($old);
settingsAssert($m->saved['phonecontent_weather']['name']==='My Town',
    'Legacy weather settings rollback failed');
$invalid=$post;
$invalid['catdog']['cooldown_minutes']='0';
try {$config->prepare($invalid);throw new RuntimeException('Out-of-range cooldown accepted');}
catch(InvalidArgumentException $expected){}
$invalid=$post;
$invalid['news']['feed']='https://example.org/rss';
try {$config->prepare($invalid);throw new RuntimeException('Unapproved news feed accepted');}
catch(InvalidArgumentException $expected){}
$invalid=$post;
$invalid['weather']['lat']='91';
try {$config->prepare($invalid);throw new RuntimeException('Invalid latitude accepted');}
catch(InvalidArgumentException $expected){}
$invalid=$post;
$invalid['catdog']['extra']='bad';
try {$config->prepare($invalid);throw new RuntimeException('Unknown setting accepted');}
catch(InvalidArgumentException $expected){}
$view=file_get_contents(__DIR__.'/../views/content.services.php');
settingsAssert(str_contains($view,"$".'definitions') &&
    str_contains($view,"app_settings[") &&
    str_contains($view,"'settings'")===false,
    'Expected manifest-driven Services controls');
$handler=file_get_contents(__DIR__.'/../sccpManClasses/phonecontent.class.php');
settingsAssert(str_contains($handler,'->prepare(') &&
    !str_contains($handler,"$".'post'."['weather_lat']"),
    'Legacy per-service save handling was not replaced');
echo "Manifest-defined Services configuration and existing settings tests passed\n";
