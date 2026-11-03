<?php
// Render both layouts and verify real image pixels survive into Cisco PNG.
require_once __DIR__.'/../sccpManClasses/phoneNotifications.class.php';
use FreePBX\modules\Sccp_manager\phoneNotifications;
foreach (['imagecreatetruecolor','imagettftext','imagettfbbox','imagecreatefromstring'] as $fn)
    if (!function_exists($fn)) throw new RuntimeException('CI requires GD FreeType: '.$fn);
$root=sys_get_temp_dir().'/sccp-notification-image-test-'.bin2hex(random_bytes(5));
if (!mkdir($root,0700)) throw new RuntimeException('Cannot create test directory');
class NotificationImageContentFixture {
    private $path;
    public function __construct($path){$this->path=$path;}
    public function publicPath(){return $this->path;}
    public function baseUrl($device=null){return 'http://127.0.0.1/sccp-manager';}
}
class NotificationImageModuleFixture {
    public $phonecontent;
    public function __construct($path){$this->phonecontent=new NotificationImageContentFixture($path);}
}
$module=new NotificationImageModuleFixture($root);
$source=imagecreatetruecolor(60,60);
imagefilledrectangle($source,0,0,59,59,imagecolorallocate($source,230,30,45));
ob_start();imagepng($source);$image=ob_get_clean();imagedestroy($source);
$fields=['title'=>'Reminder','primary'=>'Primary','secondary'=>'Secondary',
    'detail'=>'Details','timestamp'=>'11/02/2026 6:00 PM'];
try {
    foreach ([false,true] as $hasImage) {
        $payload=$fields;
        if ($hasImage) $payload['image']=$image;
        $xml=phoneNotifications::graphic($module,$payload,['theme'=>'cisco']);
        $doc=new DOMDocument();
        if (!@$doc->loadXML($xml,LIBXML_NONET) || $doc->documentElement->tagName!=='CiscoIPPhoneImageFile')
            throw new RuntimeException('Invalid rendered Cisco XML');
        $url=$doc->getElementsByTagName('URL')->item(0)->textContent;
        $file=$root.'/'.basename(parse_url($url,PHP_URL_PATH));
        if (!is_file($file)) throw new RuntimeException('Generated PNG missing');
        $png=imagecreatefrompng($file);
        if (!$png || imagesx($png)!==296 || imagesy($png)!==156)
            throw new RuntimeException('Incorrect generated dimensions');
        $color=imagecolorsforindex($png,imagecolorat($png,60,85));
        if ($hasImage && !($color['red']>180 && $color['green']<90 && $color['blue']<100))
            throw new RuntimeException('Image not present in expected left-side area');
        if (!$hasImage && $color['red']>180 && $color['green']<90 && $color['blue']<100)
            throw new RuntimeException('Text layout unexpectedly contains image');
        imagedestroy($png);
    }
    try {
        phoneNotifications::graphic($module,$fields+['image'=>'invalid'],['theme'=>'cisco']);
        throw new RuntimeException('Invalid source image accepted');
    } catch (InvalidArgumentException $expected) {}
} finally {
    foreach (glob($root.'/*')?:[] as $file) unlink($file);
    rmdir($root);
}
echo "Text-only and image+text notification rendering passed\n";
