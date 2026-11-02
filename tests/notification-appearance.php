<?php
require_once __DIR__.'/../sccpManClasses/notificationConfig.class.php';
use FreePBX\modules\Sccp_manager\notificationConfig;
class AppearanceFixture {
    public $data=[];
    public function getConfig($key){return $this->data[$key]??null;}
    public function setConfig($key,$value){$this->data[$key]=$value;}
}
function checkAppearance($ok,$why){if(!$ok)throw new RuntimeException($why);}
$m=new AppearanceFixture();
$m->data['phonecontent_notifications']=[
 'voicemail'=>['theme'=>'plum','stripes'=>['#123456'],'stripe_position'=>'header'],
 'missed'=>['theme'=>'ocean']
];
$c=new notificationConfig($m);
checkAppearance($c->appearance()['theme']==='plum','Existing voicemail theme must migrate');
checkAppearance($c->appearance()['stripes']===['#123456'],'Existing stripes migrate');
$m->data['phonecontent_notification_appearance']=['theme'=>'forest','colors'=>['header'=>'#ABCDEF']];
checkAppearance($c->appearance()['theme']==='forest','Shared appearance overrides per-type themes');
checkAppearance($c->appearance()['colors']['header']==='#ABCDEF','Shared custom colors persist');
$worker=file_get_contents(__DIR__.'/../sccpManClasses/notificationDelivery.class.php');
$service=file_get_contents(__DIR__.'/../sccpManClasses/phoneservices.class.php');
checkAppearance(str_contains($worker,'$appearance=$config->appearance()') && substr_count($worker,'[],$appearance,$device)')===2,
 'Voicemail and missed-call renderers use shared design');
checkAppearance(str_contains($worker,'$kindSettings=$appearance'),'Scheduled uses shared design');
checkAppearance(str_contains($service,'->appearance()'),'Service sends use shared design');
$view=file_get_contents(__DIR__.'/../views/content.notifications.php');
checkAppearance(substr_count($view,'Design & layout')===1,'Single design editor');
checkAppearance(str_contains($view,'notifications[appearance][theme]'),'Editor submits shared theme');
echo "Shared notification appearance tests passed\n";
