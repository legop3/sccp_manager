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
        $config=new notificationConfig($this->module);
        $settings=$config->all();
        $appearance=$config->appearance();
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
        // Every visible notification is rendered by the same themed image builder.
        // Settings may still describe a source/sound, but not a second XML layout.
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
            $actions=[];
            $graphic=null;
            $renderFields=[
                'title'=>$title,'primary'=>$message,'secondary'=>'',
                'detail'=>'','timestamp'=>date('m/d/Y g:i A')
            ];
            if ($kind==='voicemail') {
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
                $renderFields=[
                    'title'=>'New voicemail','primary'=>(string)($fields['caller']??'Unknown caller'),
                    'secondary'=>(string)($fields['number']??''),
                    'detail'=>isset($fields['length'])&&$fields['length']!==''?'Length: '.$fields['length']:'',
                    'timestamp'=>(string)($fields['when']??$fields['received']??'')
                ];
                if (preg_match('/^[0-9]{1,6}$/D',$renderFields['secondary']))
                    $renderFields['secondary']='Ext. '.$renderFields['secondary'];
                $graphic=phoneNotifications::graphic($this->module,$renderFields,[],$appearance,$device);
                if (!$graphic) throw new \RuntimeException('Graphical notification generation failed');
            } elseif ($kind==='missed') {
                // Present the user's public number, not the SCCP-only line.
                $identity=(new phonevoicemail($this->module))->destinationForDevice($device);
                $fields['extension']=$identity['extension'];
                $actions=[];
                $number=(string)($fields['number']??'');
                if (preg_match('/^[+0-9*#]{1,32}$/D',$number)) $actions['Call Back']='Dial:'.$number;
                $renderFields=[
                    'title'=>'Missed call','primary'=>(string)($fields['caller']??'Unknown caller'),
                    'secondary'=>(string)($fields['number']??''),
                    'detail'=>'','timestamp'=>(string)($fields['when']??$fields['received']??'')
                ];
                if (preg_match('/^[0-9]{1,6}$/D',$renderFields['secondary']))
                    $renderFields['secondary']='Ext. '.$renderFields['secondary'];
                $graphic=phoneNotifications::graphic($this->module,$renderFields,[],$appearance,$device);
                if (!$graphic) throw new \RuntimeException('Graphical notification generation failed');
            }
            if ($graphic===null)
                $graphic=phoneNotifications::graphic($this->module,$renderFields,[],$appearance,$device);
            if (!$graphic) throw new \RuntimeException('Graphical notification generation failed');
            $delivery=(new notificationQueue($this->module))->send($device,$ip,
                new notificationMessage('',$actions,$graphic,$sound??null));
            $status=$delivery['status'];
            $response=$delivery['response'];
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
