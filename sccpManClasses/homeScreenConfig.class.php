<?php
namespace FreePBX\modules\Sccp_manager;

/** Native SCCP phone softkey layouts, stored in the FreePBX module KV store. */
final class homeScreenConfig {
    private $parent;
    const SET_NAME = 'sccpmanager-home';
    public function __construct($parent) { $this->parent = $parent; }
    public static function options() {
        return array('redial'=>'Redial','newcall'=>'New Call','cfwdall'=>'Forward All',
            'cfwdbusy'=>'Forward Busy','cfwdnoanswer'=>'Forward No Answer',
            'pickup'=>'Pickup','gpickup'=>'Group Pickup','dnd'=>'Do Not Disturb',
            'private'=>'Privacy','hold'=>'Hold','endcall'=>'End Call',
            'transfer'=>'Transfer','park'=>'Park','select'=>'Select',
            'idivert'=>'Divert','resume'=>'Resume','answer'=>'Answer',
            'transvm'=>'Transfer to Voicemail','conf'=>'Conference',
            'conflist'=>'Conference List','join'=>'Join','dirtrfr'=>'Direct Transfer',
            'back'=>'Backspace','dial'=>'Dial','empty'=>'Blank',
            'meetme'=>'Meet Me','barge'=>'Barge','cbarge'=>'Conference Barge',
            'monitor'=>'Monitor','vidmode'=>'Video','intrcpt'=>'Intercept');
    }
    public static function defaults() {
        return array(
            'onhook'=>array('newcall','dnd','redial'),
            'offhook'=>array('redial','endcall','pickup'),
            'offhookfeat'=>array('redial','endcall'),
            'digitsfoll'=>array('back','dial','endcall'),
            'ringout'=>array('empty','endcall','transfer'),
            'connected'=>array('hold','endcall','transfer','park'),
            'conntrans'=>array('endcall','transfer','hold'),
            'connconf'=>array('hold','endcall','conf','conflist','join'),
            'onhold'=>array('resume','newcall','endcall','transfer'),
            'ringin'=>array('answer','idivert','endcall'),
            'onhint'=>array('newcall','pickup','barge'),
            'onstealable'=>array('newcall','pickup','dnd'),
            'holdconf'=>array('resume','newcall','endcall','join')
        );
    }
    public function get() {
        // install.php also invokes config generation on a temporary anonymous
        // helper without the FreePBX KV-store methods. Keep install safe.
        if (!is_object($this->parent) || !method_exists($this->parent, 'getConfig'))
            return array('enabled'=>false, 'states'=>self::defaults());
        $stored = $this->parent->getConfig('phonecontent_home_softkeys');
        $defaults = self::defaults();
        if (!is_array($stored)) return array('enabled'=>false,'states'=>$defaults);
        $states=$defaults;
        foreach ($defaults as $state=>$buttons) {
            if (isset($stored['states'][$state]) && is_array($stored['states'][$state]))
                $states[$state]=$stored['states'][$state];
        }
        return array('enabled'=>!empty($stored['enabled']),'states'=>$states);
    }
    public function save($post) {
        if (!is_array($post)) throw new \InvalidArgumentException('Invalid softkey configuration.');
        $allowed=array_keys(self::options());
        $defaults=self::defaults();
        $states=array();
        foreach ($defaults as $state=>$default) {
            $raw=$post['states'][$state] ?? implode(',', $default);
            if (!is_string($raw)) throw new \InvalidArgumentException('Invalid layout for '.$state);
            $keys=array_filter(array_map('trim',explode(',',strtolower($raw))), 'strlen');
            if (count($keys)>15 || count($keys)!==count(array_unique($keys))) throw new \InvalidArgumentException('Maximum 15 distinct softkeys per state: '.$state);
            foreach ($keys as $key) if (!in_array($key,$allowed,true)) throw new \InvalidArgumentException('Unknown softkey: '.$key);
            if (!$keys) throw new \InvalidArgumentException('A state cannot have zero softkeys: '.$state);
            $states[$state]=array_values($keys);
        }
        $this->parent->setConfig('phonecontent_home_softkeys',
            array('enabled'=>!empty($post['enabled']),'states'=>$states));
    }
    /** Build a managed chan_sccp softkeyset; no synthetic or renamed actions. */
    public function inject(array &$config) {
        $setting=$this->get();
        if (!$setting['enabled']) return;
        $section=array('type'=>'softkeyset');
        foreach ($setting['states'] as $state=>$keys) $section[$state]=implode(',',$keys);
        $config[self::SET_NAME]=$section;
    }
}
