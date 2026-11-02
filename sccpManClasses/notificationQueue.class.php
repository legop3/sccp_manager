<?php
namespace FreePBX\modules\Sccp_manager;

require_once __DIR__.'/notificationMessage.php';

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
    /**
     * One generic notification contract for every producer. Renderers own their
     * XML and actions; this manager owns the Clear softkey and navigation state.
     */
    public function enqueue($device,$url,array $providedActions=[],$contentXml=null) {
        if (!phonevoicemail::validDevice($device) ||
            ($url!=='' && !filter_var($url,FILTER_VALIDATE_URL)) ||
            ($url==='' && $contentXml===null))
            throw new \InvalidArgumentException('Invalid queued notification');
        $endpoint=$this->module->phonecontent->publicPath().'/notification-queue.php';
        $source=__DIR__.'/../services/notification-queue-entry.php';
        if (!is_file($endpoint) || (is_file($source) &&
            hash_file('sha256',$source)!==hash_file('sha256',$endpoint)))
            $this->module->phonecontent->publishDirectory();
        $id=bin2hex(random_bytes(12));
        if ($contentXml!==null && (!is_string($contentXml) || strlen($contentXml)>262144))
            throw new \InvalidArgumentException('Invalid notification XML payload');
        $snapshot=null;
        $actions=[];
        foreach ($providedActions as $label=>$target) {
            if (count($actions)===3) break;
            if (is_string($label) && trim($label)!=='' && is_string($target) && trim($target)!=='')
                $actions[]=['name'=>substr(trim($label),0,12),'url'=>$target];
        }
        $parts=parse_url($url);
        if ($contentXml!==null) {
            $snapshot=$contentXml;
        } elseif (isset($parts['path']) && basename($parts['path'])==='notification-display.php') {
            parse_str((string)($parts['query']??''),$params);
            $fileId=$params['id']??'';
            if (is_string($fileId) && preg_match('/^[a-f0-9]{24}$/D',$fileId)) {
                $file=$this->module->phonecontent->publicPath().'/notification-'.$fileId.'.xml';
                if (is_file($file) && !is_link($file)) {
                    $raw=@file_get_contents($file);
                    if (is_string($raw) && $raw!=='') {
                        $doc=new \DOMDocument('1.0','UTF-8');
                        if (@$doc->loadXML($raw,LIBXML_NONET) && $doc->documentElement) {
                            $root=$doc->documentElement;
                            foreach (iterator_to_array($root->getElementsByTagName('SoftKeyItem')) as $key) {
                                if ($key->parentNode!==$root) continue;
                                $name=$key->getElementsByTagName('Name')->item(0);
                                $target=$key->getElementsByTagName('URL')->item(0);
                                if (!$providedActions && $name && $target && count($actions)<3 && !in_array(strtolower(trim($name->textContent)),['exit','dismiss','clear'],true)) {
                                    $actions[]=['name'=>trim($name->textContent),'url'=>$target->textContent];
                                }
                                $root->removeChild($key);
                            }
                            $snapshot=$doc->saveXML();
                        }
                    }
                }
            }
        }
        $this->transaction(static function(&$state)use($device,$id,$url,$snapshot,$actions) {
            $items=self::prune($state[$device]??[]);
            $items[]=['id'=>$id,'url'=>$url,'xml'=>$snapshot,'actions'=>array_slice($actions,0,3),'at'=>time()];
            $state[$device]=self::prune($items);
        });
        return $this->url($device,$id);
    }
    /**
     * Single public entry point for a sender. The caller owns content and
     * action definitions; the notification system owns registration, queuing
     * and the actual CGI push.
     *
     * @param string $device SEP identifier
     * @param string $ip SCCP session peer IPv4 address
     * @param array $notification ['url'=>string,'actions'=>[label=>url],'sound'=>?string]
     */
    public function send($device,$ip,notificationMessage $message) {
        $notification=$message->payload();
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))
            throw new \InvalidArgumentException('Invalid notification handset address');
        $url=(string)($notification['url']??'');
        $contentXml=$notification['xml']??null;
        $actions=$notification['actions']??[];
        if (!is_array($actions)) throw new \InvalidArgumentException('Invalid notification actions');
        $sound=(string)($notification['sound']??'');
        if ($sound!=='' && !preg_match('/^[A-Za-z0-9_.-]+\\.raw$/D',$sound))
            throw new \InvalidArgumentException('Invalid notification sound');
        if ($url==='' && $contentXml===null) {
            if ($sound==='') throw new \InvalidArgumentException('Empty notification');
            // Sound-only notifications do not have an XML app to clear.
            $display=null;
        } else {
            $display=$this->enqueue($device,$url,$actions,$contentXml);
        }
        $xml='<CiscoIPPhoneExecute>';
        if ($sound!=='') $xml.='<ExecuteItem URL="Play:'.htmlspecialchars($sound,ENT_XML1|ENT_QUOTES,'UTF-8').'"/>';
        if ($display!==null) $xml.='<ExecuteItem URL="'.htmlspecialchars($display,ENT_XML1|ENT_QUOTES,'UTF-8').'"/>';
        $xml.='</CiscoIPPhoneExecute>';
        $ch=curl_init('http://'.$ip.'/CGI/Execute');
        if (!$ch) throw new \RuntimeException('Cannot initialize phone notification push');
        try {
            curl_setopt_array($ch,[
                CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['XML'=>$xml]),
                CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>'sccpnotify:',
                CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_RETURNTRANSFER=>true
            ]);
            $response=curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        } finally { curl_close($ch); }
        return ['ok'=>$status===200 && is_string($response) &&
                strpos($response,'CiscoIPPhoneError')===false,
                'status'=>$status,'response'=>substr(trim((string)$response),0,160)];
    }
    public function current($device,$dismissId=null) {
        if (!phonevoicemail::validDevice($device)) throw new \InvalidArgumentException('Invalid phone');
        return $this->transaction(static function(&$state)use($device,$dismissId) {
            $items=self::prune($state[$device]??[]);
            if ($dismissId!==null) $items=array_values(array_filter($items,
                static function($item)use($dismissId) { return $item['id']!==$dismissId; }));
            $state[$device]=$items;
            return ['entry'=>$items?end($items):null,'count'=>count($items),'items'=>$items];
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
        if (($q['op']??'')==='return') {
            foreach ($state['items'] as $item) if ($item['id']===$id) {
                $state['entry']=$item;
                break;
            }
        }
        if (!$state['entry']) {
            echo '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneExecute><ExecuteItem URL="Init:Services"/></CiscoIPPhoneExecute>';
            return;
        }
        $entry=$state['entry'];
        $url=$entry['url'];
        $dismiss=$this->url($device,$entry['id'],'dismiss');
        $count=$state['count'];
        // The queue never changes notification content. It adds only its own
        // Clear action plus up to three actions supplied by the renderer.
        $parts=parse_url($url);
        $raw=$entry['xml']??null;
        if (is_string($raw) && $raw!=='') {
            $doc=new \DOMDocument('1.0','UTF-8');
            if (!@$doc->loadXML($raw,LIBXML_NONET) || !$doc->documentElement)
                throw new \RuntimeException('Stored notification XML is invalid');
            $root=$doc->documentElement;
            // Legacy queue entries had softkeys embedded in their snapshot.
            // Strip only that old control metadata before applying the shared
            // four-key contract; do not change the notification body.
            foreach (iterator_to_array($root->getElementsByTagName('SoftKeyItem')) as $oldKey)
                if ($oldKey->parentNode===$root) $root->removeChild($oldKey);
            if ($root->tagName==='CiscoIPPhoneStatus') {
                $value=$root->getElementsByTagName('Text')->item(0);
                $message=$value?$value->textContent:'';
                $doc=new \DOMDocument('1.0','UTF-8');
                $root=$doc->appendChild($doc->createElement('CiscoIPPhoneText'));
                $root->appendChild($doc->createElement('Title','Notification'));
                $root->appendChild($doc->createElement('Text'))->appendChild($doc->createTextNode($message));
            }
            foreach (iterator_to_array($root->getElementsByTagName('Prompt')) as $p)
                if ($p->parentNode===$root) $root->removeChild($p);
            // Cisco XML requires Prompt before softkeys.
            $prompt=$doc->createElement('Prompt');
            $prompt->appendChild($doc->createTextNode('Notification 1 of '.$count));
            $root->appendChild($prompt);
            $actions=array_merge([['name'=>'Clear','url'=>$dismiss]],array_slice($entry['actions']??[],0,3));
            foreach ($actions as $position=>$action) {
                if (!is_array($action) || !isset($action['name'],$action['url'])) continue;
                $item=$root->appendChild($doc->createElement('SoftKeyItem'));
                foreach (['Name'=>(string)$action['name'],
                          'URL'=>self::contextLink((string)$action['url'],$entry['id']),
                          'Position'=>(string)($position+1)] as $tag=>$value) {
                    $item->appendChild($doc->createElement($tag))
                        ->appendChild($doc->createTextNode($value));
                }
            }
            echo $doc->saveXML(); return;
        }
        // A notification can launch ANY module application. Its renderer owns
        // the page and implements the shared notification_id return contract.
        // No sender type or service endpoint is special-cased here.
        $contextual=self::contextLink($url,$entry['id']);
        if ($contextual!==$url) {
            $url=$contextual.'&notification_entry=1';
        }
        $safe=htmlspecialchars($url,ENT_XML1|ENT_QUOTES,'UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneExecute><ExecuteItem URL="'.$safe.'"/></CiscoIPPhoneExecute>';
    }
    /** Shared lifecycle contract: internal app links carry only notification identity.
     * Content renderers stay responsible for their own pages and menus. */
    public static function contextLink($url,$id) {
        if (!preg_match('/^[a-f0-9]{24}$/D',(string)$id)) return $url;
        $path=(string)(parse_url($url,PHP_URL_PATH)??'');
        // Any phone-content application can participate without a queue-side type list.
        if (!preg_match('~/sccp-manager/[^/]+\\.php$~i',$path)) return $url;
        parse_str((string)(parse_url($url,PHP_URL_QUERY)??''),$query);
        if (($query['notification_id']??null)===$id) return $url;
        return $url.(strpos($url,'?')===false?'?':'&').
            http_build_query(['notification_id'=>$id],'','&',PHP_QUERY_RFC3986);
    }
    public function returnControls($device,$id) {
        if (!preg_match('/^[a-f0-9]{24}$/D',(string)$id)) return null;
        $state=$this->current($device);
        foreach (($state['items']??[]) as $item) {
            if ($item['id']===$id)
                return ['return'=>$this->url($device,$id,'return'),
                        'clear'=>$this->url($device,$id,'dismiss'),'id'=>$id];
        }
        return null;
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
