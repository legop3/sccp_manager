<?php
/** Existing messages behavior, now owned by the manifest-based service. */
return static function(array $ctx,array $q) {
    $legacy=$ctx['legacy'];
    $device=$ctx['device'];
    $line=$ctx['user'];
    $route=$ctx['route'];
    $app='messages';
    $op=(string)($q['op']??'');
    ob_start();
    try {
        $render=static function()use($legacy,$device,$line,$route,$app,$op,$q) {
        if($app==='messages'){
            if($op==='compose'){
                $entries=[];
                foreach($legacy->recipients($device) as $target=>$info)
                    $entries[]=[$target===$device?'My Phone ('.$info['line'].')':'Ext '.$info['line'],$route(['app'=>'messages','op'=>'write','target'=>$target])];
                if(!$entries){$legacy->xml('Messages','No other SCCP phones connected.');return;}
                $legacy->menu('Send to', $entries);return;
            }
            if($op==='write'){
                $target=(string)($q['target']??'');
                if(!isset($legacy->recipients($device)[$target])){$legacy->xml('Messages','Recipient not connected.');return;}
                $legacy->form('New Message',$route(['app'=>'messages','op'=>'send','target'=>$target]),
                    'Message (max 160)', 'text','A');return;
            }
            if($op==='send'){
                $target=(string)($q['target']??'');$message=trim((string)($q['text']??''));
                $others=$legacy->recipients($device);
                if(!isset($others[$target]) || $message==='' || strlen($message)>160 ||
                    preg_match('/[\x00-\x1F\x7F]/',$message)){$legacy->xml('Messages','Invalid recipient or message.');return;}
                $id=bin2hex(random_bytes(8));$now=time();
                $recipientLine=$others[$target]['line'];
                $legacy->transact(function(&$s)use($id,$now,$device,$line,$target,$message,$recipientLine){
                    $s['messages'][]=['id'=>$id,'time'=>$now,'from'=>$device,'line'=>$line,'to'=>$target,'toLine'=>$recipientLine,'text'=>$message];
                    $s['messages']=array_slice($s['messages'],-500);
                });
                $legacy->push($target,$legacy->url($target).'&app=messages&op=read&id='.$id);
                $legacy->xml('Messages','Message sent to ext '.$others[$target]['line']);return;
            }
            if($op==='read'){
                $id=(string)($q['id']??'');
                $record=$legacy->transact(function(&$s)use($device,$id){
                    foreach(($s['messages']??[]) as $m)if($m['id']===$id && ($m['to']===$device || $m['from']===$device))return $m;
                    return null;
                });
                $legacy->xml('Message',$record?'From '.$record['line']."\n".$record['text']:'Message unavailable.');return;
            }
            if($op==='inbox' || $op==='sent') {
                $messages=$legacy->transact(function(&$s)use($device,$op){
                    $key=$op==='sent'?'from':'to';
                    return array_reverse(array_values(array_filter($s['messages']??[],
                        function($m)use($device,$key){return ($m[$key]??'')===$device;})));
                });
                if(!$messages){$legacy->xml('Messages','No messages here.');return;}
                $rows=[];
                foreach(array_slice($messages,0,20) as $m) {
                    $label=$op==='sent'?'To ext '.($m['toLine']??'?'):'From ext '.$m['line'];
                    $rows[]=[$label.': '.substr($m['text'],0,20),
                        $route(['app'=>'messages','op'=>'read','id'=>$m['id']])];
                }
                $legacy->menu($op==='sent'?'Sent Messages':'Inbox',$rows);return;
            }
            $legacy->menu('Messages',[
                ['Compose',$route(['app'=>'messages','op'=>'compose'])],
                ['Inbox',$route(['app'=>'messages','op'=>'inbox'])],
                ['Sent',$route(['app'=>'messages','op'=>'sent'])]
            ]);return;
        }
        };
        $render();
        return ob_get_clean();
    } catch(\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
};
