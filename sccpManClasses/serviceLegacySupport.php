<?php
namespace FreePBX\modules\Sccp_manager;
/** Shared compatibility functionality for the existing phone services. */
final class serviceLegacySupport {
    public $module;
    private $notificationNavigation;
    public function __construct($module,$navigation=null) {
        $this->module=$module;
        $this->notificationNavigation=$navigation;
    }
    public function queueSoftkey($type='text') {
        if ($this->notificationNavigation && $this->notificationNavigation->active())
            return notificationNavigation::xmlSoftkeys($this->notificationNavigation->softkeys($type));
        return '';
    }
    public function esc($s) {return htmlspecialchars((string)$s, ENT_XML1|ENT_QUOTES, 'UTF-8');}
    public function xml($name,$data) {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<CiscoIPPhoneText><Title>'.$this->esc(substr($name,0,32)).'</Title><Text>'.$this->esc($data).'</Text>' . ($this->notificationNavigation && $this->notificationNavigation->active() ? '<Prompt>Notification menu</Prompt>'.$this->queueSoftkey() : '') . '</CiscoIPPhoneText>';
    }
    public function menu($title,$entries) {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<CiscoIPPhoneMenu><Title>'.$this->esc(substr($title,0,32)).'</Title><Prompt>'.'Select an item'.'</Prompt>';
        foreach($entries as $entry) {
            echo '<MenuItem><Name>'.$this->esc(substr($entry[0],0,64)).'</Name><URL>'.$this->esc($entry[1]).'</URL></MenuItem>';
        }
        echo $this->queueSoftkey('menu').'</CiscoIPPhoneMenu>';
    }
    public function form($title,$url,$label,$param,$mode='A') {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<CiscoIPPhoneInput><Title>'.$this->esc($title).'</Title><Prompt>'.$this->esc($label).'</Prompt><URL>'.$this->esc($url).'</URL>';
        echo '<InputItem><DisplayName>'.$this->esc($label).'</DisplayName><QueryString>'.$this->esc($param).'</QueryString><InputFlags>'.$this->esc($mode).'</InputFlags></InputItem>'.$this->queueSoftkey('input').'</CiscoIPPhoneInput>';
    }
    public function statePath() {return '/var/spool/asterisk/sccp-manager-services.json';}
    public function transact($callback) {
        $path=$this->statePath();
        $handle=@fopen($path,'c+');
        if(!$handle) throw new \RuntimeException('Services storage cannot be opened.');
        try {
            if(!flock($handle,LOCK_EX)) throw new \RuntimeException('Unable to lock service storage.');
            rewind($handle);
            $state=json_decode(stream_get_contents($handle),true);
            if(!is_array($state))$state=['messages'=>[],'alarms'=>[]];
            $result=$callback($state);
            rewind($handle);ftruncate($handle,0);
            fwrite($handle,json_encode($state,JSON_UNESCAPED_SLASHES));fflush($handle);
            flock($handle,LOCK_UN);
            return $result;
        } finally {fclose($handle);}
    }
    public function url($device) {
        // Provision with the VPN-facing address for Cisco VPN handsets.
        $base=$this->module->phonecontent->baseUrl($device);
        if($base==='') throw new \RuntimeException('Phone Content address is not configured.');
        return $base.'/service.php?'.http_build_query([
            'phone'=>$device,'key'=>$this->module->phonevoicemail->tokenForDevice($device)
        ],'','&',PHP_QUERY_RFC3986);
    }
    public function recipients($me) {
        $found=[];
        foreach ((new notificationDelivery($this->module))->devices() as $device=>$ip) {
            try {
                $identity=$this->module->phonevoicemail->destinationForDevice($device);
                $found[$device]=['line'=>$identity['extension'],'ip'=>$ip];
            } catch(\Throwable $e){}
        }
        return $found;
    }
    public function fetch($url) {
        // Cache public forecast and RSS data so multiple phones do not
        // re-fetch it for each menu navigation.
        $cache='/var/spool/asterisk/sccp-svc-'.hash('sha256',$url).'.cache';
        $ttl=strpos($url,'open-meteo.com')!==false?900:600;
        if(is_file($cache) && !is_link($cache) && filemtime($cache)>time()-$ttl) {
            $cached=@file_get_contents($cache);
            if($cached!==false && $cached!=='')return $cached;
        }
        $context=stream_context_create(['http'=>['timeout'=>5,'follow_location'=>0,
            'user_agent'=>'SCCP-Manager/17.1.84']]);
        $raw=@file_get_contents($url,false,$context,0,180000);
        if($raw!==false && $raw!=='') {
            if(!is_link($cache))@file_put_contents($cache,$raw,LOCK_EX);
            return $raw;
        }
        // Stale data is preferable to a blank phone when an external API fails.
        $cached=(!is_link($cache) && is_file($cache))?@file_get_contents($cache):false;
        return $cached===false?'':$cached;
    }
}
