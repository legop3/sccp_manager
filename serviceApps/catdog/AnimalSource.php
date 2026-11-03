<?php
/** Animal-source integration belongs to catdog, not the core app registry. */
return static function($type) {
    $fetch=static function($url,$max) {
        $parts=parse_url($url);
        if(!is_array($parts) || ($parts['scheme']??'')!=='https' ||
            !in_array($parts['host']??'',['cataas.com','dog.ceo','images.dog.ceo'],true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port']))
            throw new \RuntimeException('Invalid animal source');
        if(!function_exists('curl_init'))throw new \RuntimeException('cURL required');
        $ch=curl_init($url);$bytes='';
        if(!$ch)throw new \RuntimeException('Unable to initialize animal request');
        try {
            curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,
                CURLOPT_USERAGENT=>'SCCP-Services/1',
                CURLOPT_WRITEFUNCTION=>static function($ch,$data)use(&$bytes,$max){
                    if(strlen($bytes)+strlen($data)>$max)return 0;
                    $bytes.=$data;
                    return strlen($data);
                }]);
            $ok=curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            if($ok===false || $status!==200 || $bytes==='')
                throw new \RuntimeException('Animal source unavailable');
        } finally {curl_close($ch);}
        return $bytes;
    };
    if($type==='cat') $image=$fetch('https://cataas.com/cat',6291456);
    elseif($type==='dog'){
        $data=json_decode($fetch('https://dog.ceo/api/breeds/image/random',16000),true);
        $url=(string)($data['message']??'');
        if(!preg_match('~^https://images\.dog\.ceo/breeds/[a-zA-Z0-9_./%-]+\.(?:jpg|jpeg|png)$~D',$url))
            throw new \RuntimeException('Dog API returned an invalid image URL');
        $image=$fetch($url,6291456);
    } else throw new \InvalidArgumentException('Unknown animal');
    $info=@getimagesizefromstring($image);
    if(!$info || $info[0]<1 || $info[1]<1 || $info[0]>4096 || $info[1]>4096 ||
        !in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_GIF],true))
        throw new \RuntimeException('Unsupported animal image');
    if (!function_exists('imagecreatefromstring'))
        throw new \RuntimeException('PHP GD is required to resize animal photos');
    $src=@imagecreatefromstring($image);
    if(!$src)throw new \RuntimeException('Unable to decode animal photo');
    try {
        $scale=min(1,480/$info[0],360/$info[1]);
        $w=max(1,(int)round($info[0]*$scale));
        $h=max(1,(int)round($info[1]*$scale));
        $small=imagecreatetruecolor($w,$h);
        try {
            // JPEG output keeps random photo payloads comfortably below the
            // unified notification renderer's 1 MiB input cap.
            imagefilledrectangle($small,0,0,$w-1,$h-1,
                imagecolorallocate($small,245,245,245));
            imagecopyresampled($small,$src,0,0,0,0,$w,$h,$info[0],$info[1]);
            ob_start();
            try {
                $ok=imagejpeg($small,null,78);
                $image=ob_get_contents();
            } finally { ob_end_clean(); }
            if(!$ok || !is_string($image) || $image==='' || strlen($image)>1048576)
                throw new \RuntimeException('Unable to resize animal photo');
        } finally { imagedestroy($small); }
    } finally { imagedestroy($src); }
    return $image;
};
