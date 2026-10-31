<?php
namespace FreePBX\modules\Sccp_manager;

final class notificationDelivery {
    private $module;
    public function __construct($module) { $this->module=$module; }

    public function devices() {
        $result=[];
        // The SCCP session list contains the socket peer IP; it is the
        // reachable VPN address for remote phones, unlike provisioning IP.
        foreach (['sccp show sessions', 'sccp show sessions all', 'sccp show devices'] as $command) {
            $rows=[];$code=0;
            exec('/usr/sbin/asterisk -rx '.escapeshellarg($command).' 2>/dev/null',$rows,$code);
            if ($code!==0) continue;
            foreach ($rows as $line) {
                if (!preg_match('/\bSEP([A-F0-9]{12})\b/i',$line,$device)) continue;
                if (!preg_match_all('/(?<![0-9])((?:[0-9]{1,3}\.){3}[0-9]{1,3})(?![0-9])/', $line,$addresses)) continue;
                foreach ($addresses[1] as $ip) {
                    if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || $ip==='0.0.0.0') continue;
                    $result['SEP'.strtoupper($device[1])]=$ip;
                    break;
                }
            }
            if ($result) break;
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
        if (!is_dir($dir) || is_link($dir)) return null;
        $name='notification-'.bin2hex(random_bytes(12)).'.xml';
        if (file_put_contents($dir.'/'.$name,$xml,LOCK_EX)===false) return null;
        chmod($dir.'/'.$name,0644);
        foreach (glob($dir.'/notification-*.xml')?:[] as $old)
            if (is_file($old) && !is_link($old) && filemtime($old)<time()-3600) @unlink($old);
        // The 7945G XML browser expects text/xml. Apache serves .xml as
        // application/xml on some FreePBX hosts, causing "HTTP file error".
        // Serve through a fixed, read-only endpoint with the proper MIME type.
        return substr($name,13,24);
    }

    /** All notification display links require a recipient; never fall back to LAN. */
    private function displayUrl($device, $id) {
        if ($id === null) return null;
        $base=$this->module->phonecontent->baseUrl($device);
        if ($base==='') throw new \RuntimeException('No HTTP address configured for '.$device);
        return $base.'/notification-display.php?id='.$id;
    }

    private function forDevice($device, $extensions, $kind) {
        if ($extensions === null) return true;
        try {
            $identity = (new phonevoicemail($this->module))->destinationForDevice($device);
        } catch (\Throwable $e) {
            error_log('SCCP notification destination lookup: '.$e->getMessage());
            return false;
        }
        return self::matchesDestination($identity, $kind, $extensions);
    }

    public static function matchesDestination(array $identity, $kind, array $extensions) {
        // CDR missed calls address the SCCP line; voicemail addresses the mailbox.
        $target = $kind === 'missed' ? (string)($identity['line'] ?? '') :
            (string)($identity['mailbox'] ?? '');
        if ($target === '') return false;
        foreach ($extensions as $ext) if ($target === (string)$ext) return true;
        return false;
    }

    public function send($kind,$fields=[],$extensions=null,$test=false) {
        $settings=(new notificationConfig($this->module))->all();
        if (!isset($settings[$kind])) return 0;
        $options=$settings[$kind];
        if (!$options['enabled'] && !$test) return 0;
        $values=[];
        foreach ($fields as $key=>$value) $values['{'.$key.'}']=(string)$value;
        $title=strtr($options['title'],$values);
        $message=strtr($options['message'],$values);
        $builtIn=null;
        if ($kind==='scheduled' && ($options['source']??'custom')!=='custom') {
            $builtIn=notificationSources::builtIn($options['source']);
            if ($builtIn===null) throw new \RuntimeException('Built-in notification source unavailable: '.$options['source']);
            $title=$builtIn['title'];
            $message=$builtIn['message'];
        }
        if ($kind==='scheduled' && $builtIn===null) {
            $title=notificationSources::text($options['title_url'],$title);
            $message=notificationSources::text($options['message_url'],$message);
        }
        $sound=$this->configuredSound($options['sound']);
        $url=null;
        if ($options['style']!=='sound') {
            if ($kind==='scheduled' && $options['style']==='image' && ($builtIn!==null || $options['image_url']!=='')) {
                $image=notificationSources::image($builtIn!==null ? $builtIn['image_url'] : $options['image_url'],$this->module,$message,null);
                $xml=$image?'<CiscoIPPhoneImageFile><Title>'.htmlspecialchars(substr($title,0,32),ENT_XML1).'</Title><LocationX>0</LocationX><LocationY>0</LocationY><URL>'.htmlspecialchars($image,ENT_XML1).'</URL></CiscoIPPhoneImageFile>':phoneNotifications::text($title,$message);
            } else {
                $xml=$options['style']==='status'
                    ? phoneNotifications::status($message,$options['duration'])
                    : phoneNotifications::text($title,$message);
            }
            $documentId=$this->publicDocument($xml);
        }
        if (!$documentId && !$sound) return 0;
        $secret=(string)$this->module->getConfig('phonecontent_push_secret');
        if ($secret==='') throw new \RuntimeException('Push authentication is not configured. Save notification settings first.');
        $sent=0;
        $devices=$this->devices();
        if (!$devices) throw new \RuntimeException('No connected SCCP phones were detected from Asterisk session/device output.');
        $errors=[];
        foreach ($devices as $device=>$ip) {
            if (!$this->forDevice($device,$extensions,$kind)) continue;
            // Every handset needs its own authenticated voicemail links.
            // Do not publish the bearer token in a shared document.
            $deviceUrl=$documentId ? $this->displayUrl($device,$documentId) : null;
            if ($documentId && strpos($xml,'__SCCP_NOTIFICATION_BASE__')!==false) {
                // Scheduled images use the same network base as their recipient.
                $perDeviceXml=str_replace('__SCCP_NOTIFICATION_BASE__',
                    $this->module->phonecontent->baseUrl($device),$xml);
                $deviceUrl=$this->displayUrl($device,$this->publicDocument($perDeviceXml));
            }
            if ($kind==='voicemail' && $options['style']==='text') {
                $actions=[];
                try {
                    $vm=new phonevoicemail($this->module);
                    if ($vm->mailboxForDevice($device)===(string)($fields['extension']??'')) {
                        $base=$vm->serviceUrl($device).'&service=1';
                        $id=(string)($fields['id']??'');
                        if ($id!=='' && preg_match('/^[A-Za-z0-9_.-]{1,120}$/D',$id)) {
                            // Resolve the event's original ID; never substitute
                            // an unrelated newer message.
                            $item=phonevoicemail::resolveMessage($vm->listMessages((string)$fields['extension']),$id);
                            $actions['Listen']=$base.'&single=1&op=play&id='.rawurlencode($item['id']);
                            $callback=phonevoicemail::callbackNumber($item);
                            if ($callback!=='')
                                $actions['Call Back']='Dial:'.$callback;
                            else
                                $actions['Details']=$base.'&single=1&op=detail&id='.rawurlencode($item['id']);
                        }
                        $actions['Messages']=$base;
                    }
                } catch (\Throwable $e) {
                    error_log('SCCP notification voicemail actions: '.$e->getMessage());
                }
                $actions['Dismiss']='SoftKey:Exit';
                $graphic=phoneNotifications::graphic($this->module,'voicemail',$fields,$actions,$options,$device);
                if (!$graphic) throw new \RuntimeException('Graphical notification generation failed');
                $deviceUrl=$this->displayUrl($device,$this->publicDocument($graphic));
            } elseif ($kind==='missed' && $options['style']==='text') {
                // Present the user's public number, not the SCCP-only line.
                $identity=(new phonevoicemail($this->module))->destinationForDevice($device);
                $fields['extension']=$identity['extension'];
                $actions=[];
                $number=(string)($fields['number']??'');
                if (preg_match('/^[+0-9*#]{1,32}$/D',$number)) $actions['Call Back']='Dial:'.$number;
                $actions['Dismiss']='SoftKey:Exit';
                $graphic=phoneNotifications::graphic($this->module,'missed',$fields,$actions,$options,$device);
                if (!$graphic) throw new \RuntimeException('Graphical notification generation failed');
                $deviceUrl=$this->displayUrl($device,$this->publicDocument($graphic));
            }
            $xml='<CiscoIPPhoneExecute>';
            if ($sound) $xml.='<ExecuteItem URL="Play:'.htmlspecialchars($sound,ENT_XML1).'"/>';
            if ($deviceUrl) $xml.='<ExecuteItem URL="'.htmlspecialchars($deviceUrl,ENT_XML1).'"/>';
            $xml.='</CiscoIPPhoneExecute>';
            $ch=curl_init('http://'.$ip.'/CGI/Execute');
            curl_setopt_array($ch,[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>http_build_query(['XML'=>$xml]),
                CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
                // Cisco 79xx forwards Basic UserID but can omit Password in
                // its authenticationURL callback. Use a secret-bearing identity.
                CURLOPT_USERPWD=>'sccpnotify:',
                CURLOPT_CONNECTTIMEOUT=>2,
                CURLOPT_TIMEOUT=>4,
                CURLOPT_RETURNTRANSFER=>true
            ]);
            $response=curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($status===200 && is_string($response) && strpos($response,'CiscoIPPhoneError')===false) {
                $sent++;
            } else {
                $errors[]=$device.' ('.$ip.'): HTTP '.$status.', '.substr(trim((string)$response),0,160);
            }
        }
        if ($test && $errors) throw new \RuntimeException('Sent to '.$sent.' of '.count($devices).' phones. Failures: '.implode('; ',$errors));
        return $sent;
    }
}
