<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Reusable Cisco LCD frame transport. Generates per-frame XML and sends
 * the existing authenticated CGI Execute push; does not handle audio or
 * decide how frames are generated.
 */
final class serviceAppScreens {
    private $root;
    private $base;
    private $ip;
    public function __construct($module,$device) {
        if (!phonevoicemail::validDevice($device))
            throw new \InvalidArgumentException('Invalid handset');
        $ips=(new notificationDelivery($module))->devices();
        $this->ip=$ips[$device]??null;
        if (!is_string($this->ip) ||
            !filter_var($this->ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))
            throw new \RuntimeException('Handset is not connected');
        $this->root=$module->phonecontent->publicPath();
        $this->base=$module->phonecontent->baseUrl($device);
        if (!is_dir($this->root) || is_link($this->root) || $this->base==='')
            throw new \RuntimeException('Image publishing is unavailable');
    }
    /**
     * Publish a PNG frame to the address appropriate for LAN or VPN.
     * Caller tracks file paths for cleanup; only server-generated content.
     * Success means the phone accepted the push, NOT that it displayed it.
     */
    public function frame($name,$png,array &$files) {
        if (!preg_match('/^[a-z0-9-]{1,90}$/D',(string)$name))
            throw new \InvalidArgumentException('Invalid frame name');
        if (!is_string($png) || strlen($png)<8 || strlen($png)>1048576 ||
            substr($png,0,8)!=="\x89PNG\r\n\x1a\n")
            throw new \InvalidArgumentException('Invalid PNG frame');
        $image=$this->root.'/'.$name.'.png';
        $xmlFile=$this->root.'/'.$name.'.xml';
        if (is_link($image) || is_link($xmlFile) || file_exists($image) || file_exists($xmlFile))
            throw new \RuntimeException('Frame path already exists');
        if(file_put_contents($image,$png,LOCK_EX)!==strlen($png))
            throw new \RuntimeException('Unable to publish image frame');
        $files[]=$image;
        @chmod($image,0644);
        $esc=static function($s){return htmlspecialchars($s,ENT_XML1|ENT_QUOTES,'UTF-8');};
        $document='<?xml version="1.0" encoding="UTF-8"?>'.
            '<CiscoIPPhoneImageFile><Title>Video Test</Title>'.
            '<LocationX>0</LocationX><LocationY>0</LocationY>'.
            '<URL>'.$esc($this->base.'/'.$name.'.png').'</URL></CiscoIPPhoneImageFile>';
        if(file_put_contents($xmlFile,$document,LOCK_EX)!==strlen($document))
            throw new \RuntimeException('Unable to publish frame document');
        $files[]=$xmlFile;
        @chmod($xmlFile,0644);
        $execute='<CiscoIPPhoneExecute><ExecuteItem URL="'.
            $esc($this->base.'/'.$name.'.xml').'"/></CiscoIPPhoneExecute>';
        if(!function_exists('curl_init'))throw new \RuntimeException('cURL required for image push');
        $ch=curl_init('http://'.$this->ip.'/CGI/Execute');
        if(!$ch)throw new \RuntimeException('Cannot initialize phone push');
        try {
            curl_setopt_array($ch,[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>http_build_query(['XML'=>$execute]),
                CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
                CURLOPT_USERPWD=>'sccpnotify:',
                CURLOPT_CONNECTTIMEOUT=>2,
                CURLOPT_TIMEOUT=>3,
                CURLOPT_RETURNTRANSFER=>true
            ]);
            $response=curl_exec($ch);
            $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        } finally {curl_close($ch);}
        return $code===200 && is_string($response) &&
            strpos($response,'CiscoIPPhoneError')===false;
    }
    public function cleanup(array $files) {
        foreach($files as $path) {
            if (is_string($path) && dirname($path)===$this->root &&
                is_file($path) && !is_link($path) &&
                preg_match('/^media-demo-[a-f0-9]{16}-[0-9]+[.]((png)|(xml))$/D',basename($path)))
                @unlink($path);
        }
    }
}
