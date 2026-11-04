<?php
namespace FreePBX\modules\Sccp_manager;

/** Phone-service entrypoint: authentication, catalog, and single registry. */
class phoneservices {
    private $module;
    private $notificationNavigation=null;
    private function queueSoftkey($type='text') {
        if ($this->notificationNavigation && $this->notificationNavigation->active())
            return notificationNavigation::xmlSoftkeys($this->notificationNavigation->softkeys($type));
        return '';
    }
    public function __construct($module=null) {$this->module=$module;}
    private function esc($s) {return htmlspecialchars((string)$s, ENT_XML1|ENT_QUOTES, 'UTF-8');}
    private function xml($name,$data) {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<CiscoIPPhoneText><Title>'.$this->esc(substr($name,0,32)).'</Title><Text>'.$this->esc($data).'</Text>' . ($this->notificationNavigation && $this->notificationNavigation->active() ? '<Prompt>Notification menu</Prompt>'.$this->queueSoftkey() : '') . '</CiscoIPPhoneText>';
    }
    private function menu($title,$entries) {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<CiscoIPPhoneMenu><Title>'.$this->esc(substr($title,0,32)).'</Title><Prompt>'.'Select an item'.'</Prompt>';
        foreach($entries as $entry) {
            echo '<MenuItem><Name>'.$this->esc(substr($entry[0],0,64)).'</Name><URL>'.$this->esc($entry[1]).'</URL></MenuItem>';
        }
        echo $this->queueSoftkey('menu').'</CiscoIPPhoneMenu>';
    }
    private function form($title,$url,$label,$param,$mode='A') {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<CiscoIPPhoneInput><Title>'.$this->esc($title).'</Title><Prompt>'.$this->esc($label).'</Prompt><URL>'.$this->esc($url).'</URL>';
        echo '<InputItem><DisplayName>'.$this->esc($label).'</DisplayName><QueryString>'.$this->esc($param).'</QueryString><InputFlags>'.$this->esc($mode).'</InputFlags></InputItem>'.$this->queueSoftkey('input').'</CiscoIPPhoneInput>';
    }
    private function requestUrl($query) {
        $base=$this->module->phonecontent->requestBaseUrl();
        return $base.'/service.php?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }
    public function url($device) {
        // Provision with the VPN-facing address for Cisco VPN handsets.
        $base=$this->module->phonecontent->baseUrl($device);
        if($base==='') throw new \RuntimeException('Phone Content address is not configured.');
        return $base.'/service.php?'.http_build_query([
            'phone'=>$device,'key'=>$this->module->phonevoicemail->tokenForDevice($device)
        ],'','&',PHP_QUERY_RFC3986);
    }
    private function auth($query) {
        $device=(string)($query['phone']??'');
        $key=(string)($query['key']??'');
        if(!phonevoicemail::validDevice($device) || !preg_match('/^[a-f0-9]{64}$/D',$key))
            throw new \RuntimeException('Invalid handset credentials.');
        if(!hash_equals($this->module->phonevoicemail->tokenForDevice($device),$key))
            throw new \RuntimeException('Invalid handset credentials.');
        return [$device,$this->module->phonevoicemail->destinationForDevice($device)];
    }
    public function handle($q) {
        header('Content-Type: text/xml; charset=UTF-8');
        header('Cache-Control: no-store');
        header('Expires: Thu, 01 Jan 1970 00:00:01 GMT');
        header('X-Content-Type-Options: nosniff');
        [$device,$identity]=$this->auth($q);
        $this->notificationNavigation=new notificationNavigation($this->module,$device,$q);
        $root=['phone'=>$device,'key'=>(string)$q['key']];
        $root=array_merge($root,$this->notificationNavigation->fields());
        $route=function(array $fields)use($root){
            return $this->requestUrl(array_merge($root,$fields));
        };
        require_once __DIR__.'/serviceRegistry.class.php';
        $registry=new serviceRegistry($this->module);
        $flags=$this->module->phonemenus->serviceFlags();
        $app=(string)($q['app']??'');
        if($app==='' || $app==='home') {
            $entries=[['Screen Off',$route(['app'=>'screenoff'])]];
            foreach($registry->manifests() as $id=>$meta)
                if(!empty($flags[$id]))$entries[]=[(string)$meta['name'],$route(['app'=>$id])];
            $this->menu('Services',$entries);
            return;
        }
        if($app==='screenoff') {
            echo '<?xml version="1.0" encoding="UTF-8"?>'.
                '<CiscoIPPhoneExecute><ExecuteItem URL="Display:Off:0"/></CiscoIPPhoneExecute>';
            return;
        }
        if(empty($flags[$app])) {
            $this->xml('Services','This service is disabled.');
            return;
        }
        if(!isset($registry->manifests()[$app])) {
            $this->xml('Services','Unknown service.');
            return;
        }
        $registry->handle($app,$q,$device,$identity,$route,$this->notificationNavigation);
    }

}
