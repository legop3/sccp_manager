<?php
namespace FreePBX\modules\Sccp_manager;

/** Built-in Cisco services. Service actions use the existing per-handset voicemail bearer token. */
class phoneservices {
    private $module;
    public function __construct($module=null) {$this->module=$module;}
    private function esc($s) {return htmlspecialchars((string)$s, ENT_XML1|ENT_QUOTES, 'UTF-8');}
    private function xml($name,$data) {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<CiscoIPPhoneText><Title>'.$this->esc(substr($name,0,32)).'</Title><Text>'.$this->esc($data).'</Text></CiscoIPPhoneText>';
    }
    private function menu($title,$entries) {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<CiscoIPPhoneMenu><Title>'.$this->esc(substr($title,0,32)).'</Title><Prompt>Select an item</Prompt>';
        foreach($entries as $entry) {
            echo '<MenuItem><Name>'.$this->esc(substr($entry[0],0,64)).'</Name><URL>'.$this->esc($entry[1]).'</URL></MenuItem>';
        }
        echo '</CiscoIPPhoneMenu>';
    }
    private function form($title,$url,$label,$param,$mode='A') {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<CiscoIPPhoneInput><Title>'.$this->esc($title).'</Title><Prompt>'.$this->esc($label).'</Prompt><URL>'.$this->esc($url).'</URL>';
        echo '<InputItem><DisplayName>'.$this->esc($label).'</DisplayName><QueryString>'.$this->esc($param).'</QueryString><InputFlags>'.$this->esc($mode).'</InputFlags></InputItem></CiscoIPPhoneInput>';
    }
    private function statePath() {return '/var/spool/asterisk/sccp-manager-services.json';}
    private function transact($callback) {
        $path=$this->statePath();
        $handle=@fopen($path,'c+');
        if(!$handle) throw new \RuntimeException('Services storage cannot be opened.');
        try {
            if(!flock($handle,LOCK_EX)) throw new \RuntimeException('Unable to lock service storage.');
            rewind($handle);
            $state=json_decode(stream_get_contents($handle),true);
            if(!is_array($state))$state=['messages'=>[],'alarms'=>[]];
            $result=$callback($state);
            rewind($handle);ftruncate($handle,0);
            fwrite($handle,json_encode($state,JSON_UNESCAPED_SLASHES));fflush($handle);
            flock($handle,LOCK_UN);
            return $result;
        } finally {fclose($handle);}
    }
    private function requestUrl($query) {
        $base=$this->module->phonecontent->baseUrl();
        return $base.'/service.php?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }
    public function url($device) {
        // Provision with the VPN-facing address for Cisco VPN handsets.
        $base=$this->module->phonecontent->baseUrl($device);
        if($base==='') throw new \RuntimeException('Phone Content address is not configured.');
        return $base.'/service.php?'.http_build_query([
            'phone'=>$device,'key'=>$this->module->phonevoicemail->tokenForDevice($device)
        ],'','&',PHP_QUERY_RFC3986);
    }
    private function auth($query) {
        $device=(string)($query['phone']??'');
        $key=(string)($query['key']??'');
        if(!phonevoicemail::validDevice($device) || !preg_match('/^[a-f0-9]{64}$/D',$key))
            throw new \RuntimeException('Invalid handset credentials.');
        if(!hash_equals($this->module->phonevoicemail->tokenForDevice($device),$key))
            throw new \RuntimeException('Invalid handset credentials.');
        $line=$this->module->phonevoicemail->mailboxForDevice($device);
        return [$device,$line];
    }
    private function recipients($me) {
        $found=[];
        foreach ((new notificationDelivery($this->module))->devices() as $device=>$ip) {
            try {
                $line=$this->module->phonevoicemail->mailboxForDevice($device);
                if($device!==$me)$found[$device]=['line'=>$line,'ip'=>$ip];
            } catch(\Throwable $e){}
        }
        return $found;
    }
    private function push($device,$url) {
        $all=(new notificationDelivery($this->module))->devices();
        if(!isset($all[$device]))return false;
        $ip=$all[$device];
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))return false;
        $xml='<CiscoIPPhoneExecute><ExecuteItem URL="'.$this->esc($url).'"/></CiscoIPPhoneExecute>';
        $ch=curl_init('http://'.$ip.'/CGI/Execute');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['XML'=>$xml]),
            CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>'sccpnotify:',
            CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_RETURNTRANSFER=>true]);
        $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        return $status===200 && is_string($body) && strpos($body,'CiscoIPPhoneError')===false;
    }
    private function fetch($url) {
        $context=stream_context_create(['http'=>['timeout'=>5,'follow_location'=>0,
            'user_agent'=>'SCCP-Manager/17.1.84']]);
        $raw=@file_get_contents($url,false,$context,0,180000);
        return $raw===false?'':$raw;
    }
    public function handle($q) {
        header('Content-Type: text/xml; charset=UTF-8');
        header('Cache-Control: no-store');header('Expires: Thu, 01 Jan 1970 00:00:01 GMT');
        header('X-Content-Type-Options: nosniff');
        [$device,$line]=$this->auth($q);
        $root=['phone'=>$device,'key'=>(string)$q['key']];
        $route=function($fields)use($root){return $this->requestUrl(array_merge($root,$fields));};
        $flags=$this->module->phonemenus->serviceFlags();
        $app=(string)($q['app']??'');$op=(string)($q['op']??'');
        if($app==='' || $app==='home') {
            $catalog=['weather'=>'Weather','messages'=>'Messages','alarms'=>'Alarms & Reminders','news'=>'News & RSS','games'=>'Games'];
            $entries=[];
            foreach($catalog as $id=>$name)if(!empty($flags[$id]))$entries[]=[$name,$route(['app'=>$id])];
            if(!$entries){$this->xml('Services','No services enabled.');return;}
            $this->menu('Services',$entries);return;
        }
        if(empty($flags[$app])){$this->xml('Services','This service is disabled.');return;}
        if($app==='weather') {
            $settings=$this->module->getConfig('phonecontent_weather');
            if(!is_array($settings))$settings=['name'=>'Columbus, OH','lat'=>39.9612,'lon'=>-82.9988];
            $lat=(float)($settings['lat']??39.9612);$lon=(float)($settings['lon']??-82.9988);
            $api='https://api.open-meteo.com/v1/forecast?'.http_build_query([
                'latitude'=>$lat,'longitude'=>$lon,'current'=>'temperature_2m,relative_humidity_2m,wind_speed_10m',
                'daily'=>'temperature_2m_max,temperature_2m_min','temperature_unit'=>'fahrenheit',
                'wind_speed_unit'=>'mph','timezone'=>'auto','forecast_days'=>4]);
            $data=json_decode($this->fetch($api),true);
            if(!is_array($data)||!isset($data['current'])){$this->xml('Weather','Forecast unavailable.');return;}
            $c=$data['current'];$d=$data['daily']??[];
            $rows=[(string)($settings['name']??'Weather'),'Current: '.($c['temperature_2m']??'?').' F',
                'Humidity: '.($c['relative_humidity_2m']??'?').'%',
                'Wind: '.($c['wind_speed_10m']??'?').' mph','Forecast:'];
            foreach(($d['time']??[]) as $i=>$date)$rows[]=$date.' '.($d['temperature_2m_min'][$i]??'?').' - '.($d['temperature_2m_max'][$i]??'?').' F';
            $this->xml('Weather',implode("\n",$rows));return;
        }
        if($app==='news') {
            $feed=(string)$this->module->getConfig('phonecontent_news_feed');
            if($feed==='')$feed='https://feeds.bbci.co.uk/news/rss.xml';
            $raw=$this->fetch($feed);
            libxml_use_internal_errors(true);
            $rss=$raw!==''?simplexml_load_string($raw,'SimpleXMLElement',LIBXML_NONET):false;
            if(!$rss || !isset($rss->channel->item)){$this->xml('News','Feed unavailable.');return;}
            $items=$rss->channel->item;
            if($op==='story') {
                $n=filter_var($q['id']??null,FILTER_VALIDATE_INT);
                if($n===false||$n===null||$n<0||$n>=min(15,count($items))){$this->xml('News','Story unavailable.');return;}
                $this->xml('News',substr((string)$items[$n]->title."\n\n".strip_tags((string)$items[$n]->description),0,950));return;
            }
            $entries=[];
            foreach($items as $i=>$story){if($i>=15)break;$entries[]=[(string)$story->title,$route(['app'=>'news','op'=>'story','id'=>$i])];}
            $this->menu('News',$entries);return;
        }
        if($app==='games') {
            if($op==='coin'){$this->xml('Coin Flip',random_int(0,1)?'Heads':'Tails');return;}
            if($op==='dice'){$this->xml('Dice Roll','You rolled '.random_int(1,6));return;}
            if($op==='eight'){$answers=['Yes','No','Probably','Ask again later','Definitely','Unlikely','Outlook good','Cannot predict now'];$this->xml('Magic 8 Ball',$answers[random_int(0,count($answers)-1)]);return;}
            $trivia=[
                ['How many days in a leap year?',['365','366','364','367'],1],
                ['Which planet is known as the Red Planet?',['Venus','Mars','Saturn','Mercury'],1],
                ['How many sides does a hexagon have?',['5','6','7','8'],1],
                ['What color do blue and yellow make?',['Purple','Orange','Green','Red'],2],
                ['Which ocean is the largest?',['Atlantic','Indian','Arctic','Pacific'],3],
                ['What is 12 times 12?',['124','144','142','154'],1],
                ['Which gas do plants absorb?',['Oxygen','Helium','Carbon Dioxide','Nitrogen'],2],
                ['How many minutes are in an hour?',['30','90','100','60'],3]
            ];
            if($op==='trivia'){
                $n=isset($q['id']) ? filter_var($q['id'],FILTER_VALIDATE_INT) : random_int(0,count($trivia)-1);
                if($n===false || $n<0 || $n>=count($trivia)){$this->xml('Trivia','Question unavailable.');return;}
                if(isset($q['answer'])){
                    $a=filter_var($q['answer'],FILTER_VALIDATE_INT);
                    $this->xml('Trivia',$a===$trivia[$n][2]?'Correct!':'Not quite. Answer: '.$trivia[$n][1][$trivia[$n][2]]);
                    return;
                }
                $entries=[];
                foreach($trivia[$n][1] as $a=>$choice)$entries[]=[$choice,$route(['app'=>'games','op'=>'trivia','id'=>$n,'answer'=>$a])];
                $this->menu('Trivia: '.$trivia[$n][0],$entries);return;
            }
            if($op==='ttt-start') {
                $entries=[];
                foreach($this->recipients($device) as $other=>$info)
                    $entries[]=['Extension '.$info['line'],$route(['app'=>'games','op'=>'ttt-new','other'=>$other])];
                if(!$entries){$this->xml('Tic-Tac-Toe','No other SCCP phones connected.');return;}
                $this->menu('Challenge a phone',$entries);return;
            }
            if($op==='ttt-new') {
                $other=(string)($q['other']??'');
                if(!isset($this->recipients($device)[$other])){$this->xml('Tic-Tac-Toe','Other phone unavailable.');return;}
                $pair=[$device,$other];sort($pair,SORT_STRING);
                $id=implode('-',$pair);
                $this->transact(function(&$s)use($id,$device,$other){
                    $s['games'][$id]=['players'=>[$device,$other],'board'=>array_fill(0,9,'-'),
                        'turn'=>$device,'winner'=>null,'created'=>time()];
                });
                $this->push($other,$this->url($other).'&app=games&op=ttt-board&other='.$device);
                $this->menu('Tic-Tac-Toe',[['Open Game',$route(['app'=>'games','op'=>'ttt-board','other'=>$other])]]);return;
            }
            if($op==='ttt-board' || $op==='ttt-move') {
                $other=(string)($q['other']??'');
                if(!phonevoicemail::validDevice($other)){$this->xml('Tic-Tac-Toe','Invalid opponent.');return;}
                $pair=[$device,$other];sort($pair,SORT_STRING);$id=implode('-',$pair);
                $move=($op==='ttt-move')?filter_var($q['cell']??null,FILTER_VALIDATE_INT):null;
                $game=$this->transact(function(&$s)use($id,$device,$move){
                    $game=$s['games'][$id]??null;
                    if(!is_array($game) || !in_array($device,$game['players']??[],true))return null;
                    if($move!==null && $move!==false && $move>=0 && $move<9 &&
                        $game['winner']===null && $game['turn']===$device && $game['board'][$move]==='-'){
                        $mark=$device===$game['players'][0]?'X':'O';
                        $game['board'][$move]=$mark;
                        $wins=[[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
                        foreach($wins as $row)if($game['board'][$row[0]]===$mark &&
                            $game['board'][$row[1]]===$mark && $game['board'][$row[2]]===$mark)$game['winner']=$device;
                        if($game['winner']===null && !in_array('-', $game['board'],true))$game['winner']='draw';
                        $game['turn']=$device===$game['players'][0]?$game['players'][1]:$game['players'][0];
                        $s['games'][$id]=$game;
                    }
                    return $game;
                });
                if(!$game){$this->xml('Tic-Tac-Toe','No game with that phone.');return;}
                $board=$game['board'];
                $state=$game['winner']==='draw'?'Draw!':($game['winner']!==null
                    ? ($game['winner']===$device?'You win!':'You lose!') :
                    ($game['turn']===$device?'Your turn':'Their turn'));
                $entries=[];
                for($i=0;$i<9;$i++)$entries[]=[
                    'Cell '.($i+1).' ['.$board[$i].']',
                    $route(['app'=>'games','op'=>'ttt-move','other'=>$other,'cell'=>$i])];
                $entries[]=['Refresh',$route(['app'=>'games','op'=>'ttt-board','other'=>$other])];
                $this->menu('Tic-Tac-Toe: '.$state,$entries);return;
            }
            $rows=[['Trivia',$route(['app'=>'games','op'=>'trivia'])],
                ['Tic-Tac-Toe',$route(['app'=>'games','op'=>'ttt-start'])],
                ['Flip a Coin',$route(['app'=>'games','op'=>'coin'])],
                ['Roll a Die',$route(['app'=>'games','op'=>'dice'])],
                ['Magic 8 Ball',$route(['app'=>'games','op'=>'eight'])]];
            $this->menu('Games',$rows);return;
        }
        if($app==='messages'){
            if($op==='compose'){
                $entries=[];
                foreach($this->recipients($device) as $target=>$info)
                    $entries[]=['Ext '.$info['line'],$route(['app'=>'messages','op'=>'write','target'=>$target])];
                if(!$entries){$this->xml('Messages','No other SCCP phones connected.');return;}
                $this->menu('Send to', $entries);return;
            }
            if($op==='write'){
                $target=(string)($q['target']??'');
                if(!isset($this->recipients($device)[$target])){$this->xml('Messages','Recipient not connected.');return;}
                $this->form('New Message',$route(['app'=>'messages','op'=>'send','target'=>$target]),
                    'Message (max 160)', 'text','A');return;
            }
            if($op==='send'){
                $target=(string)($q['target']??'');$message=trim((string)($q['text']??''));
                $others=$this->recipients($device);
                if(!isset($others[$target]) || $message==='' || strlen($message)>160 ||
                    preg_match('/[\x00-\x1F\x7F]/',$message)){$this->xml('Messages','Invalid recipient or message.');return;}
                $id=bin2hex(random_bytes(8));$now=time();
                $recipientLine=$others[$target]['line'];
                $this->transact(function(&$s)use($id,$now,$device,$line,$target,$message,$recipientLine){
                    $s['messages'][]=['id'=>$id,'time'=>$now,'from'=>$device,'line'=>$line,'to'=>$target,'toLine'=>$recipientLine,'text'=>$message];
                    $s['messages']=array_slice($s['messages'],-500);
                });
                $this->push($target,$this->url($target).'&app=messages&op=read&id='.$id);
                $this->xml('Messages','Message sent to ext '.$others[$target]['line']);return;
            }
            if($op==='read'){
                $id=(string)($q['id']??'');
                $record=$this->transact(function(&$s)use($device,$id){
                    foreach(($s['messages']??[]) as $m)if($m['id']===$id && ($m['to']===$device || $m['from']===$device))return $m;
                    return null;
                });
                $this->xml('Message',$record?'From '.$record['line']."\n".$record['text']:'Message unavailable.');return;
            }
            if($op==='inbox' || $op==='sent') {
                $messages=$this->transact(function(&$s)use($device,$op){
                    $key=$op==='sent'?'from':'to';
                    return array_reverse(array_values(array_filter($s['messages']??[],
                        function($m)use($device,$key){return ($m[$key]??'')===$device;})));
                });
                if(!$messages){$this->xml('Messages','No messages here.');return;}
                $rows=[];
                foreach(array_slice($messages,0,20) as $m) {
                    $label=$op==='sent'?'To ext '.($m['toLine']??'?'):'From ext '.$m['line'];
                    $rows[]=[$label.': '.substr($m['text'],0,20),
                        $route(['app'=>'messages','op'=>'read','id'=>$m['id']])];
                }
                $this->menu($op==='sent'?'Sent Messages':'Inbox',$rows);return;
            }
            $this->menu('Messages',[
                ['Compose',$route(['app'=>'messages','op'=>'compose'])],
                ['Inbox',$route(['app'=>'messages','op'=>'inbox'])],
                ['Sent',$route(['app'=>'messages','op'=>'sent'])]
            ]);return;
        }
        if($app==='alarms'){
            if($op==='set'){
                $this->form('New Alarm',$route(['app'=>'alarms','op'=>'save']),'Time HHMM (24h)','time','N');return;
            }
            if($op==='save'){
                $time=(string)($q['time']??'');
                if(!preg_match('/^([01][0-9]|2[0-3])([0-5][0-9])$/D',$time)){$this->xml('Alarm','Use HHMM, e.g. 0730.');return;}
                $when=strtotime('today '.substr($time,0,2).':'.substr($time,2,2));
                if($when<=time())$when=strtotime('tomorrow '.substr($time,0,2).':'.substr($time,2,2));
                $this->transact(function(&$s)use($device,$line,$when){$s['alarms'][$device]=['line'=>$line,'when'=>$when];});
                $this->xml('Alarm','Scheduled for '.date('M j g:i A',$when));return;
            }
            if($op==='cancel') {
                $this->transact(function(&$s)use($device){unset($s['alarms'][$device]);});
                $this->xml('Alarms','Alarm cancelled.');return;
            }
            $alarm=$this->transact(function(&$s)use($device){return $s['alarms'][$device]??null;});
            $entries=[['Set Alarm',$route(['app'=>'alarms','op'=>'set'])]];
            if($alarm)$entries[]=['Cancel '.date('M j g:i A',$alarm['when']),$route(['app'=>'alarms','op'=>'cancel'])];
            $this->menu('Alarms',$entries);return;
        }
        $this->xml('Services','Unknown service.');
    }
    public function tick() {
        $due=$this->transact(function(&$s){
            $due=[];
            foreach(($s['alarms']??[]) as $device=>$alarm){
                if(($alarm['when']??PHP_INT_MAX)<=time()){
                    if(($alarm['when']??0)>=time()-600)$due[]=$alarm;
                    unset($s['alarms'][$device]);
                }
            }
            return $due;
        });
        foreach($due as $alarm){
            $line=(string)($alarm['line']??'');
            if(!preg_match('/^[0-9]{1,16}$/D',$line))continue;
            // Asterisk rings the extension; once answered it plays the built-in beep.
            $cmd='/usr/sbin/asterisk -rx '.escapeshellarg(
                'channel originate Local/'.$line.'@from-internal extension s@sccp-manager-services-alarm');
            $out=[];$status=0;exec($cmd.' 2>&1',$out,$status);
            if($status!==0)error_log('SCCP alarm originate failed for '.$line.': '.implode(' ',array_slice($out,-3)));
        }
    }
}
