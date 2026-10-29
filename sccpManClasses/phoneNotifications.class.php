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

    public static function text($title, $message, $prompt = 'Dismiss')
    {
        $title = self::xml(self::limit($title, 32));
        $prompt = self::xml(self::limit($prompt, 32));
        $message = self::xml(self::limit($message, 2048));
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CiscoIPPhoneText><Title>' . $title . '</Title>'
            . '<Text>' . $message . '</Text><Prompt>' . $prompt . '</Prompt>'
            . '<SoftKeyItem><Name>Exit</Name><URL>SoftKey:Exit</URL><Position>1</Position></SoftKeyItem>'
            . '</CiscoIPPhoneText>';
    }

    public static function interactive($title, $message, array $actions)
    {
        $title=self::xml(self::limit($title,32));
        $message=self::xml(self::limit($message,2048));
        $xml='<?xml version="1.0" encoding="UTF-8"?>'
            .'<CiscoIPPhoneText><Title>'.$title.'</Title><Text>'.$message.'</Text>'
            .'<Prompt>Select an action</Prompt>';
        $position=1;
        foreach ($actions as $label=>$url) {
            if ($position>4) break;
            if (!is_string($url) || !preg_match('~^(https?://|Dial:|Init:|SoftKey:)~',$url)) continue;
            $xml.='<SoftKeyItem><Name>'.self::xml(self::limit($label,12))
                .'</Name><URL>'.self::xml($url).'</URL><Position>'.$position.'</Position></SoftKeyItem>';
            $position++;
        }
        return $xml.'</CiscoIPPhoneText>';
    }

    /** Render a compact graphical notification for the 7945G screen. */
    public static function graphic($module, $kind, $fields, array $actions, array $settings = [])
    {
        if (!function_exists('imagecreatetruecolor')) return null;
        $dir=$module->phonecontent->publicPath();
        $base=$module->phonecontent->baseUrl();
        if (!is_dir($dir) || is_link($dir) || $base==='') return null;
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
            'details_text'=>$p[2],'accent'=>$p[4]];
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
        $stripes=array_values(array_filter((array)($settings['stripes']??[]),static function($v) {
            return is_string($v)&&preg_match('/^#[0-9a-f]{6}$/i',$v);
        }));
        $position=(string)($settings['stripe_position']??'off');
        $drawStripes=static function($y0,$y1) use($im,$stripes,$color) {
            if (!$stripes) return;
            $n=count($stripes);
            foreach($stripes as $i=>$hex) {
                $from=$y0+(int)floor(($y1-$y0)*$i/$n);
                $to=$y0+(int)floor(($y1-$y0)*($i+1)/$n)-1;
                imagefilledrectangle($im,0,$from,295,$to,$color($hex));
            }
        };
        if ($position==='background') $drawStripes(0,156);
        imagefilledrectangle($im,0,0,295,28,$color($colors['header']));
        if ($position==='header') $drawStripes(0,29);
        if ($position==='top') $drawStripes(0,7);
        if ($position==='bottom') $drawStripes(149,156);
        if ($position==='background') {
            // Solid panel ensures caller details remain readable over stripes.
            imagefilledrectangle($im,4,31,291,145,$bg);
        }
        // Cisco screen: large caller name, with number and time side by side.
        $heading=$kind==='voicemail'?'New voicemail':'Missed call';
        imagestring($im,5,9,5,$heading,$color($colors['header_text']));
        $name=(string)($fields['caller']??'Unknown caller');
        $name=preg_replace('/[^\\x20-\\x7E]/','',$name);
        $displayName=substr($name,0,27);
        $nameWidth=imagefontwidth(5)*strlen($displayName);
        if ($nameWidth>0) {
            $layer=imagecreatetruecolor($nameWidth,15);
            $transparent=imagecolorallocate($layer,255,0,255);
            imagefill($layer,0,0,$transparent);
            $rgb=imagecolorsfor($im,$color($colors['name_text']));
            $ink=imagecolorallocate($layer,$rgb['red'],$rgb['green'],$rgb['blue']);
            imagestring($layer,5,0,0,$displayName,$ink);
            imagecolortransparent($layer,$transparent);
            $scaledWidth=min(276,$nameWidth*2);
            imagecopyresized($im,$layer,10,42,0,0,$scaledWidth,31,$nameWidth,15);
            imagedestroy($layer);
        }
        if (!isset($settings['show_number']) || $settings['show_number']) {
            $number=(string)($fields['number']??'');
            if ($number!=='') {
                $ext=(bool)preg_match('/^[0-9]{1,6}$/D',$number);
                imagestring($im,5,10,92,substr(($ext?'Ext. ':'').$number,0,19),$color($colors['secondary_text']));
            }
        }
        if (!isset($settings['show_details']) || $settings['show_details']) {
            $when=(string)($fields['time']??$fields['when']??'');
            imagestring($im,4,177,93,substr($when,0,17),$color($colors['details_text']));
            if ($kind==='voicemail') {
                imagestring($im,3,10,134,substr((string)($fields['length']??''),0,32),$color($colors['details_label']));
            } elseif ((string)($fields['date']??'')!=='') {
                imagestring($im,3,10,134,substr((string)$fields['date'],0,32),$color($colors['details_label']));
            }
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
        $position=1;
        foreach ($actions as $label=>$url) {
            if ($position>4) break;
            $xml.='<SoftKeyItem><Name>'.self::xml(self::limit($label,12))
                .'</Name><URL>'.self::xml($url).'</URL><Position>'.$position.'</Position></SoftKeyItem>';
            $position++;
        }
        return $xml.'</CiscoIPPhoneImageFile>';
    }

    public static function status($message, $timer = 0)
    {
        $message = self::xml(self::limit($message, 80));
        $timer = max(0, min(3600, (int)$timer));
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CiscoIPPhoneStatus><Text>' . $message . '</Text>'
            . '<Timer>' . $timer . '</Timer></CiscoIPPhoneStatus>';
    }

    public static function execute($notificationUrl, $soundFile = null)
    {
        if (!filter_var($notificationUrl, FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string)parse_url($notificationUrl, PHP_URL_SCHEME)), array('http', 'https'), true)) {
            throw new \InvalidArgumentException('Invalid notification URL');
        }
        $items = '';
        if ($soundFile !== null && $soundFile !== '') {
            if (!preg_match('/^[A-Za-z0-9_.-]+\\.raw$/D', (string)$soundFile)) {
                throw new \InvalidArgumentException('Invalid notification sound filename');
            }
            $items .= '<ExecuteItem URL="Play:' . self::xml($soundFile) . '"/>';
        }
        $items .= '<ExecuteItem URL="' . self::xml($notificationUrl) . '"/>';
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CiscoIPPhoneExecute>' . $items . '</CiscoIPPhoneExecute>';
    }
}
