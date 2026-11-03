<?php
require_once __DIR__.'/../sccpManClasses/notificationConfig.class.php';
use FreePBX\modules\Sccp_manager\notificationConfig;
function checkHelp($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
class HelpPhoneContentFixture {
    public $path;
    public function __construct($path){$this->path=$path;}
    public function ringtones(){return [];}
    public function publicPath(){return $this->path;}
}
class HelpModuleFixture {
    public $data=[];
    public $phonecontent;
    public $regenerated=0;
    public function __construct($path){$this->phonecontent=new HelpPhoneContentFixture($path);}
    public function getConfig($key){return $this->data[$key]??null;}
    public function setConfig($key,$value){$this->data[$key]=$value;}
    public function phoneContentRegenerate($force=false){$this->regenerated++;}
}
$dir=sys_get_temp_dir().'/sccp-help-test-'.bin2hex(random_bytes(6));
mkdir($dir,0700);
try {
    $module=new HelpModuleFixture($dir);
    $config=new notificationConfig($module);
    checkHelp($config->helpButton()['action']==='default','Default Help action changed');
    $config->save(['help_button'=>['action'=>'notifications']]);
    checkHelp($config->helpButton()['action']==='notifications','Open Notifications setting was not stored');
    checkHelp($module->regenerated===1,'Help configuration did not trigger phone reprovisioning');
    $config->save(['help_button'=>['action'=>'custom','url'=>'https://example.com/phone']]);
    checkHelp($config->helpButton()['url']==='https://example.com/phone','Custom URL was not persisted');
    foreach (['file:///etc/passwd','javascript:alert(1)',''] as $url) {
        try {
            $config->save(['help_button'=>['action'=>'custom','url'=>$url]]);
            throw new RuntimeException('Invalid custom URL accepted: '.$url);
        } catch (InvalidArgumentException $expected) {}
    }
    $config->save(['help_button'=>['action'=>'default']]);
    checkHelp($config->helpButton()['action']==='default','Default selection was not restored');
    $manager=file_get_contents(__DIR__.'/../Sccp_manager.class.php');
    checkHelp(str_contains($manager,"dev_informationURL'") &&
        str_contains($manager,"'op'=>'list'") &&
        str_contains($manager,"tokenForDevice(\$dev_id)"),
        'Help button must use per-device authenticated notification URLs');
    checkHelp(!str_contains($manager,"'dev_informationURL' => '/notification"),
        'Global informationURL must not be overwritten unconditionally');
    $queue=file_get_contents(__DIR__.'/../sccpManClasses/notificationQueue.class.php');
    checkHelp(str_contains($queue,"\$op==='list'") && str_contains($queue,'No notifications'),
        'Queue Help entry must support empty inbox');
    $view=file_get_contents(__DIR__.'/../views/content.notifications.php');
    checkHelp(str_contains($view,'notifications[help_button][action]') &&
        str_contains($view,'Open Notifications'),'Help setting missing from Notifications editor');
} finally {
    foreach (glob($dir.'/*')?:[] as $file) unlink($file);
    rmdir($dir);
}
echo "Help button settings and queue entry checks passed\n";
