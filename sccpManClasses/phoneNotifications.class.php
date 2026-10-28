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
    public static function graphic($module, $kind, $fields, array $actions)
    {
        if (!function_exists('imagecreatetruecolor')) return null;
        $dir=$module->phonecontent->publicPath();
        $base=$module->phonecontent->baseUrl();
        if (!is_dir($dir) || is_link($dir) || $base==='') return null;
        $im=imagecreatetruecolor(296,156);
        $bg=imagecolorallocate($im,19,41,61);
        $panel=imagecolorallocate($im,30,66,91);
        $white=imagecolorallocate($im,250,252,255);
        $muted=imagecolorallocate($im,168,206,229);
        $accent=imagecolorallocate($im,$kind==='voicemail'?90:255,$kind==='voicemail'?196:174,$kind==='voicemail'?245:137);
        imagefilledrectangle($im,0,0,295,155,$bg);
        imagefilledrectangle($im,0,0,295,28,$panel);
        $heading=$kind==='voicemail'?'NEW VOICEMAIL':'MISSED CALL';
        imagestring($im,4,10,6,$heading,$accent);
        $name=(string)($fields['caller']??'Unknown caller');
        $name=preg_replace('/[^\\x20-\\x7E]/','',$name);
        imagestring($im,5,10,38,substr($name,0,28),$white);
        imagestring($im,3,10,62,substr((string)($fields['number']??''),0,39),$muted);
        imageline($im,10,87,285,87,$panel);
        if ($kind==='voicemail') {
            imagestring($im,3,10,98,'RECEIVED', $muted);
            imagestring($im,3,10,116,substr((string)($fields['time']??''),0,22),$white);
            imagestring($im,3,169,98,'LENGTH', $muted);
            imagestring($im,3,169,116,substr((string)($fields['length']??''),0,12),$white);
        } else {
            imagestring($im,3,10,98,'LAST CALL', $muted);
            imagestring($im,3,10,116,substr((string)($fields['when']??''),0,38),$white);
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
