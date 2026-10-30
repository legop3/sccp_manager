<?php
// Minimal self-contained Cisco XML service endpoint. No shell execution.
header('Content-Type: text/xml; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function esc($v) { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function outputText($title, $text) {
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<CiscoIPPhoneText><Title>'.esc($title).'</Title><Text>'.esc($text).'</Text></CiscoIPPhoneText>';
    exit;
}
function outputMenu($title,$rows) {
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<CiscoIPPhoneMenu><Title>'.esc($title).'</Title><Prompt>Select an item</Prompt>';
    foreach ($rows as $name=>$url) echo '<MenuItem><Name>'.esc($name).'</Name><URL>'.esc($url).'</URL></MenuItem>';
    echo '</CiscoIPPhoneMenu>'; exit;
}
function remote($url) {
    $ctx=stream_context_create(array('http'=>array('timeout'=>3,'user_agent'=>'SCCP-Manager/17.1.83','follow_location'=>0)));
    $s=@file_get_contents($url,false,$ctx,0,150000);
    return $s===false ? '' : $s;
}
$app=(string)($_GET['app']??'');
$action=(string)($_GET['action']??'');
$base='http://'.($_SERVER['HTTP_HOST']??'127.0.0.1').($_SERVER['SCRIPT_NAME']??'/sccp-manager/service.php');
if($app==='weather') {
    // Default New York location until admin-configurable per-phone geography is available.
    $raw=remote('https://api.open-meteo.com/v1/forecast?latitude=40.7128&longitude=-74.0060&current=temperature_2m,relative_humidity_2m,wind_speed_10m,weather_code&daily=temperature_2m_max,temperature_2m_min&temperature_unit=fahrenheit&wind_speed_unit=mph&timezone=America%2FNew_York&forecast_days=3');
    $data=json_decode($raw,true);
    if(!is_array($data)||!isset($data['current'])) outputText('Weather','Weather service unavailable. Try again later.');
    $cur=$data['current']; $daily=$data['daily']??array();
    $lines=array('New York, NY', 'Temperature: '.($cur['temperature_2m']??'?').' F', 'Humidity: '.($cur['relative_humidity_2m']??'?').'%', 'Wind: '.($cur['wind_speed_10m']??'?').' mph', '');
    foreach(($daily['time']??array()) as $i=>$day) $lines[]=$day.': '.($daily['temperature_2m_min'][$i]??'?').' - '.($daily['temperature_2m_max'][$i]??'?').' F';
    outputText('Weather',implode("\n",$lines));
}
if($app==='news'){
    $raw=remote('https://feeds.bbci.co.uk/news/rss.xml');
    if($raw==='') outputText('News','News feed unavailable.');
    libxml_use_internal_errors(true);
    $rss=simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
    if(!$rss) outputText('News','Could not read news feed.');
    if($action==='story'){
        $index=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
        $items=$rss->channel->item;
        if($index===false || $index===null || $index<0 || $index>=min(12,count($items))) outputText('News','Story unavailable.');
        outputText((string)$items[$index]->title, trim(strip_tags((string)$items[$index]->description)));
    }
    $rows=array();$i=0;
    foreach($rss->channel->item as $item){if($i>=12)break;$rows[mb_substr((string)$item->title,0,60)]=$base.'?app=news&action=story&id='.$i;$i++;}
    outputMenu('News',$rows);
}
if($app==='games'){
    if($action==='coin') outputText('Coin Flip',random_int(0,1)?'Heads':'Tails');
    if($action==='dice') outputText('Dice Roll','You rolled '.random_int(1,6).'.');
    if($action==='eight') {
        $answers=array('Yes','No','Probably','Ask again later','Definitely','Unlikely','Outlook good','Cannot predict now');
        outputText('Magic 8 Ball',$answers[random_int(0,count($answers)-1)]);
    }
    outputMenu('Games',array('Flip a Coin'=>$base.'?app=games&action=coin','Roll a Die'=>$base.'?app=games&action=dice','Magic 8 Ball'=>$base.'?app=games&action=eight'));
}
if($app==='messages') outputText('Messages','Phone-to-phone text messaging is not available in this build.');
if($app==='alarms') outputText('Alarms','Scheduled wake-up calls are not available in this build.');
http_response_code(404);outputText('Services','Unknown service.');
