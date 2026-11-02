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
$c=new notificationConfig($m);
checkAppearance($c->appearance()['theme']==='cisco','Only shared appearance defaults are supported');
$m->data['phonecontent_notification_appearance']=['theme'=>'forest','colors'=>['header'=>'#ABCDEF']];
checkAppearance($c->appearance()['theme']==='forest','Shared appearance overrides per-type themes');
checkAppearance($c->appearance()['colors']['header']==='#ABCDEF','Shared custom colors persist');
$worker=file_get_contents(__DIR__.'/../sccpManClasses/notificationDelivery.class.php');
$service=file_get_contents(__DIR__.'/../sccpManClasses/phoneservices.class.php');
checkAppearance(str_contains($worker,'$appearance=$config->appearance()') && substr_count($worker,'$renderFields,$appearance,$device)')===1,
 'Voicemail and missed-call renderers use shared design');
checkAppearance(substr_count($worker,'phoneNotifications::graphic(')===1,'All events use one render call');
checkAppearance(str_contains($service,'->appearance()'),'Service sends use shared design');
$view=file_get_contents(__DIR__.'/../views/content.notifications.php');
checkAppearance(substr_count($view,'Design & layout')===1,'Single design editor');
checkAppearance(str_contains($view,'notifications[appearance][theme]'),'Editor submits shared theme');
$renderer=file_get_contents(__DIR__.'/../sccpManClasses/phoneNotifications.class.php');
$graphic=substr($renderer,strpos($renderer,'public static function graphic('));
checkAppearance(strpos($graphic,"$kind==='voicemail'")===false &&
    strpos($graphic,"$kind==='missed'")===false,
    'Graphical renderer must not branch on sender type');
foreach (["'title'","'primary'","'secondary'","'detail'","'timestamp'"] as $field)
    checkAppearance(strpos($graphic,$field)!==false,'Renderer supports generic field '.$field);
$view=file_get_contents(__DIR__.'/../views/content.notifications.php');
checkAppearance(strpos($view,'show_number')===false && strpos($view,'show_details')===false,
    'Appearance editor must not expose caller-specific content flags');
checkAppearance(strpos($graphic,'$kind')===false,
    'Image renderer cannot depend on notification kind');
checkAppearance(strpos($renderer,'public static function text(')===false &&
    strpos($renderer,'public static function status(')===false &&
    strpos($renderer,'public static function interactive(')===false,
    'No competing text/status notification renderers');
echo "Shared notification appearance tests passed\n";
