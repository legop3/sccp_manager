<?php
/**
 * Lightweight, one-URL image endpoint for the Cisco adaptive media experiment.
 * No FreePBX bootstrap per frame. The run/key are created by an authenticated
 * Services action and never supplied from an untrusted media source.
 */
ini_set('display_errors','0');
$device=(string)($_GET['phone']??'');
$run=(string)($_GET['session']??'');
$key=(string)($_GET['key']??'');
if(!preg_match('/^SEP[A-F0-9]{12}$/D',$device) ||
    !preg_match('/^[a-f0-9]{16}$/D',$run) ||
    !preg_match('/^[a-f0-9]{32}$/D',$key)) {
    http_response_code(404); exit;
}
// The published endpoint lives alongside the session PNG and ticket.
// Never traverse Asterisk's private spool from Apache.
$root=__DIR__;
$metaPath=$root.'/sccp-media-'.$device.'.json';
if(!is_file($metaPath) || is_link($metaPath)) {
    error_log('SCCP adaptive media HTTP: session metadata unavailable');
    http_response_code(404); exit;
}
$meta=json_decode((string)@file_get_contents($metaPath),true);
if(!is_array($meta) || ($meta['run']??'')!==$run ||
    !((is_string($meta['key_hash']??null) &&
        hash_equals($meta['key_hash'],hash('sha256',$key))) ||
      (is_string($meta['key']??null) && hash_equals($meta['key'],$key))) ||
    // The signed per-run URL is the authorization credential. The HTTP
    // source address can differ from the SCCP connection across VPN/NAT.
    (int)($meta['expires']??0)<time()) {
    error_log('SCCP adaptive media HTTP: session expired or invalid');
    http_response_code(404); exit;
}
// The notification-style image screen is requested separately from the PNG.
// The same signed media session authenticates both requests. This screen
// response does not acknowledge an image GET; only the PNG response does.
if(($_GET['view']??'')==='screen') {
    $stopkey=(string)($_GET['stopkey']??'');
    $host=(string)($_SERVER['HTTP_HOST']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$stopkey) ||
        !filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) {
        http_response_code(404); exit;
    }
    $base='http://'.$host.'/sccp-manager/';
    $frameUrl=$base.'media-frame.php?'.http_build_query([
        'phone'=>$device,'session'=>$run,'key'=>$key
    ],'','&',PHP_QUERY_RFC3986);
    $stopUrl=$base.'service.php?'.http_build_query([
        'phone'=>$device,'key'=>$stopkey,'app'=>'media-player','op'=>'stop'
    ],'','&',PHP_QUERY_RFC3986);
    $escape=static fn($value)=>htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');
    header('Content-Type: text/xml; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<?xml version="1.0" encoding="UTF-8"?>'.
        '<CiscoIPPhoneImageFile><Title>media player</Title>'.
        '<LocationX>0</LocationX><LocationY>0</LocationY>'.
        '<URL>'.$escape($frameUrl).'</URL>'.
        '<Prompt>media player</Prompt>'.
        '<SoftKeyItem><Name>Stop</Name><URL>'.$escape($stopUrl).
        '</URL><Position>1</Position></SoftKeyItem>'.
        '</CiscoIPPhoneImageFile>';
    exit;
}
$path=$root.'/sccp-media-'.$device.'-'.$run.'.png';
if(!is_file($path) || is_link($path)) {http_response_code(503);exit;}
$bytes=@file_get_contents($path);
if(!is_string($bytes) || strlen($bytes)<8 || strlen($bytes)>1048576 ||
    substr($bytes,0,8)!=="\x89PNG\r\n\x1a\n") {http_response_code(503);exit;}
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:01 GMT');
header('Content-Length: '.strlen($bytes));
header('Connection: close');
echo $bytes;
// Signal after the HTTP body is handed off. On mod_php, script completion is
// the nearest observable completion point. This does NOT prove LCD rendering.
if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
else {if(ob_get_level())@ob_flush();@flush();}
$ack=$root.'/sccp-media-'.$device.'-'.$run.'.ack';
// Ensure a retired run cannot wake a new one.
if(is_file($metaPath) && !is_link($metaPath)) {
    $current=json_decode((string)@file_get_contents($metaPath),true);
    if(is_array($current) && ($current['run']??null)===$run &&
        is_file($path) && !is_link($ack)) {
        @file_put_contents($ack,'done',LOCK_EX);
    }
}
