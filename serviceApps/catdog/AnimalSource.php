<?php
/** Fetch, validate and normalize animal photos for the shared renderer. */
return static function($type,$transport=null) {
    $fetch=static function($url,$limit)use($transport) {
        $parts=parse_url($url);
        if(!is_array($parts) || ($parts['scheme']??'')!=='https' ||
            !in_array($parts['host']??'',['cataas.com','dog.ceo','images.dog.ceo'],true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port']))
            throw new \RuntimeException('Invalid animal source');
        if($transport!==null) {
            // Allow deterministic photo-source tests without an external API.
            if(!is_callable($transport))throw new \InvalidArgumentException('Invalid image transport');
            $bytes=$transport($url,$limit);
            if(!is_string($bytes) || $bytes==='' || strlen($bytes)>$limit)
                throw new \RuntimeException('Animal source returned invalid data');
            return $bytes;
        }
        if(!function_exists('curl_init'))throw new \RuntimeException('PHP cURL is required');
        $reason='Animal source unavailable';
        for($attempt=0;$attempt<2;$attempt++) {
            $ch=curl_init($url);
            if(!$ch)throw new \RuntimeException('Unable to initialize animal request');
            $bytes='';
            try {
                curl_setopt_array($ch,[
                    CURLOPT_FOLLOWLOCATION=>false,
                    CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>7,
                    CURLOPT_USERAGENT=>'SCCP-Services/1',
                    CURLOPT_WRITEFUNCTION=>static function($ch,$data)use(&$bytes,$limit) {
                        if(strlen($bytes)+strlen($data)>$limit)return 0;
                        $bytes.=$data;
                        return strlen($data);
                    }
                ]);
                $ok=curl_exec($ch);
                $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
                $reason=$ok===false?'Animal source timed out or image exceeded size limit':
                    'Animal source HTTP '.$status;
            } finally {curl_close($ch);}
            if($ok!==false && $status===200 && $bytes!=='')return $bytes;
            // Bad requests and unavailable resources will not improve by
            // retrying. Brief outages and HTTP rate limiting sometimes do.
            if($status>=400 && $status<500 && $status!==429)break;
            if($attempt===0)usleep(100000);
        }
        throw new \RuntimeException($reason);
    };
    if($type==='cat') {
        $bytes=$fetch('https://cataas.com/cat',6291456);
    } elseif($type==='dog') {
        $response=json_decode($fetch('https://dog.ceo/api/breeds/image/random',16000),true);
        $url=(string)($response['message']??'');
        if(($response['status']??'')!=='success' ||
           !preg_match('~^https://images\.dog\.ceo/breeds/[a-zA-Z0-9_./%-]+\.(?:jpg|jpeg|png)$~D',$url))
            throw new \RuntimeException('Dog API returned an invalid image URL');
        $bytes=$fetch($url,6291456);
    } else {
        throw new \InvalidArgumentException('Unknown animal');
    }

    $info=@getimagesizefromstring($bytes);
    if(!$info || $info[0]<1 || $info[1]<1 || $info[0]>4096 ||
       $info[1]>4096 ||
       !in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_GIF],true))
        throw new \RuntimeException('Unsupported animal image');
    if(!function_exists('imagecreatefromstring') || !function_exists('imagejpeg'))
        throw new \RuntimeException('PHP GD is required to process animal photos');

    $source=@imagecreatefromstring($bytes);
    if(!$source)throw new \RuntimeException('Animal photo could not be decoded');
    try {
        $ratio=min(1,480/$info[0],360/$info[1]);
        $width=max(1,(int)round($info[0]*$ratio));
        $height=max(1,(int)round($info[1]*$ratio));
        $image=imagecreatetruecolor($width,$height);
        if(!$image)throw new \RuntimeException('Unable to allocate animal image');
        try {
            // Flatten any transparent GIF/PNG onto white so JPG output does
            // not show unexpected black regions on the 7945 screen.
            imagealphablending($image,true);
            imagefilledrectangle($image,0,0,$width-1,$height-1,
                imagecolorallocate($image,255,255,255));
            if(!imagecopyresampled($image,$source,0,0,0,0,$width,$height,$info[0],$info[1]))
                throw new \RuntimeException('Unable to resize animal image');
            ob_start();
            try {
                $ok=imagejpeg($image,null,78);
                $output=ob_get_contents();
            } finally {ob_end_clean();}
            if(!$ok || !is_string($output) || $output==='' ||
               strlen($output)>1048576)
                throw new \RuntimeException('Animal photo exceeds notification size limit');
            return $output;
        } finally {imagedestroy($image);}
    } finally {imagedestroy($source);}
};
