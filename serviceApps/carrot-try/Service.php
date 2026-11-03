<?php
return static function(array $ctx,array $q) {
    if(($q['op']??'')!=='roll')return $ctx['menu']('Carrot Roulette',[
        ['Roll (1 in 235)',$ctx['route'](['op'=>'roll'])]
    ]);
    $wait=$ctx['cooldown'](10);
    if($wait>0)return $ctx['text']('Carrot Roulette','Try again in '.$wait.' seconds.');
    if(random_int(1,235)!==1)return $ctx['text']('Carrot Roulette','No carrot this time.');
    if(!function_exists('imagecreatetruecolor'))return $ctx['text']('Carrot Roulette','Missing PHP GD');
    $im=imagecreatetruecolor(296,156);
    imagefilledrectangle($im,0,0,295,155,imagecolorallocate($im,24,58,40));
    $orange=imagecolorallocate($im,255,139,36);$green=imagecolorallocate($im,70,225,86);
    imagefilledpolygon($im,[104,35,204,35,153,146],3,$orange);
    imagefilledellipse($im,154,35,100,32,$orange);
    imagefilledpolygon($im,[149,25,124,1,136,37],3,$green);
    imagefilledpolygon($im,[149,25,187,0,164,37],3,$green);
    ob_start();imagepng($im);$img=ob_get_clean();imagedestroy($im);
    [$sent,$total]=$ctx['broadcast']('CARROT!','You won the carrot lottery',$img);
    return $ctx['text']('Carrot Roulette','CARROT! Sent to '.$sent.' of '.$total.' phones.');
};
