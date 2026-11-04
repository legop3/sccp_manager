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
    private $module;
    private $device;
    public function __construct($module,$device) {
        $this->module=$module;
        $this->device=$device;
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
    public static function document($url,$stopUrl=null) {
        if(!is_string($url) ||
            !preg_match('~^http://[0-9.]+/sccp-manager/(?:[a-z0-9-]+\\.png|media-frame\\.php\\?phone=SEP[A-F0-9]{12}&session=[a-f0-9]{16}&key=[a-f0-9]{32})$~D',$url))
            throw new \InvalidArgumentException('Invalid media PNG URL');
        return '<?xml version="1.0" encoding="UTF-8"?>'.
            '<CiscoIPPhoneImageFile><Title>media test</Title>'.
            '<LocationX>0</LocationX><LocationY>0</LocationY>'.
            '<URL>'.htmlspecialchars($url,ENT_XML1|ENT_QUOTES,'UTF-8').
            '</URL>'.($stopUrl!==null ? '<Prompt>media player</Prompt><SoftKeyItem><Name>Stop</Name><URL>'.htmlspecialchars($stopUrl,ENT_XML1|ENT_QUOTES,'UTF-8').'</URL><Position>1</Position></SoftKeyItem>' : '').'</CiscoIPPhoneImageFile>';
    }
    public function frame($name,$png,array &$files) {
        if (!preg_match('/^[a-z0-9-]{1,90}$/D',(string)$name))
            throw new \InvalidArgumentException('Invalid frame name');
        if (!is_string($png) || strlen($png)<8 || strlen($png)>1048576 ||
            substr($png,0,8)!=="\x89PNG\r\n\x1a\n")
            throw new \InvalidArgumentException('Invalid PNG frame');
        $image=$this->root.'/'.$name.'.png';
        // Deliver the small display XML directly to the handset. The phone
        // then has only one PNG URL to fetch; static .xml MIME mismatches and
        // out-of-order follow-up XML downloads cannot break the frame.
        if(is_link($image) || file_exists($image))
            throw new \RuntimeException('Frame path already exists');
        if(file_put_contents($image,$png,LOCK_EX)!==strlen($png))
            throw new \RuntimeException('Unable to publish image frame');
        $files[]=$image;
        @chmod($image,0644);
        $url=$this->base.'/'.$name.'.png';
        // Check the published image really is available over HTTP.
        if(!function_exists('curl_init'))throw new \RuntimeException('cURL required for frame delivery');
        $check=curl_init($url);
        if(!$check)throw new \RuntimeException('Cannot check published frame URL');
        try {
            curl_setopt_array($check,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>2,
                CURLOPT_TIMEOUT=>4,
                CURLOPT_FOLLOWLOCATION=>false
            ]);
            $served=curl_exec($check);
            $status=(int)curl_getinfo($check,CURLINFO_RESPONSE_CODE);
        } finally { curl_close($check); }
        if($status!==200 || !is_string($served) || $served!==$png)
            throw new \RuntimeException('Published frame URL unavailable (HTTP '.$status.')');
        return $this->push($url);
    }
    /** Same URL on every push. One push is issued only after the prior GET ends. */
    public function push($url) {
        // Only the live media player receives Stop; synthetic image tests
        // and other image pushes keep their original XML unchanged.
        $stopUrl=null;
        if(str_contains($url,'/media-frame.php?')) {
            try {
                $stopUrl=$this->base.'/service.php?'.http_build_query([
                    'phone'=>$this->device,
                    'key'=>$this->module->phonevoicemail->tokenForDevice($this->device),
                    'app'=>'media-player',
                    'op'=>'stop'
                ],'','&',PHP_QUERY_RFC3986);
            } catch(\Throwable $e) {
                // Failure to prepare a control must never prevent video.
                $stopUrl=null;
            }
        }
        $document=self::document($url,$stopUrl);
        if(!function_exists('curl_init'))throw new \RuntimeException('cURL required for image push');
        $ch=curl_init('http://'.$this->ip.'/CGI/Execute');
        if(!$ch)throw new \RuntimeException('Cannot initialize phone push');
        try {
            curl_setopt_array($ch,[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>http_build_query(['XML'=>$document]),
                CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
                CURLOPT_USERPWD=>'sccpnotify:',
                CURLOPT_CONNECTTIMEOUT=>2,
                CURLOPT_TIMEOUT=>3,
                CURLOPT_RETURNTRANSFER=>true
            ]);
            $response=curl_exec($ch);
            $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        } finally {curl_close($ch);}
        $accepted=$code===200 && is_string($response) &&
            strpos($response,'CiscoIPPhoneError')===false;
        if(!$accepted)
            error_log('SCCP media frame rejected: HTTP '.$code.' '.
                substr(trim((string)$response),0,120));
        return $accepted;
    }
    /** IP resolved from the same active SCCP session as CGI pushes. */
    public function ip() { return $this->ip; }
    public function cleanup(array $files) {
        foreach($files as $path) {
            if (is_string($path) && dirname($path)===$this->root &&
                is_file($path) && !is_link($path) &&
                preg_match('/^media-demo-[a-f0-9]{16}-[0-9]+[.]((png)|(xml))$/D',basename($path)))
                @unlink($path);
        }
    }
}
