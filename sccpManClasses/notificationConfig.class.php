<?php
namespace FreePBX\modules\Sccp_manager;

/** Notification settings stored with other Phone Content settings. */
final class notificationConfig {
    private $module;
    public function __construct($module) { $this->module=$module; }
    public function all() {
        $defaults=[
            'voicemail'=>['enabled'=>false,'style'=>'text','title'=>'New Voicemail','message'=>'You have {count} new voicemail(s)','sound'=>'','duration'=>8],
            'missed'=>['enabled'=>false,'style'=>'text','title'=>'Missed Call','message'=>'Missed call from {caller}','sound'=>'','duration'=>8],
            'scheduled'=>['enabled'=>false,'style'=>'text','title'=>'Hello','message'=>'A message for everyone','sound'=>'','duration'=>8,'interval'=>60,'time'=>'','title_url'=>'','message_url'=>'','image_url'=>'']
        ];
        $saved=$this->module->getConfig('phonecontent_notifications');
        if (is_array($saved)) foreach ($defaults as $kind=>$data)
            if (isset($saved[$kind]) && is_array($saved[$kind]))
                $defaults[$kind]=array_merge($data,array_intersect_key($saved[$kind],$data));
        return $defaults;
    }
    public function save($submitted) {
        if (!is_array($submitted)) throw new \InvalidArgumentException('Invalid notification configuration');
        $values=$this->all();
        $sounds=[''];
        foreach ($this->module->phonecontent->ringtones() as $ring) $sounds[]=(string)($ring['id']??'');
        foreach ($values as $kind=>$settings) {
            $post=isset($submitted[$kind])&&is_array($submitted[$kind])?$submitted[$kind]:[];
            $values[$kind]['enabled']=!empty($post['enabled']);
            $style=(string)($post['style']??'text');
            if (!in_array($style,['text','status','image','sound'],true)) throw new \InvalidArgumentException('Invalid display style');
            $values[$kind]['style']=$style;
            $sound=(string)($post['sound']??'');
            if (!in_array($sound,$sounds,true)) throw new \InvalidArgumentException('Unknown ringtone');
            $values[$kind]['sound']=$sound;
            $values[$kind]['duration']=max(2,min(60,(int)($post['duration']??8)));
            foreach (['title','message','title_url','message_url','image_url'] as $key) {
                if (!array_key_exists($key,$settings)) continue;
                $v=trim((string)($post[$key]??''));
                if (strlen($v)>($key==='message'?2048:500)) throw new \InvalidArgumentException('Notification field too long');
                if (substr($key,-4)==='_url' && $v!=='' && !preg_match('~^https?://~i',$v)) throw new \InvalidArgumentException('Only HTTP/HTTPS URLs supported');
                $values[$kind][$key]=$v;
            }
            if ($kind==='scheduled') {
                $values[$kind]['interval']=max(1,min(10080,(int)($post['interval']??60)));
                $t=(string)($post['time']??'');
                if ($t!=='' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$t)) throw new \InvalidArgumentException('Invalid time');
                $values[$kind]['time']=$t;
            }
        }
        $this->module->setConfig('phonecontent_notifications',$values);
        if (!$this->module->getConfig('phonecontent_push_secret')) {
            $this->module->setConfig('phonecontent_push_secret', bin2hex(random_bytes(24)));
        }
        $this->publishAuthentication();
        $this->module->phoneContentRegenerate(false);
    }
    private function publishAuthentication() {
        $path=$this->module->phonecontent->publicPath().'/notification-auth.php';
        $script=<<<'PHP'
<?php
ini_set('display_errors','0');
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
try {
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    $module=\FreePBX::create()->Sccp_manager;
    $secret=(string)$module->getConfig('phonecontent_push_secret');
    $user=(string)($_REQUEST['UserID']??'');
    $password=(string)($_REQUEST['Password']??'');
    // Cisco 79xx may omit Password when calling its authenticationURL.
    // Never accept the public username with an empty password: the
    // Basic identity instead carries a secret derived from the server key.
    $expected='sccpnotify-'.substr(hash_hmac('sha256','cisco-push-user',$secret),0,32);
    echo $secret!=='' && hash_equals($expected,$user) && $password==='' 
        ? 'AUTHORIZED' : 'UN-AUTHORIZED';
} catch (\Throwable $e) { echo 'UN-AUTHORIZED'; }
PHP;
        if (!is_dir(dirname($path)) || is_link(dirname($path)) ||
            file_put_contents($path,$script."\n",LOCK_EX)===false)
            throw new \RuntimeException('Cannot publish Cisco push authentication endpoint.');
        chmod($path,0644);
    }
}
