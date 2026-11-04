<?php
return static function(array $ctx,array $q) {
    if(($q['op']??'')!=='roll')return $ctx['menu']('carrot try',[
        ['roll (1 in '.$ctx['config']['one_in'].')',$ctx['route'](['op'=>'roll'])]
    ]);
    $roll=$ctx['randomInt']??'random_int';
    if($roll(1,$ctx['config']['one_in'])!==1)return $ctx['text']('carrot try','no carrot this time.');
    if(!function_exists('imagecreatetruecolor'))return $ctx['text']('carrot try','missing php gd');
    $im=imagecreatetruecolor(296,156);
    imagefilledrectangle($im,0,0,295,155,imagecolorallocate($im,24,58,40));
    $orange=imagecolorallocate($im,255,139,36);$green=imagecolorallocate($im,70,225,86);
    imagefilledpolygon($im,[104,35,204,35,153,146],3,$orange);
    imagefilledellipse($im,154,35,100,32,$orange);
    imagefilledpolygon($im,[149,25,124,1,136,37],3,$green);
    imagefilledpolygon($im,[149,25,187,0,164,37],3,$green);
    ob_start();imagepng($im);$img=ob_get_clean();imagedestroy($im);
    [$sent,$total,$deferred]=$ctx['notifications']->broadcast(
        'carrot!','you won the carrot lottery',$img,'all',$ctx['device'],true
    );
    if($total===0)return $ctx['text']('carrot try','carrot! no connected phones found.');
    if($deferred>0)return $ctx['text']('carrot try',
        'carrot! you will see it too. others accepted '.$sent.'.');
    return $ctx['text']('carrot try','carrot! accepted by '.$sent.' of '.$total.' phones.');
};
