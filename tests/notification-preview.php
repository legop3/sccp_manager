<?php
require_once __DIR__.'/../sccpManClasses/phoneNotifications.class.php';
require_once __DIR__.'/../sccpManClasses/notificationConfig.class.php';
use FreePBX\modules\Sccp_manager\phoneNotifications;
use FreePBX\modules\Sccp_manager\notificationConfig;
function previewCheck($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
class PreviewContent {
    public $path;
    function __construct($path){$this->path=$path;}
    function publicPath(){return $this->path;}
    function baseUrl($device=null){return 'http://192.168.0.25/sccp-manager';}
}
class PreviewModule {
    public $phonecontent;
    public $data=[];
    function __construct($path){$this->phonecontent=new PreviewContent($path);}
    function getConfig($key){return $this->data[$key]??null;}
    function setConfig($key,$value){$this->data[$key]=$value;}
}
$path=sys_get_temp_dir().'/notification-preview-'.bin2hex(random_bytes(6));
mkdir($path,0700);
try {
    $m=new PreviewModule($path);
    $config=new notificationConfig($m);
    $settings=$config->previewAppearance([
        'theme'=>'custom','colors'=>[
            'background'=>'#112233','header'=>'#334455','header_text'=>'#FFFFFF',
            'details_background'=>'#123456','accent'=>'#EDAC24'
        ],'stripe_position'=>'off'
    ]);
    previewCheck($config->appearance()['theme']==='cisco','Preview must not save appearance');
    $fields=['title'=>'Preview','primary'=>'Example','secondary'=>'Second',
        'detail'=>'More','timestamp'=>'11/03/2026 8:45 PM'];
    $png=phoneNotifications::graphic($m,$fields,$settings,null,true);
    previewCheck(is_string($png) && substr($png,0,8)==="\x89PNG\r\n\x1a\n",'Preview must return raw PNG bytes');
    previewCheck(count(glob($path.'/*'))===0,'Preview must not write public PNG files');
    previewCheck(strpos($png,'192.168.0.25')===false,'Preview must not expose private PBX URL');
    $im=imagecreatefromstring($png);
    previewCheck($im && imagesx($im)===296 && imagesy($im)===156,'Preview dimensions');
    $accent=imagecolorsforindex($im,imagecolorat($im,280,29));
    previewCheck($accent['red']===237 && $accent['green']===172 && $accent['blue']===36,'Accent setting must alter pixels');
    $detail=imagecolorsforindex($im,imagecolorat($im,281,141));
    previewCheck($detail['red']===18 && $detail['green']===52 && $detail['blue']===86,'Details background must alter pixels');
    imagedestroy($im);
    $sample=imagecreatetruecolor(20,20);
    imagefilledrectangle($sample,0,0,19,19,imagecolorallocate($sample,250,10,20));
    ob_start();imagepng($sample);$bytes=ob_get_clean();imagedestroy($sample);
    $pngImage=phoneNotifications::graphic($m,$fields+['image'=>$bytes],$settings,null,true);
    $im=imagecreatefromstring($pngImage);
    $pixel=imagecolorsforindex($im,imagecolorat($im,60,80));
    previewCheck($pixel['red']===250 && $pixel['green']===10,'Image layout must render source image in preview');
    imagedestroy($im);
    previewCheck(count(glob($path.'/*'))===0,'Image preview must not publish files');
    $view=file_get_contents(__DIR__.'/../views/content.notifications.php');
    previewCheck(str_contains($view,"command:'notification_preview'"),'Live preview must call authenticated AJAX endpoint');
    previewCheck(str_contains($view,"name.startsWith('notifications[appearance]')"),'Preview must include stripe input settings');
    previewCheck(str_contains($view,"<option value=\"image\">"),'Image sample must be available');
    previewCheck(!str_contains($view,'Shared design preview'),'Static LAN-only preview must be removed');
    $ajax=file_get_contents(__DIR__.'/../sccpManTraits/ajaxHelper.php');
    previewCheck(str_contains($ajax,"case 'notification_preview':"),'Preview endpoint missing');
} finally {
    foreach(glob($path.'/*')?:[] as $file)unlink($file);
    rmdir($path);
}
echo "Live notification preview renderer and wiring tests passed\n";
