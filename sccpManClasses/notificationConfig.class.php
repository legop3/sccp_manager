<?php
namespace FreePBX\modules\Sccp_manager;

/** Notification settings stored with other Phone Content settings. */
final class notificationConfig {
    private $module;
    public function __construct($module) { $this->module=$module; }
    public function appearance() {
        $defaults=['theme'=>'cisco','colors'=>[],'stripes'=>[],'stripe_position'=>'off'];
        $saved=$this->module->getConfig('phonecontent_notification_appearance');
        if (is_array($saved)) return array_merge($defaults,array_intersect_key($saved,$defaults));
        return $defaults;
    }
    private function saveAppearance(array $post) {
        $settings=$this->appearance();
        $theme=(string)($post['theme']??$settings['theme']);
        if (!in_array($theme,['cisco','ocean','charcoal','plum','forest','custom'],true))
            throw new \InvalidArgumentException('Invalid notification theme');
        $settings['theme']=$theme;
        foreach (['background','header','header_text','name_text','secondary_text','details_background','details_label','details_text','accent'] as $key) {
            $value=(string)($post['colors'][$key]??$settings['colors'][$key]??'');
            if ($value!=='' && !preg_match('/^#[0-9a-fA-F]{6}$/D',$value))
                throw new \InvalidArgumentException('Invalid notification color');
            $settings['colors'][$key]=$value;
        }
        $position=(string)($post['stripe_position']??$settings['stripe_position']);
        if (!in_array($position,['off','header','top','bottom','background'],true))
            throw new \InvalidArgumentException('Invalid stripe placement');
        $settings['stripe_position']=$position;
        $settings['stripes']=[];
        foreach (array_slice((array)($post['stripes']??[]),0,10) as $color) {
            if ($color==='' || $color===null) continue;
            if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/D',$color))
                throw new \InvalidArgumentException('Invalid stripe color');
            $settings['stripes'][]=$color;
        }
        $this->module->setConfig('phonecontent_notification_appearance',$settings);
    }
    /** Global hardware Help button mapping; independent of notification kinds. */
    public function helpButton() {
        $saved=$this->module->getConfig('phonecontent_notification_help_button');
        $default=['action'=>'default','url'=>''];
        return is_array($saved)?array_merge($default,array_intersect_key($saved,$default)):$default;
    }
    public function all() {
        $defaults=[
            'voicemail'=>['enabled'=>false,'sound'=>''],
            'missed'=>['enabled'=>false,'sound'=>''],
            'scheduled'=>['enabled'=>false,'title'=>'Hello','message'=>'A message for everyone','sound'=>'','interval'=>60,'time'=>'','title_url'=>'','message_url'=>'','image_url'=>'','source'=>'custom']
        ];
        $saved=$this->module->getConfig('phonecontent_notifications');
        if (is_array($saved)) foreach ($defaults as $kind=>$data)
            if (isset($saved[$kind]) && is_array($saved[$kind]))
                $defaults[$kind]=array_merge($data,array_intersect_key($saved[$kind],$data));
        return $defaults;
    }
    public function save($submitted) {
        if (!is_array($submitted)) throw new \InvalidArgumentException('Invalid notification configuration');
        if (isset($submitted['help_button'])) {
            $help=(array)$submitted['help_button'];
            $action=(string)($help['action']??'default');
            $url=trim((string)($help['url']??''));
            if (!in_array($action,['default','notifications','custom'],true))
                throw new \InvalidArgumentException('Unknown Help button action');
            if ($action==='custom' && (strlen($url)>500 || !filter_var($url,FILTER_VALIDATE_URL) ||
                !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)))
                throw new \InvalidArgumentException('Enter an HTTP/HTTPS Help button URL');
            $this->module->setConfig('phonecontent_notification_help_button',
                ['action'=>$action,'url'=>$action==='custom'?$url:'']);
        }
        $values=$this->all();
        if (isset($submitted['appearance']) && is_array($submitted['appearance']))
            $this->saveAppearance($submitted['appearance']);
        $sounds=[''];
        foreach ($this->module->phonecontent->ringtones() as $ring) $sounds[]=(string)($ring['id']??'');
        foreach ($values as $kind=>$settings) {
            $post=isset($submitted[$kind])&&is_array($submitted[$kind])?$submitted[$kind]:[];
            $values[$kind]['enabled']=!empty($post['enabled']);
            $sound=(string)($post['sound']??'');
            if (!in_array($sound,$sounds,true)) throw new \InvalidArgumentException('Unknown ringtone');
            $values[$kind]['sound']=$sound;
            foreach (['title','message','title_url','message_url','image_url'] as $key) {
                if (!array_key_exists($key,$settings)) continue;
                $v=trim((string)($post[$key]??''));
                if (strlen($v)>($key==='message'?2048:500)) throw new \InvalidArgumentException('Notification field too long');
                if (substr($key,-4)==='_url' && $v!=='' && !preg_match('~^https?://~i',$v)) throw new \InvalidArgumentException('Only HTTP/HTTPS URLs supported');
                $values[$kind][$key]=$v;
            }
            if ($kind==='scheduled') {
                $source=(string)($post['source']??'custom');
                if (!in_array($source,['custom','dog','xkcd'],true)) throw new \InvalidArgumentException('Unknown scheduled source');
                $values[$kind]['source']=$source;
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

    }
}
