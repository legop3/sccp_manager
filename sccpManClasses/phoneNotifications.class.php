<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Cisco XML notification document builder.
 *
 * This intentionally does not touch chan_sccp's native message-waiting state.
 * Documents are returned as strings so callers can host them behind the
 * module's existing phone XML endpoint and push the resulting URL to a phone.
 */
final class phoneNotifications
{
    private static function xml($value)
    {
        return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function limit($value, $bytes)
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string)$value);
        if (strlen($value) <= $bytes) return $value;
        if (function_exists('mb_strcut')) return mb_strcut($value, 0, $bytes, 'UTF-8');
        while (strlen($value) > $bytes) $value = substr($value, 0, -1);
        return $value;
    }

    /** Render a compact graphical notification for the 7945G screen. */
    public static function graphic($module, array $fields, array $settings, $device = null, $preview = false)
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext') || !function_exists('imagettfbbox'))
            throw new \RuntimeException('Notification images require PHP GD with FreeType support');
        $dir=$module->phonecontent->publicPath();
        $base=$module->phonecontent->baseUrl($device);
        if (!$preview && (!is_dir($dir) || is_link($dir) || $base==='')) return null;
        $im=imagecreatetruecolor(296,156);
        $presets=[
            'ocean'=>['#13293D','#1E425B','#FAFCFF','#A8CEE5','#5AC4F5'],
            'charcoal'=>['#181B20','#30343C','#FFFFFF','#BFC6D0','#FFD084'],
            'plum'=>['#301C3F','#513162','#FFFFFF','#DFC6EC','#FF9BC8'],
            'forest'=>['#123C31','#245C48','#F5FFF8','#C1E9D2','#91E7B1'],
            'cisco'=>['#D5E2ED','#1E568F','#101C2A','#233849','#FFFFFF'],
            'custom'=>['#13293D','#1E425B','#FAFCFF','#A8CEE5','#5AC4F5']
        ];
        $p=$presets[$settings['theme']??'ocean']??$presets['ocean'];
        $defaults=['background'=>$p[0],'header'=>$p[1],'header_text'=>$p[4],
            'name_text'=>$p[2],'secondary_text'=>$p[3],
            'details_background'=>$p[0],'details_label'=>$p[3],
            'details_text'=>$p[2],'accent'=>$p[4],'border'=>$p[4]];
        if (($settings['theme']??'')==='cisco') {
            $defaults['details_background']='#D5E2ED';
            $defaults['details_label']='#334A5D';
            $defaults['details_text']='#172A3C';
        }
        $colors=array_merge($defaults,(array)($settings['colors']??[]));
        $color=static function($hex) use($im) {
            if (!is_string($hex)||!preg_match('/^#[0-9a-f]{6}$/i',$hex)) $hex='#FFFFFF';
            return imagecolorallocate($im,hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2)));
        };
        $bg=$color($colors['background']);
        imagefilledrectangle($im,0,0,295,155,$bg);
        // Reuse uploaded phone wallpapers as full-card artwork. The phone's
        // own notification image (when present) remains a separate fixed slot.
        $wallpaper=(string)($settings['background_image']??'');
        if (preg_match('/^[a-f0-9]{16}$/D',$wallpaper)) {
            $path=$dir.'/wallpaper-'.$wallpaper.'.png';
            if (is_file($path) && !is_link($path) && filesize($path)<=4194304) {
                $source=@imagecreatefrompng($path);
                if ($source!==false) {
                    $sw=imagesx($source);$sh=imagesy($source);
                    $fit=(string)($settings['background_fit']??'cover');
                    $scale=$fit==='contain'?min(296/$sw,156/$sh):max(296/$sw,156/$sh);
                    $rw=$fit==='stretch'?296:max(1,(int)round($sw*$scale));
                    $rh=$fit==='stretch'?156:max(1,(int)round($sh*$scale));
                    $picture=imagecreatetruecolor(296,156);
                    imagefilledrectangle($picture,0,0,295,155,$bg);
                    imagecopyresampled($picture,$source,(int)floor((296-$rw)/2),
                        (int)floor((156-$rh)/2),0,0,$rw,$rh,$sw,$sh);
                    imagecopymerge($im,$picture,0,0,0,0,296,156,
                        max(0,min(100,(int)($settings['background_opacity']??100))));
                    imagedestroy($picture);
                    imagedestroy($source);
                }
            }
        }
        $stripes=array_values(array_filter((array)($settings['stripes']??[]),static function($v) {
            return is_string($v)&&preg_match('/^#[0-9a-f]{6}$/i',$v);
        }));
        if (!empty($settings['stripe_reverse'])) $stripes=array_reverse($stripes);
        $position=(string)($settings['stripe_position']??'off');
        $direction=(string)($settings['stripe_direction']??'horizontal');
        $repeat=max(1,min(6,(int)($settings['stripe_repeat']??1)));
        $opacity=max(0,min(100,(int)($settings['stripe_opacity']??100)));
        $thickness=max(2,min(32,(int)($settings['stripe_thickness']??7)));
        $drawStripes=static function($x0,$y0,$x1,$y1) use($im,$stripes,$direction,$repeat,$opacity) {
            if (!$stripes || $opacity===0 || $x1<=$x0 || $y1<=$y0) return;
            $count=count($stripes)*$repeat;
            $alpha=127-(int)round($opacity*127/100);
            $inks=[];
            foreach($stripes as $hex) {
                $inks[]=imagecolorallocatealpha($im,hexdec(substr($hex,1,2)),
                    hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2)),$alpha);
            }
            $w=$x1-$x0;$h=$y1-$y0;
            if ($direction==='vertical') {
                for($x=$x0;$x<$x1;$x++) {
                    $index=min($count-1,(int)floor(($x-$x0)*$count/$w))%count($inks);
                    imageline($im,$x,$y0,$x,$y1-1,$inks[$index]);
                }
            } elseif ($direction==='diagonal') {
                // Fixed 45-degree diagonal bands, clipped to the chosen
                // stripe region. Each repeat repeats the full color palette.
                for($y=$y0;$y<$y1;$y++) for($x=$x0;$x<$x1;$x++) {
                    $phase=(($x-$x0)+($y-$y0))*$count/max(1,$w+$h-2);
                    $index=min($count-1,(int)floor($phase))%count($inks);
                    imagesetpixel($im,$x,$y,$inks[$index]);
                }
            } else {
                for($y=$y0;$y<$y1;$y++) {
                    $index=min($count-1,(int)floor(($y-$y0)*$count/$h))%count($inks);
                    imageline($im,$x0,$y,$x1-1,$y,$inks[$index]);
                }
            }
        };
        if ($position==='background') $drawStripes(0,0,296,156);
        imagefilledrectangle($im,0,0,295,28,$color($colors['header']));
        if ($position==='header') $drawStripes(0,0,296,29);
        if ($position==='top') $drawStripes(0,0,296,$thickness);
        if ($position==='bottom') $drawStripes(0,156-$thickness,296,156);
        if ($position==='left') $drawStripes(0,0,$thickness,156);
        if ($position==='right') $drawStripes(296-$thickness,0,296,156);
        if ($position==='background') {
            // Preserve a solid content panel so full-surface stripes never
            // undermine readability. Text and image geometry do not move.
            imagefilledrectangle($im,4,31,291,145,$bg);
        }
        imagefilledrectangle($im,4,111,291,143,$color($colors['details_background']));
        $separatorWidth=max(0,min(6,(int)($settings['separator_width']??2)));
        if ($separatorWidth) imagefilledrectangle($im,0,28,295,27+$separatorWidth,$color($colors['accent']));
        // Cisco screen: large caller name, with number and time side by side.
        // Render directly in TrueType at native LCD resolution. Avoid scaling
        // low-resolution GD bitmap glyphs into blocky text.
        $font=null;
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/usr/share/fonts/liberation-sans/LiberationSans-Regular.ttf'
        ] as $candidate) {
            if (is_readable($candidate)) { $font=$candidate; break; }
        }
        if ($font===null) throw new \RuntimeException('Notification images require a readable TrueType font');
        $draw=static function($text,$x,$baseline,$maxWidth,$size,$hex,$minSize=10) use($im,$font,$color) {
            $text=trim((string)$text);
            if ($text==='') return;
            $ink=$color($hex);
            {
                for ($point=$size;$point>=$minSize;$point-=0.5) {
                    $box=imagettfbbox($point,0,$font,$text);
                    if ($box!==false && ($box[2]-$box[0])<=$maxWidth) {
                        imagettftext($im,$point,0,$x,$baseline,$ink,$font,$text);
                        return;
                    }
                }
                // In the image layout every field has a narrower column.
                // Never let long captions paint over the adjacent image.
                while ($text!=='') {
                    $box=imagettfbbox($minSize,0,$font,$text.'...');
                    if ($box!==false && ($box[2]-$box[0])<=$maxWidth) break;
                    $text=function_exists('mb_substr')
                        ? mb_substr($text,0,-1,'UTF-8') : substr($text,0,-1);
                }
                if ($text!=='') imagettftext($im,$minSize,0,$x,$baseline,$ink,$font,$text.'...');
                return;
            }

        };
        // The renderer accepts semantic-free display slots. Producers decide
        // what belongs in them; no notification type is interpreted here.
        $heading=(string)($fields['title']??'Notification');
        $primary=(string)($fields['primary']??'');
        $secondary=(string)($fields['secondary']??'');
        $detail=(string)($fields['detail']??'');
        $timestamp=(string)($fields['timestamp']??'');
        // All notification producers use the same five text slots.
        // The optional image field contains decoded/validated image bytes from
        // the sender's source layer, never a remote URL fetched by the renderer.
        $image=$fields['image']??null;
        if ($image!==null && (!is_string($image) || $image===''))
            throw new \InvalidArgumentException('Notification image must contain image bytes');
        $picture=null;
        if ($image!==null) {
            if (strlen($image)>1048576) throw new \InvalidArgumentException('Notification image exceeds 1 MiB');
            $info=@getimagesizefromstring($image);
            if (!$info || $info[0]<1 || $info[1]<1 || $info[0]>4096 || $info[1]>4096 ||
                !in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_GIF],true))
                throw new \InvalidArgumentException('Unsupported notification image');
            $picture=@imagecreatefromstring($image);
            if ($picture===false) throw new \InvalidArgumentException('Cannot decode notification image');
        }
        $draw($heading,9,22,278,15,$colors['header_text'],12);
        if ($picture!==null) {
            // Shift all text to the right of a 112x104 photo area. A full-width
            // footer holds the timestamp; no text slot is lost.
            $pw=imagesx($picture);$ph=imagesy($picture);
            $scale=min(112/$pw,104/$ph);
            $rw=max(1,(int)round($pw*$scale));$rh=max(1,(int)round($ph*$scale));
            $px=8+(int)floor((112-$rw)/2);
            $py=34+(int)floor((104-$rh)/2);
            imagefilledrectangle($im,7,33,121,139,$color($colors['details_background']));
            imagecopyresampled($im,$picture,$px,$py,0,0,$rw,$rh,$pw,$ph);
            imagedestroy($picture);
            $draw($primary,130,54,158,17,$colors['name_text'],10);
            $draw($secondary,130,77,158,12,$colors['secondary_text'],9);
            $draw($detail,130,100,158,11,$colors['details_label'],9);
            $draw($timestamp,130,128,158,11,$colors['details_text'],9);
        } else {
            $draw($primary,9,72,278,26,$colors['name_text'],15);
            $draw($secondary,10,101,$detail!==''?160:276,17,$colors['secondary_text'],10);
            if ($detail!=='') {
                $detailBox=imagettfbbox(13,0,$font,$detail);
                if ($detailBox===false) throw new \RuntimeException('Unable to measure notification text');
                $detailWidth=$detailBox[2]-$detailBox[0];
                $draw($detail,max(165,286-$detailWidth),101,121,13,$colors['details_label'],10);
            }
            $draw($timestamp,10,129,276,16,$colors['details_text'],10);
        }
        $borderWidth=max(0,min(6,(int)($settings['border_width']??0)));
        if ($borderWidth) for($inset=0;$inset<$borderWidth;$inset++)
            imagerectangle($im,$inset,$inset,295-$inset,155-$inset,
                $color($colors['border']));

        if ($preview) {
            // Identical GD renderer, but stream bytes to the authenticated
            // admin AJAX response. Never publish preview files or LAN URLs.
            ob_start();
            try { $ok=imagepng($im); $png=ob_get_contents(); }
            finally { ob_end_clean(); imagedestroy($im); }
            if (!$ok || !is_string($png)) throw new \RuntimeException('Preview rendering failed');
            return $png;
        }
        $id=bin2hex(random_bytes(12));
        $filename='notification-'.$id.'.png';
        $ok=imagepng($im,$dir.'/'.$filename);
        imagedestroy($im);
        if (!$ok) return null;
        chmod($dir.'/'.$filename,0644);
        $xml='<?xml version="1.0" encoding="UTF-8"?>'
            .'<CiscoIPPhoneImageFile><Title>'.self::xml($heading).'</Title>'
            .'<LocationX>0</LocationX><LocationY>0</LocationY><URL>'
            .self::xml($base.'/'.$filename).'</URL>';
        return $xml.'</CiscoIPPhoneImageFile>';
    }

}
