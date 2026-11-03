<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Validated, manifest-defined settings for the Services UI and applications.
 * Settings are always namespaced by service ID. Weather and News retain their
 * existing FreePBX config keys to avoid migration or loss of existing choices.
 */
final class serviceAppConfig {
    private $module;
    private $registry;
    public function __construct($module) {
        $this->module=$module;
        require_once __DIR__.'/serviceRegistry.class.php';
        $this->registry=new serviceRegistry($module);
    }
    public function definitions() {
        $out=[];
        foreach ($this->registry->manifests() as $id=>$meta) {
            $settings=$meta['settings']??[];
            if (!is_array($settings)) $settings=[];
            $out[$id]=[];
            foreach($settings as $key=>$field) {
                if(!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$key) ||
                    !is_array($field) || !in_array($field['type']??'',['text','integer','number','select','toggle'],true) ||
                    !isset($field['label']) || !is_string($field['label']) ||
                    strlen($field['label'])>80 || !array_key_exists('default',$field))
                    throw new \RuntimeException('Invalid service setting declaration: '.$id);
                if(($field['type']==='select') &&
                    (!isset($field['options']) || !is_array($field['options']) || !$field['options']))
                    throw new \RuntimeException('Invalid service choices: '.$id.'.'.$key);
                $out[$id][$key]=$field;
            }
        }
        return $out;
    }
    private function legacy($id) {
        if($id==='weather') {
            $saved=$this->module->getConfig('phonecontent_weather');
            return is_array($saved)?$saved:[];
        }
        if($id==='news') {
            $saved=$this->module->getConfig('phonecontent_news_feed');
            return is_string($saved) && $saved!==''?['feed'=>$saved]:[];
        }
        $saved=$this->module->getConfig('phonecontent_service_settings_'.$id);
        return is_array($saved)?$saved:[];
    }
    public function get($id) {
        $defs=$this->definitions();
        if(!isset($defs[$id]))throw new \InvalidArgumentException('Unknown service settings');
        if(!$defs[$id])return [];
        $saved=$this->legacy($id);
        $result=[];
        foreach($defs[$id] as $key=>$field) {
            $value=array_key_exists($key,$saved)?$saved[$key]:$field['default'];
            try {$value=self::validate($value,$field);} catch(\InvalidArgumentException $e) {
                $value=self::validate($field['default'],$field);
            }
            $result[$key]=$value;
        }
        return $result;
    }
    public static function validate($value,array $field) {
        $type=$field['type']??'';
        if($type==='toggle') {
            if($value===true || $value==='1' || $value===1)return true;
            if($value===false || $value==='0' || $value===0 || $value==='')return false;
            throw new \InvalidArgumentException('Invalid toggle value');
        }
        if(!is_string($value) && !is_int($value) && !is_float($value))
            throw new \InvalidArgumentException('Invalid setting value');
        $value=trim((string)$value);
        if($type==='text') {
            $max=(int)($field['maxLength']??80);
            if(strlen($value)>$max || strlen($value)>(int)($field['maxLength']??80) ||
                strlen($value)<(int)($field['minLength']??0) ||
                !preg_match('//u',$value) || preg_match('/[\x00-\x1F\x7F]/',$value))
                throw new \InvalidArgumentException('Invalid text setting');
            return $value;
        }
        if($type==='select') {
            if(!array_key_exists($value,$field['options']))
                throw new \InvalidArgumentException('Invalid choice');
            return $value;
        }
        if($type==='integer' && !preg_match('/^-?[0-9]+$/D',$value))
            throw new \InvalidArgumentException('Invalid integer');
        if($type==='number' && !preg_match('/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/D',$value))
            throw new \InvalidArgumentException('Invalid number');
        if($type==='integer' || $type==='number') {
            $number=$type==='integer'?(int)$value:(float)$value;
            if((string)$number==='INF' || !is_finite((float)$number) ||
               (isset($field['min']) && $number<$field['min']) ||
               (isset($field['max']) && $number>$field['max']))
                throw new \InvalidArgumentException('Number outside allowed range');
            return $number;
        }
        throw new \InvalidArgumentException('Unknown setting type');
    }
    /** Validate *all* submitted fields before changing any stored setting. */
    public function prepare($posted) {
        if(!is_array($posted))throw new \InvalidArgumentException('Invalid app settings');
        $defs=$this->definitions();
        foreach($posted as $id=>$fields) {
            if(!array_key_exists($id,$defs) || !is_array($fields))
                throw new \InvalidArgumentException('Unknown service settings');
            foreach($fields as $key=>$unused)
                if(!array_key_exists($key,$defs[$id]))
                    throw new \InvalidArgumentException('Unknown setting: '.$id.'.'.$key);
        }
        $changes=[];
        foreach($defs as $id=>$fields) {
            if(!$fields)continue;
            $existing=$this->get($id);
            $incoming=$posted[$id]??[];
            foreach($fields as $key=>$field) {
                // A non-submitted boolean checkbox means false; other inputs
                // are always submitted by the generated settings form.
                if(!array_key_exists($key,$incoming) && $field['type']!=='toggle')
                    throw new \InvalidArgumentException('Missing setting: '.$id.'.'.$key);
                try {$existing[$key]=self::validate($incoming[$key]??false,$field);}
                catch(\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException($id.' / '.$field['label'].': '.$e->getMessage());
                }
            }
            $changes[$id]=$existing;
        }
        return $changes;
    }
    /** Keep legacy values in the same keys; other apps have independent keys. */
    public function apply(array $changes) {
        $writes=[];
        foreach($changes as $id=>$values) {
            if($id==='weather')$writes['phonecontent_weather']=$values;
            elseif($id==='news')$writes['phonecontent_news_feed']=$values['feed'];
            else $writes['phonecontent_service_settings_'.$id]=$values;
        }
        $before=[];
        foreach($writes as $key=>$value)$before[$key]=$this->module->getConfig($key);
        try {
            foreach($writes as $key=>$value)$this->module->setConfig($key,$value);
        }catch(\Throwable $e) {
            foreach($before as $key=>$value)$this->module->setConfig($key,$value);
            throw $e;
        }
        return $before;
    }
    public function restore(array $before) {
        foreach($before as $key=>$value)$this->module->setConfig($key,$value);
    }
}
