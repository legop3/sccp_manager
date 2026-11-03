<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Discovers bundled, self-contained Cisco XML service applications.
 * Only the host owns handset authorization, URL generation and storage.
 * Each app exports a callable from serviceApps/<id>/Service.php.
 */
final class serviceRegistry {
    private $module;
    private $root;
    public function __construct($module=null) {
        $this->module=$module;
        $this->root=dirname(__DIR__).'/serviceApps';
    }
    public function manifests() {
        $found=[];
        foreach (glob($this->root.'/*/manifest.json') ?: [] as $path) {
            if (is_link($path) || is_link(dirname($path))) continue;
            $id=basename(dirname($path));
            if (!preg_match('/^[a-z][a-z0-9-]{0,39}$/D',$id)) continue;
            $raw=@file_get_contents($path);
            $meta=json_decode((string)$raw,true);
            if (!is_array($meta) || ($meta['id']??null)!==$id ||
                !isset($meta['name']) || !is_string($meta['name']) ||
                strlen($meta['name'])>48 || !is_file(dirname($path).'/Service.php')) continue;
            $found[$id]=$meta;
        }
        // Keep the existing built-in service order, then other installed apps.
        uasort($found,static function($a,$b) {
            $left=(int)($a['order']??100);
            $right=(int)($b['order']??100);
            return $left<=>$right ?: strcmp((string)$a['id'],(string)$b['id']);
        });
        return $found;
    }
    public static function text($title,$body) {
        $e=static function($s){return htmlspecialchars((string)$s,ENT_XML1|ENT_QUOTES,'UTF-8');};
        return '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneText><Title>'.
            $e(substr($title,0,32)).'</Title><Text>'.$e($body).'</Text></CiscoIPPhoneText>';
    }
    public static function menu($title,$entries) {
        $e=static function($s){return htmlspecialchars((string)$s,ENT_XML1|ENT_QUOTES,'UTF-8');};
        $xml='<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneMenu><Title>'.
            $e(substr($title,0,32)).'</Title><Prompt>Select an item</Prompt>';
        foreach(array_slice($entries,0,30) as $item)
            $xml.='<MenuItem><Name>'.$e(substr((string)$item[0],0,64)).
                '</Name><URL>'.$e($item[1]).'</URL></MenuItem>';
        return $xml.'</CiscoIPPhoneMenu>';
    }
    public function handle($id,array $query,$device,array $identity,callable $route,$navigation=null) {
        $meta=$this->manifests();
        if (!isset($meta[$id])) throw new \InvalidArgumentException('Unknown service');
        $file=$this->root.'/'.$id.'/Service.php';
        $handler=require $file;
        if (!is_callable($handler)) throw new \RuntimeException('Invalid service implementation');
        foreach(['serviceAppStorage','serviceAppNotifications','serviceAppAudio',
                 'serviceAppMedia','serviceAppInput'] as $class)
            require_once __DIR__.'/'.$class.'.php';
        $ctx=[
            'device'=>$device,
            'user'=>(string)($identity['extension']??$device),
            'route'=>function(array $params=[])use($route,$id){
                return $route(array_merge(['app'=>$id],$params));
            },
            'text'=>[self::class,'text'],
            'menu'=>[self::class,'menu'],
            'storage'=>new serviceAppStorage($id),
            'notifications'=>new serviceAppNotifications($this->module,$id),
            'audio'=>new serviceAppAudio($device,$identity),
            'media'=>new serviceAppMedia($device),
            'input'=>new serviceAppInput()
        ];
        if(!empty($meta[$id]['builtin'])) {
            require_once __DIR__.'/serviceLegacySupport.php';
            $ctx['legacy']=new serviceLegacySupport($this->module,$navigation);
        }
        $xml=$handler($ctx,$query);
        if (!is_string($xml) || strlen($xml)>65536)
            throw new \RuntimeException('Invalid service response');
        $doc=new \DOMDocument();
        if (!@$doc->loadXML($xml,LIBXML_NONET) ||
            !in_array($doc->documentElement->tagName,['CiscoIPPhoneText','CiscoIPPhoneMenu','CiscoIPPhoneExecute','CiscoIPPhoneInput'],true))
            throw new \RuntimeException('Invalid Cisco XML service response');
        echo $xml;
    }
}
