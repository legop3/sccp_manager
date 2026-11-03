<?php
require_once __DIR__.'/../sccpManClasses/phoneNotifications.class.php';
require_once __DIR__.'/../sccpManClasses/notificationConfig.class.php';
use FreePBX\modules\Sccp_manager\phoneNotifications;
use FreePBX\modules\Sccp_manager\notificationConfig;
function themedAssert($ok,$why){if(!$ok)throw new RuntimeException($why);}
class ThemeContent {
  public $path;
  function __construct($path){$this->path=$path;}
  function publicPath(){return $this->path;}
  function baseUrl($device=null){return 'http://192.168.0.25/sccp-manager';}
  function wallpapers(){return [['id'=>'aabbccddeeff0011','name'=>'Saved wallpaper']];}
}
class ThemeModule {
  public $phonecontent;public $config=[];
  function __construct($path){$this->phonecontent=new ThemeContent($path);}
  function getConfig($key){return $this->config[$key]??null;}
  function setConfig($key,$v){$this->config[$key]=$v;}
}
$path=sys_get_temp_dir().'/sccp-theme-'.bin2hex(random_bytes(5));
mkdir($path,0700);
try {
 $module=new ThemeModule($path);
 $config=new notificationConfig($module);
 $defaults=$config->appearance();
 themedAssert($defaults['stripe_direction']==='horizontal','Defaults retain old stripes');
 $settings=$config->previewAppearance([
  'theme'=>'custom','colors'=>['background'=>'#101820','header'=>'#202830',
   'details_background'=>'#404850','accent'=>'#ABCDEF','border'=>'#00FF00'],
  'stripes'=>['#FF0000','#0000FF'],
  'stripe_position'=>'left','stripe_direction'=>'vertical',
  'stripe_repeat'=>2,'stripe_opacity'=>100,'stripe_thickness'=>12,
  'stripe_reverse'=>'1','background_image'=>'aabbccddeeff0011',
  'background_fit'=>'cover','background_opacity'=>100,'content_overlay'=>0,
  'gradient_target'=>'header','gradient_direction'=>'horizontal',
  'gradient_end'=>'#FFFFFF','border_width'=>2,
  'separator_width'=>3,'panel_radius'=>8
 ]);
 themedAssert($config->appearance()===$defaults,'Unsaved preview does not persist');
 foreach(['left','right','top','bottom','header','background'] as $position)
   themedAssert($config->previewAppearance(array_merge($settings,['stripe_position'=>$position]))['stripe_position']===$position,
      'Placement not supported: '.$position);
 foreach(['diagonal','horizontal','vertical'] as $direction)
   themedAssert($config->previewAppearance(array_merge($settings,['stripe_direction'=>$direction]))['stripe_direction']===$direction,
      'Direction not supported: '.$direction);
 foreach([['stripe_repeat'=>7],['stripe_opacity'=>101],['border_width'=>7],
          ['panel_radius'=>13],['background_image'=>'not-a-valid-id'],
          ['gradient_target'=>'unknown'],['gradient_end'=>'nonsense']] as $bad){
   try{$config->previewAppearance(array_merge($settings,$bad));throw new RuntimeException('Invalid option accepted: '.array_key_first($bad));}
   catch(InvalidArgumentException $expected){}
 }
 $wall=imagecreatetruecolor(320,212);
 imagefilledrectangle($wall,0,0,319,211,imagecolorallocate($wall,150,45,70));
 imagepng($wall,$path.'/wallpaper-aabbccddeeff0011.png');
 imagedestroy($wall);
 $fields=['title'=>'Reminder','primary'=>'Package','secondary'=>'Test',
          'detail'=>'Extra','timestamp'=>'11/03/2026 3:00 AM'];
 $png=phoneNotifications::graphic($module,$fields,$settings,null,true);
 themedAssert(substr($png,0,8)==="\x89PNG\r\n\x1a\n",'New editor renders a PNG');
 $img=imagecreatefromstring($png);
 themedAssert(imagesx($img)===296 && imagesy($img)===156,'Fixed screen size');
 $border=imagecolorsforindex($img,imagecolorat($img,0,0));
 themedAssert($border['green']===255 && $border['red']===0,'Configurable border is visible');
 $wallPixel=imagecolorsforindex($img,imagecolorat($img,10,70));
 themedAssert($wallPixel['red']===150 && $wallPixel['green']===45,'Wallpaper paints fixed background');
 $headerLeft=imagecolorsforindex($img,imagecolorat($img,12,15));
 $headerRight=imagecolorsforindex($img,imagecolorat($img,280,15));
 themedAssert($headerLeft!==$headerRight,'Header gradient affects real rendered pixels');
 imagedestroy($img);
 themedAssert(count(glob($path.'/notification-*.png'))===0,'Preview generates no public image');
 $settings['stripe_position']='right';$settings['stripe_direction']='horizontal';
 $settings['border_width']=0;$settings['gradient_target']='off';
 $settings['background_image']='';
 $vertical=phoneNotifications::graphic($module,$fields,$settings,null,true);
 $v=imagecreatefromstring($vertical);
 $first=imagecolorsforindex($v,imagecolorat($v,294,40));
 $second=imagecolorsforindex($v,imagecolorat($v,294,120));
 themedAssert($first!==$second,'Repeated right-edge horizontal stripes work');
 imagedestroy($v);
 $ui=file_get_contents(__DIR__.'/../views/content.notifications.php');
 foreach(['background_image','background_fit','background_opacity',
  'stripe_direction','stripe_repeat','stripe_opacity','stripe_thickness',
  'stripe_reverse','content_overlay','gradient_target','gradient_direction','gradient_end',
  'border_width','separator_width','panel_radius'] as $key)
   themedAssert(str_contains($ui,'notifications[appearance]['.$key.']'),'Missing appearance editor: '.$key);
 themedAssert(str_contains($ui,"payload['appearance'+name.slice("),'All new controls must be sent to live preview');
} finally {
 foreach(glob($path.'/*')?:[] as $file)unlink($file);
 rmdir($path);
}
echo "Extended fixed-layout notification appearance tests passed\n";
