<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Shared adapter for any notification-linked Cisco XML application.
 * Applications own menus/content; this object owns their notification context.
 * Stored outside *.class.php so FreePBX does not construct it as a module service.
 */
final class notificationNavigation {
    private $id;
    private $returnUrl;
    private $clearUrl;

    public function __construct($module,$device,array $query) {
        $this->id='';
        $this->returnUrl=null;
        $this->clearUrl=null;
        $id=(string)($query['notification_id']??'');
        if (!preg_match('/^[a-f0-9]{24}$/D',$id)) return;
        $controls=(new notificationQueue($module))->returnControls($device,$id);
        if (!$controls) return;
        $this->id=$id;
        $this->returnUrl=$controls['return'];
        $this->clearUrl=$controls['clear'];
    }
    public function active() { return $this->returnUrl!==null; }
    public function returnUrl() { return $this->returnUrl; }
    public function clearUrl() { return $this->clearUrl; }
    public function link($url) {
        return $this->active() ? notificationQueue::contextLink($url,$this->id) : $url;
    }
    public function fields() { return $this->active()?['notification_id'=>$this->id]:[]; }
    public function softkeys($type='menu') {
        if (!$this->active()) return [];
        $keys=[];
        if ($type==='menu') $keys[]=['Select','SoftKey:Select',1];
        if ($type==='input') $keys[]=['Submit','SoftKey:Submit',1];
        $keys[]=['Back',$this->returnUrl,4];
        return $keys;
    }
    public static function xmlSoftkeys(array $keys) {
        $xml='';
        foreach ($keys as $key) {
            $name=htmlspecialchars((string)$key[0],ENT_XML1|ENT_QUOTES,'UTF-8');
            $url=htmlspecialchars((string)$key[1],ENT_XML1|ENT_QUOTES,'UTF-8');
            $pos=(int)$key[2];
            $xml.='<SoftKeyItem><Name>'.$name.'</Name><URL>'.$url.'</URL><Position>'.$pos.'</Position></SoftKeyItem>';
        }
        return $xml;
    }
}
