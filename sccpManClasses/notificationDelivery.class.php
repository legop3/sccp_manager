<?php
namespace FreePBX\modules\Sccp_manager;

final class notificationDelivery {
    private $module;
    public function __construct($module) { $this->module=$module; }

    public function devices() {
        $rows=[]; $code=0;
        exec('/usr/sbin/asterisk -rx '.escapeshellarg('sccp show sessions').' 2>/dev/null',$rows,$code);
        if ($code!==0) return [];
        $result=[];
        foreach ($rows as $line) {
            if (!preg_match('/\bSEP([A-F0-9]{12})\b/i',$line,$device)) continue;
            if (!preg_match('/\b((?:\d{1,3}\.){3}\d{1,3})\b/',$line,$address)) continue;
            if (!filter_var($address[1],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) continue;
            $result['SEP'.strtoupper($device[1])]=$address[1];
        }
        return $result;
    }

    public function configuredSound($soundId) {
        if ($soundId==='') return null;
        foreach ($this->module->phonecontent->ringtones() as $ring)
            if (($ring['id']??null)===$soundId && preg_match('/^[0-9a-f]{16}$/D',$soundId))
                return 'PC-'.$soundId.'.raw';
        return null;
    }

    private function publicDocument($xml) {
        $dir=$this->module->phonecontent->publicPath();
        $base=$this->module->phonecontent->baseUrl();
        if (!is_dir($dir) || is_link($dir) || $base==='') return null;
        $name='notification-'.bin2hex(random_bytes(12)).'.xml';
        if (file_put_contents($dir.'/'.$name,$xml,LOCK_EX)===false) return null;
        chmod($dir.'/'.$name,0644);
        foreach (glob($dir.'/notification-*.xml')?:[] as $old)
            if (is_file($old) && !is_link($old) && filemtime($old)<time()-3600) @unlink($old);
        return $base.'/'.$name;
    }

    private function forDevice($device,$extensions) {
        if ($extensions===null) return true;
        $output=[];
        exec('/usr/sbin/asterisk -rx '.escapeshellarg('sccp show device '.$device).' 2>/dev/null',$output);
        $status=implode("\n",$output);
        foreach ($extensions as $ext)
            if (preg_match('/(?<!\d)'.preg_quote((string)$ext,'/').'(?!\d)/',$status)) return true;
        return false;
    }

    public function send($kind,$fields=[],$extensions=null) {
        $settings=(new notificationConfig($this->module))->all();
        if (!isset($settings[$kind])) return 0;
        $options=$settings[$kind];
        if (!$options['enabled']) return 0;
        $values=[];
        foreach ($fields as $key=>$value) $values['{'.$key.'}']=(string)$value;
        $title=strtr($options['title'],$values);
        $message=strtr($options['message'],$values);
        if ($kind==='scheduled') {
            $title=notificationSources::text($options['title_url'],$title);
            $message=notificationSources::text($options['message_url'],$message);
        }
        $sound=$this->configuredSound($options['sound']);
        $url=null;
        if ($options['style']!=='sound') {
            if ($kind==='scheduled' && $options['style']==='image' && $options['image_url']!=='') {
                $image=notificationSources::image($options['image_url'],$this->module,$message);
                $xml=$image?'<CiscoIPPhoneImageFile><Title>'.htmlspecialchars(substr($title,0,32),ENT_XML1).'</Title><LocationX>0</LocationX><LocationY>0</LocationY><URL>'.htmlspecialchars($image,ENT_XML1).'</URL></CiscoIPPhoneImageFile>':phoneNotifications::text($title,$message);
            } else {
                $xml=$options['style']==='status'
                    ? phoneNotifications::status($message,$options['duration'])
                    : phoneNotifications::text($title,$message);
            }
            $url=$this->publicDocument($xml);
        }
        if (!$url && !$sound) return 0;
        $secret=(string)$this->module->getConfig('phonecontent_push_secret');
        if ($secret==='') return 0;
        $sent=0;
        foreach ($this->devices() as $device=>$ip) {
            if (!$this->forDevice($device,$extensions)) continue;
            $xml='<CiscoIPPhoneExecute>';
            if ($sound) $xml.='<ExecuteItem URL="Play:'.htmlspecialchars($sound,ENT_XML1).'"/>';
            if ($url) $xml.='<ExecuteItem URL="'.htmlspecialchars($url,ENT_XML1).'"/>';
            $xml.='</CiscoIPPhoneExecute>';
            $ch=curl_init('http://'.$ip.'/CGI/Execute');
            curl_setopt_array($ch,[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>http_build_query(['XML'=>$xml]),
                CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
                CURLOPT_USERPWD=>'sccpnotify:'.$secret,
                CURLOPT_CONNECTTIMEOUT=>2,
                CURLOPT_TIMEOUT=>4,
                CURLOPT_RETURNTRANSFER=>true
            ]);
            $response=curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($status===200 && is_string($response) && strpos($response,'CiscoIPPhoneError')===false) $sent++;
        }
        return $sent;
    }
}
