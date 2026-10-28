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
    // Cisco 7945G appends ?UserID=... even if authenticationURL
    // already has ?token=..., producing token=HEX?UserID=...
    // Read that exact firmware format without accepting arbitrary tokens.
    $rawQuery=(string)($_SERVER['QUERY_STRING']??'');
    $embedded=[];
    $hasEmbeddedUser=(bool)preg_match('/^token=([0-9a-f]{64})\\?UserID=([^&]*)(&|$)/D', $rawQuery, $embedded);
    $user=$hasEmbeddedUser ? rawurldecode($embedded[2]) : (string)($_REQUEST['UserID']??'');
    $device=strtoupper((string)($_REQUEST['devicename']??''));
    $token=$hasEmbeddedUser ? $embedded[1] : (string)($_GET['token']??'');
    $validDevice=(bool)preg_match('/^SEP[0-9A-F]{12}$/D',$device);
    $expected=$validDevice ? hash_hmac('sha256',$device,$secret) : '';
    echo $secret!=='' && $user==='sccpnotify' && $validDevice &&
        hash_equals($expected,$token)
        ? 'AUTHORIZED' : 'UN-AUTHORIZED';
} catch (\Throwable $e) { echo 'UN-AUTHORIZED'; }
PHP;
        if (!is_dir(dirname($path)) || is_link(dirname($path)) ||
            file_put_contents($path,$script."\n",LOCK_EX)===false)
            throw new \RuntimeException('Cannot publish Cisco push authentication endpoint.');
        chmod($path,0644);

        // Cisco 79xx expects text/xml, while Apache's default .xml MIME
        // mapping can be application/xml. Only serve generated notifications.
        $displayPath=dirname($path).'/notification-display.php';
        $displayScript=<<<'PHP'
<?php
ini_set('display_errors','0');
$id=(string)($_GET['id']??'');
if (!preg_match('/^[0-9a-f]{24}$/D',$id)) {
    http_response_code(404);
    exit;
}
$file=__DIR__.'/notification-'.$id.'.xml';
if (!is_file($file) || is_link($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/xml; charset=UTF-8');
header('Cache-Control: no-store');
readfile($file);
PHP;
        if (file_put_contents($displayPath,$displayScript."\n",LOCK_EX)===false)
            throw new \RuntimeException('Cannot publish Cisco XML notification display endpoint.');
        chmod($displayPath,0644);
    }
}
