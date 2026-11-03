<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Discovers bundled, self-contained Cisco XML service applications.
 * Only the host owns handset authorization, URL generation and storage.
 * Each app exports a callable from serviceApps/<id>/Service.php.
 */
final class serviceRegistry {
    private $module;
    private $root;
    public function __construct($module=null) {
        $this->module=$module;
        $this->root=dirname(__DIR__).'/serviceApps';
    }
    public function manifests() {
        $found=[];
        foreach (glob($this->root.'/*/manifest.json') ?: [] as $path) {
            if (is_link($path) || is_link(dirname($path))) continue;
            $id=basename(dirname($path));
            if (!preg_match('/^[a-z][a-z0-9-]{0,39}$/D',$id)) continue;
            $raw=@file_get_contents($path);
            $meta=json_decode((string)$raw,true);
            if (!is_array($meta) || ($meta['id']??null)!==$id ||
                !isset($meta['name']) || !is_string($meta['name']) ||
                strlen($meta['name'])>48 || !is_file(dirname($path).'/Service.php')) continue;
            $found[$id]=$meta;
        }
        ksort($found);
        return $found;
    }
    public static function text($title,$body) {
        $e=static function($s){return htmlspecialchars((string)$s,ENT_XML1|ENT_QUOTES,'UTF-8');};
        return '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneText><Title>'.
            $e(substr($title,0,32)).'</Title><Text>'.$e($body).'</Text></CiscoIPPhoneText>';
    }
    public static function menu($title,$entries) {
        $e=static function($s){return htmlspecialchars((string)$s,ENT_XML1|ENT_QUOTES,'UTF-8');};
        $xml='<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneMenu><Title>'.
            $e(substr($title,0,32)).'</Title><Prompt>Select an item</Prompt>';
        foreach(array_slice($entries,0,30) as $item)
            $xml.='<MenuItem><Name>'.$e(substr((string)$item[0],0,64)).
                '</Name><URL>'.$e($item[1]).'</URL></MenuItem>';
        return $xml.'</CiscoIPPhoneMenu>';
    }
    /**
     * Exclusive, per-app JSON transactions. No state can leak between apps.
     * The lock covers read/modify/write for atomic shared counters and limits.
     */
    public function transaction($id,$callback) {
        if (!isset($this->manifests()[$id])) throw new \InvalidArgumentException('Unknown service');
        $path='/var/spool/asterisk/sccp-manager-app-'. $id .'.json';
        if (is_link($path)) throw new \RuntimeException('Invalid service storage path');
        $fp=@fopen($path,'c+');
        if (!$fp) throw new \RuntimeException('Cannot open service storage');
        try {
            if (!flock($fp,LOCK_EX)) throw new \RuntimeException('Cannot lock service storage');
            rewind($fp);
            $state=json_decode(stream_get_contents($fp),true);
            if (!is_array($state)) $state=[];
            $value=$callback($state);
            $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($json===false || strlen($json)>262144)
                throw new \RuntimeException('Service state exceeds 256 KiB');
            rewind($fp);ftruncate($fp,0);
            if (fwrite($fp,$json)!==strlen($json)) throw new \RuntimeException('Service write failed');
            fflush($fp);
            return $value;
        } finally {flock($fp,LOCK_UN);fclose($fp);}
    }

    public function cooldown($id,$user,$seconds) {
        return $this->transaction($id,static function(array &$s)use($user,$seconds){
            $now=time();$key=hash('sha256',(string)$user);
            if(!isset($s['cooldowns']) || !is_array($s['cooldowns']))$s['cooldowns']=[];
            foreach($s['cooldowns'] as $k=>$until)if((int)$until<=$now)unset($s['cooldowns'][$k]);
            $wait=max(0,(int)($s['cooldowns'][$key]??0)-$now);
            if($wait===0)$s['cooldowns'][$key]=$now+max(1,min(86400,(int)$seconds));
            return $wait;
        });
    }
    private static function fetchRemote($url,$limit) {
        if(!function_exists('curl_init'))throw new \RuntimeException('cURL required');
        $host=(string)(parse_url($url,PHP_URL_HOST)??'');
        if(!in_array($host,['cataas.com','dog.ceo','images.dog.ceo'],true) ||
           parse_url($url,PHP_URL_SCHEME)!=='https')throw new \RuntimeException('Disallowed image host');
        $ch=curl_init($url);$bytes='';
        try {
            curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,
                CURLOPT_TIMEOUT=>10,CURLOPT_USERAGENT=>'SCCP-Services/1',
                CURLOPT_WRITEFUNCTION=>static function($ch,$data)use(&$bytes,$limit){
                    if(strlen($bytes)+strlen($data)>$limit)return 0;
                    $bytes.=$data;return strlen($data);
                }]);
            $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            if($ok===false || $status!==200 || $bytes==='')throw new \RuntimeException('Image source failed');
        } finally {curl_close($ch);}
        return $bytes;
    }
    public function animal($type) {
        if($type==='cat')$image=self::fetchRemote('https://cataas.com/cat',1048576);
        elseif($type==='dog'){
            $data=json_decode(self::fetchRemote('https://dog.ceo/api/breeds/image/random',16000),true);
            $url=(string)($data['message']??'');
            if(!preg_match('~^https://images\.dog\.ceo/breeds/[a-zA-Z0-9_./%-]+\.(?:jpg|jpeg|png)$~D',$url))
                throw new \RuntimeException('Dog API provided an invalid URL');
            $image=self::fetchRemote($url,1048576);
        }else throw new \InvalidArgumentException('Unsupported animal');
        $info=@getimagesizefromstring($image);
        if(!$info || $info[0]>4096 || $info[1]>4096 ||
            !in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_GIF],true))
            throw new \RuntimeException('Invalid animal image');
        return $image;
    }
    public function broadcast($app,$title,$caption,$image) {
        $devices=(new notificationDelivery($this->module))->devices();
        $theme=(new notificationConfig($this->module))->appearance();
        $queue=new notificationQueue($this->module);$count=0;
        foreach($devices as $device=>$ip){
            try {
                $graphic=phoneNotifications::graphic($this->module,[
                    'title'=>$title,'primary'=>$caption,'timestamp'=>date('m/d/Y g:i A'),
                    'image'=>$image
                ],$theme,$device);
                if(!$graphic)continue;
                $url=$this->module->phoneservices->url($device).'&app='.rawurlencode($app);
                $response=$queue->send($device,$ip,new notificationMessage(['Open'=>$url],$graphic));
                if(!empty($response['ok']))$count++;
            }catch(\Throwable $e){error_log('SCCP app broadcast '.$device.': '.$e->getMessage());}
        }
        return [$count,count($devices)];
    }
    public function clockCall($line) {
        if(!preg_match('/^[0-9]{1,16}$/D',(string)$line))
            throw new \InvalidArgumentException('Invalid SCCP line');
        if(!is_file('/etc/asterisk/sccp-manager-voicemail.conf'))
            throw new \RuntimeException('Service dialplan not installed');
        register_shutdown_function(static function()use($line){
            if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
            else {while(ob_get_level()>0)@ob_end_flush();@flush();}
            $cmd='channel originate SCCP/'.$line.'/aa=1w/ringer=silent extension s@sccp-manager-services-clock';
            $out=[];$code=0;exec('/usr/sbin/asterisk -rx '.escapeshellarg($cmd).' 2>&1',$out,$code);
            if($code!==0)error_log('SCCP spoken clock: '.implode(' ',array_slice($out,-3)));
        });
    }

    public function handle($id,array $query,$device,array $identity,callable $route) {
        $meta=$this->manifests();
        if (!isset($meta[$id])) throw new \InvalidArgumentException('Unknown service');
        $file=$this->root.'/'.$id.'/Service.php';
        $handler=require $file;
        if (!is_callable($handler)) throw new \RuntimeException('Invalid service implementation');
        $ctx=[
            'device'=>$device,
            'user'=>(string)($identity['extension']??$device),
            'route'=>function(array $params=[])use($route,$id){return $route(array_merge(['app'=>$id],$params));},
            'text'=>[self::class,'text'],
            'menu'=>[self::class,'menu'],
            'transaction'=>function(callable $callback)use($id){return $this->transaction($id,$callback);},
            'cooldown'=>function($seconds)use($id,$identity,$device){return $this->cooldown($id,$identity['extension']??$device,$seconds);},
            'animal'=>function($type){return $this->animal($type);},
            'broadcast'=>function($title,$caption,$image)use($id){return $this->broadcast($id,$title,$caption,$image);},
            'clockCall'=>function()use($identity){return $this->clockCall($identity['line']??'');}
        ];
        $xml=$handler($ctx,$query);
        if (!is_string($xml) || strlen($xml)>65536)
            throw new \RuntimeException('Invalid service response');
        $doc=new \DOMDocument();
        if (!@$doc->loadXML($xml,LIBXML_NONET) ||
            !in_array($doc->documentElement->tagName,['CiscoIPPhoneText','CiscoIPPhoneMenu','CiscoIPPhoneExecute','CiscoIPPhoneInput'],true))
            throw new \RuntimeException('Invalid Cisco XML service response');
        echo $xml;
    }
}
