<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Shared per-handset push queue. Stores references to already generated XML or
 * service URLs; notification producers do not need to understand queue state.
 */
final class notificationQueue {
    private $module;
    private const MAX_ITEMS = 30;
    private const MAX_AGE = 86400;
    public function __construct($module) { $this->module=$module; }
    private function path() { return '/var/spool/asterisk/sccp-manager-notification-queue.json'; }
    private function transaction($callback) {
        $f=@fopen($this->path(),'c+');
        if (!$f) throw new \RuntimeException('Notification queue storage unavailable');
        // Cron may run as root while Apache serves dismissals as asterisk.
        // Keep the persistent queue writable by both contexts.
        @chown($this->path(),'asterisk');
        @chgrp($this->path(),'asterisk');
        @chmod($this->path(),0660);
        try {
            if (!flock($f,LOCK_EX)) throw new \RuntimeException('Notification queue lock failed');
            rewind($f);
            $data=json_decode(stream_get_contents($f),true);
            if (!is_array($data)) $data=[];
            $result=$callback($data);
            rewind($f); ftruncate($f,0);
            if (fwrite($f,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE))===false)
                throw new \RuntimeException('Notification queue write failed');
            fflush($f); flock($f,LOCK_UN);
            return $result;
        } finally { fclose($f); }
    }
    private static function prune($items) {
        $now=time();
        $items=array_values(array_filter((array)$items,static function($v)use($now) {
            return is_array($v) && isset($v['at'],$v['id'],$v['url']) &&
                (int)$v['at']>$now-self::MAX_AGE &&
                preg_match('/^[a-f0-9]{24}$/D',(string)$v['id']);
        }));
        return array_slice($items,-self::MAX_ITEMS);
    }
    public function url($device,$id,$op='show') {
        $base=$this->module->phonecontent->baseUrl($device);
        if (!$base || !phonevoicemail::validDevice($device)) throw new \RuntimeException('Invalid notification destination');
        $key=$this->module->phonevoicemail->tokenForDevice($device);
        return $base.'/notification-queue.php?'.http_build_query([
            'phone'=>$device,'key'=>$key,'id'=>$id,'op'=>$op
        ],'','&',PHP_QUERY_RFC3986);
    }
    /** Record the notification before pushing. If push fails, retain it. */
    public function enqueue($device,$url) {
        if (!phonevoicemail::validDevice($device) || !filter_var($url,FILTER_VALIDATE_URL))
            throw new \InvalidArgumentException('Invalid queued notification');
        if (!is_file($this->module->phonecontent->publicPath().'/notification-queue.php'))
            $this->module->phonecontent->publishDirectory();
        $id=bin2hex(random_bytes(12));
        $snapshot=null;
        $parts=parse_url($url);
        if (isset($parts['path']) && basename($parts['path'])==='notification-display.php') {
            parse_str((string)($parts['query']??''),$params);
            $fileId=$params['id']??'';
            if (is_string($fileId) && preg_match('/^[a-f0-9]{24}$/D',$fileId)) {
                $file=$this->module->phonecontent->publicPath().'/notification-'.$fileId.'.xml';
                if (is_file($file) && !is_link($file)) $snapshot=@file_get_contents($file);
            }
        }
        $this->transaction(static function(&$state)use($device,$id,$url,$snapshot) {
            $items=self::prune($state[$device]??[]);
            $items[]=['id'=>$id,'url'=>$url,'xml'=>$snapshot,'at'=>time()];
            $state[$device]=self::prune($items);
        });
        return $this->url($device,$id);
    }
    public function current($device,$dismissId=null) {
        if (!phonevoicemail::validDevice($device)) throw new \InvalidArgumentException('Invalid phone');
        return $this->transaction(static function(&$state)use($device,$dismissId) {
            $items=self::prune($state[$device]??[]);
            if ($dismissId!==null) $items=array_values(array_filter($items,
                static function($item)use($dismissId) { return $item['id']!==$dismissId; }));
            $state[$device]=$items;
            return ['entry'=>$items?end($items):null,'count'=>count($items)];
        });
    }
    public function handle(array $q) {
        header('Content-Type: text/xml; charset=UTF-8');
        header('Cache-Control: no-store');
        header('Expires: Thu, 01 Jan 2004 00:00:00 GMT');
        $device=(string)($q['phone']??'');
        $key=(string)($q['key']??'');
        $id=(string)($q['id']??'');
        if (!phonevoicemail::validDevice($device) || !preg_match('/^[a-f0-9]{64}$/D',$key) ||
            !hash_equals($this->module->phonevoicemail->tokenForDevice($device),$key) ||
            !preg_match('/^[a-f0-9]{24}$/D',$id)) {
            http_response_code(403); return;
        }
        $state=$this->current($device,($q['op']??'')==='dismiss'?$id:null);
        if (!$state['entry']) {
            echo '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneText><Title>Notifications</Title><Text>No more notifications</Text><Prompt>All cleared</Prompt><SoftKeyItem><Name>Exit</Name><URL>SoftKey:Exit</URL><Position>1</Position></SoftKeyItem></CiscoIPPhoneText>';
            return;
        }
        $entry=$state['entry'];
        $url=$entry['url'];
        $dismiss=$this->url($device,$entry['id'],'dismiss');
        $count=$state['count'];
        // Generated XML is local, so preserve the complete original graphic and actions.
        $parts=parse_url($url);
        $fileId=null;
        if (isset($parts['path']) && basename($parts['path'])==='notification-display.php') {
            parse_str((string)($parts['query']??''),$params);
            $fileId=$params['id']??null;
        }
        if (is_string($fileId) && preg_match('/^[a-f0-9]{24}$/D',$fileId)) {
            $file=$this->module->phonecontent->publicPath().'/notification-'.$fileId.'.xml';
            $raw=is_string($entry['xml']??null) ? $entry['xml'] :
                (is_file($file) && !is_link($file) ? @file_get_contents($file) : false);
            if (is_string($raw) && $raw!=='') {
                $doc=new \DOMDocument('1.0','UTF-8');
                $previous=libxml_use_internal_errors(true);
                $loaded=$doc->loadXML($raw,LIBXML_NONET);
                libxml_clear_errors();libxml_use_internal_errors($previous);
                if ($loaded && $doc->documentElement) {
                    $root=$doc->documentElement;
                    foreach (iterator_to_array($root->getElementsByTagName('Prompt')) as $p)
                        if ($p->parentNode===$root) $root->removeChild($p);
                    $prompt=$doc->createElement('Prompt');
                    $prompt->appendChild($doc->createTextNode('Notification 1 of '.$count));
                    $root->appendChild($prompt);
                    $used=[];$dismissKey=null;
                    foreach (iterator_to_array($root->getElementsByTagName('SoftKeyItem')) as $item) {
                        if ($item->parentNode!==$root) continue;
                        $name=$item->getElementsByTagName('Name')->item(0);
                        $pos=$item->getElementsByTagName('Position')->item(0);
                        if ($pos) $used[(int)$pos->textContent]=true;
                        if ($name && in_array(strtolower(trim($name->textContent)),['dismiss','exit'],true))
                            $dismissKey=$item;
                    }
                    if (!$dismissKey) {
                        for($i=1;$i<=4;$i++) if (!isset($used[$i])) { $position=$i; break; }
                        if (isset($position)) {
                            $dismissKey=$doc->createElement('SoftKeyItem');
                            foreach (['Name'=>'Dismiss','URL'=>$dismiss,'Position'=>(string)$position] as $k=>$v)
                                $dismissKey->appendChild($doc->createElement($k))->appendChild($doc->createTextNode($v));
                            $root->appendChild($dismissKey);
                        }
                    }
                    if ($dismissKey) {
                        $action=$dismissKey->getElementsByTagName('URL')->item(0);
                        if ($action) $action->nodeValue=$dismiss;
                    }
                    echo $doc->saveXML(); return;
                }
            }
        }
        // Service pushes already render full interactive pages. Carry a queue
        // token into the existing service renderer for a native Dismiss softkey.
        if (isset($parts['path']) && basename($parts['path'])==='service.php') {
            $separator=strpos($url,'?')===false?'?':'&';
            $url.=$separator.http_build_query(['queue_id'=>$entry['id']],'','&',PHP_QUERY_RFC3986);
        }
        $safe=htmlspecialchars($url,ENT_XML1|ENT_QUOTES,'UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneExecute><ExecuteItem URL="'.$safe.'"/></CiscoIPPhoneExecute>';
    }
    /** Service pages can add the same queue controls without changing their content. */
    public function serviceControls($device,$id) {
        if (!preg_match('/^[a-f0-9]{24}$/D',$id)) return null;
        $state=$this->current($device);
        if (!$state['entry'] || $state['entry']['id']!==$id) return null;
        return ['prompt'=>'Notification 1 of '.$state['count'],
                'dismiss'=>$this->url($device,$id,'dismiss')];
    }
}
