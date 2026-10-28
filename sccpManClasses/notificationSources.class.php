<?php
namespace FreePBX\modules\Sccp_manager;

final class notificationSources {
    private static function fetch($url,$maximum,$types) {
        $url=trim((string)$url);
        if ($url==='') return null;
        $parts=parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme']??''),['https','http'],true) ||
            empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) return null;
        $host=$parts['host'];
        if (filter_var($host,FILTER_VALIDATE_IP)) {
            $ips=[$host];
        } else {
            $ips=gethostbynamel($host);
        }
        if (!$ips) return null;
        foreach ($ips as $ip)
            if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return null;
        $port=(int)($parts['port']??($parts['scheme']==='https'?443:80));
        if ($port!==80 && $port!==443) return null;
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>7,
            CURLOPT_MAXFILESIZE=>$maximum,
            CURLOPT_USERAGENT=>'SCCP-Manager-Phone-Content/1.0',
            CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ips[0]],
        ]);
        $data=curl_exec($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($code!==200||!is_string($data)||strlen($data)>$maximum) return null;
        $type=strtolower(trim(explode(';',$type)[0]));
        if (!in_array($type,$types,true)) return null;
        return $data;
    }
    public static function text($url,$default) {
        $content=self::fetch($url,16384,['text/plain','text/html','application/json']);
        if ($content===null) return $default;
        $content=trim(strip_tags($content));
        return substr($content,0,2048);
    }
    /** Returns a phone-accessible PNG URL, or null. Source image is never sent to the phone directly. */
    public static function image($url,$module) {
        $body=self::fetch($url,1048576,['image/png','image/jpeg','image/gif']);
        if ($body===null || !function_exists('imagecreatefromstring')) return null;
        $src=@imagecreatefromstring($body);
        if ($src===false) return null;
        $w=imagesx($src);$h=imagesy($src);
        if ($w<=0||$h<=0||$w>4096||$h>4096) {imagedestroy($src);return null;}
        $dst=imagecreatetruecolor(296,156);
        $background=imagecolorallocate($dst,255,255,255);
        imagefill($dst,0,0,$background);
        $scale=min(296/$w,156/$h);
        $sw=max(1,(int)round($w*$scale));$sh=max(1,(int)round($h*$scale));
        imagecopyresampled($dst,$src,(int)((296-$sw)/2),(int)((156-$sh)/2),0,0,$sw,$sh,$w,$h);
        imagedestroy($src);
        $dir=$module->phonecontent->publicPath();
        $base=$module->phonecontent->baseUrl();
        if (!is_dir($dir)||is_link($dir)||$base==='') {imagedestroy($dst);return null;}
        $file='notification-'.bin2hex(random_bytes(12)).'.png';
        $ok=imagepng($dst,$dir.'/'.$file);
        imagedestroy($dst);
        if (!$ok) return null;
        chmod($dir.'/'.$file,0644);
        foreach (glob($dir.'/notification-*.png')?:[] as $path)
            if (is_file($path) && !is_link($path) && filemtime($path)<time()-3600) @unlink($path);
        return $base.'/'.$file;
    }
}
