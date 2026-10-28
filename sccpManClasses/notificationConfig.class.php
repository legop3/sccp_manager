<?php
namespace FreePBX\modules\Sccp_manager;

/** Notification settings stored with other Phone Content settings. */
final class notificationConfig {
    private $module;
    public function __construct($module) { $this->module=$module; }
    public function all() {
        $defaults=[
            'voicemail'=>['enabled'=>false,'style'=>'text','title'=>'New Voicemail','message'=>"From: {caller}\\nNumber: {number}\\nReceived: {received}\\nLength: {length}\\nMailbox: {extension}\\nMessages waiting: {count}",'sound'=>'','duration'=>8,'theme'=>'ocean','show_number'=>true,'show_details'=>true,'colors'=>[],'stripes'=>[],'stripe_position'=>'off'],
            'missed'=>['enabled'=>false,'style'=>'text','title'=>'Missed Call','message'=>"From: {caller}\\nNumber: {number}\\nWhen: {when}\\nExtension: {extension}\\nResult: {reason}",'sound'=>'','duration'=>8,'theme'=>'ocean','show_number'=>true,'show_details'=>true,'colors'=>[],'stripes'=>[],'stripe_position'=>'off'],
            'scheduled'=>['enabled'=>false,'style'=>'text','title'=>'Hello','message'=>'A message for everyone','sound'=>'','duration'=>8,'interval'=>60,'time'=>'','title_url'=>'','message_url'=>'','image_url'=>'','source'=>'custom']
        ];
        $saved=$this->module->getConfig('phonecontent_notifications');
        if (is_array($saved)) foreach ($defaults as $kind=>$data)
            if (isset($saved[$kind]) && is_array($saved[$kind]))
                $defaults[$kind]=array_merge($data,array_intersect_key($saved[$kind],$data));
        // Migrate only the original shipped one-line messages. Never replace
        // a user's custom template.
        if ($defaults['voicemail']['message']==='You have {count} new voicemail(s)')
            $defaults['voicemail']['message']="From: {caller}\\nNumber: {number}\\nReceived: {received}\\nLength: {length}\\nMailbox: {extension}\\nMessages waiting: {count}";
        if ($defaults['missed']['message']==='Missed call from {caller}')
            $defaults['missed']['message']="From: {caller}\\nNumber: {number}\\nWhen: {when}\\nExtension: {extension}\\nResult: {reason}";
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
            if ($kind!=='scheduled') {
                $theme=(string)($post['theme']??$settings['theme']);
                if (!in_array($theme,['ocean','charcoal','plum','forest','custom'],true)) throw new \InvalidArgumentException('Invalid notification theme');
                $values[$kind]['theme']=$theme;
                $values[$kind]['show_number']=!empty($post['show_number']);
                $values[$kind]['show_details']=!empty($post['show_details']);
                foreach (['background','header','header_text','name_text','secondary_text','details_background','details_label','details_text','accent'] as $key) {
                    $value=(string)($post['colors'][$key]??$settings['colors'][$key]??'');
                    if ($value!=='' && !preg_match('/^#[0-9a-fA-F]{6}$/D',$value)) throw new \InvalidArgumentException('Invalid notification color');
                    $values[$kind]['colors'][$key]=$value;
                }
                $stripes=(array)($post['stripes']??[]);
                $values[$kind]['stripe_position']=in_array(($post['stripe_position']??'off'),['off','header','top','bottom','background'],true)?$post['stripe_position']:'off';
                $values[$kind]['stripes']=[];
                foreach (array_slice($stripes,0,10) as $color) {
                    if ($color==='' || $color===null) continue;
                    if (!is_string($color)||!preg_match('/^#[0-9a-fA-F]{6}$/D',$color)) throw new \InvalidArgumentException('Invalid stripe color');
                    $values[$kind]['stripes'][]=$color;
                }
            }
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
// Cisco XML browser omits pages already expired at load from its URL stack.
// Notifications are launchers, not a destination to return to after voicemail.
header('Expires: Thu, 01 Jan 2004 00:00:00 GMT');
readfile($file);
PHP;
        if (file_put_contents($displayPath,$displayScript."\n",LOCK_EX)===false)
            throw new \RuntimeException('Cannot publish Cisco XML notification display endpoint.');
        chmod($displayPath,0644);
    }
}
