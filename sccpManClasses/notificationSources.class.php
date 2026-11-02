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
    /** Built-in scheduled feeds. Fetch on the PBX, not on the phone. */
    public static function builtIn($source) {
        $catalog=[
            'dog'=>['https://dog.ceo/api/breeds/image/random',['dog.ceo']],
            'xkcd'=>['https://xkcd.com/info.0.json',['xkcd.com']]
        ];
        if (!isset($catalog[$source])) return null;
        $json=self::fetch($catalog[$source][0],16384,['application/json']);
        if ($json===null) return null;
        $data=json_decode($json,true);
        if (!is_array($data)) return null;
        if ($source==='dog') {
            if (($data['status']??'')!=='success') return null;
            $url=(string)($data['message']??'');
            $breed=(string)(parse_url($url,PHP_URL_PATH)??'');
            if (!preg_match('~^/breeds/([a-z-]+)/[^/]+\\.jpe?g$~i',$breed,$matches)) return null;
            $name=ucwords(str_replace('-',' ',$matches[1]));
            $title='Random Dog';
            $caption='Breed: '.$name;
            $host='images.dog.ceo';
        } else {
            $url=(string)($data['img']??'');
            $title=(string)($data['safe_title']??'xkcd');
            $caption=(string)($data['alt']??'');
            $host='imgs.xkcd.com';
        }
        $parts=parse_url($url);
        if (!$parts || ($parts['scheme']??'')!=='https' || ($parts['host']??'')!==$host ||
            !empty($parts['user']) || !empty($parts['pass']) || isset($parts['port'])) return null;
        return ['title'=>substr($title,0,120),
            'message'=>substr($caption,0,500)];
    }
    public static function text($url,$default) {
        $content=self::fetch($url,16384,['text/plain','text/html','application/json']);
        if ($content===null) return $default;
        $content=trim(strip_tags($content));
        return substr($content,0,2048);
    }

}
