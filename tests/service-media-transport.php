<?php
/** Media frames must be pushed directly, not through a static XML URL. */
require_once __DIR__.'/../sccpManClasses/serviceAppScreens.php';
use FreePBX\modules\Sccp_manager\serviceAppScreens;
$png='http://10.254.77.1/sccp-manager/media-demo-0123456789abcdef-0.png';
$xml=serviceAppScreens::document($png);
$d=new DOMDocument();
if(!@$d->loadXML($xml,LIBXML_NONET) ||
    $d->documentElement->tagName!=='CiscoIPPhoneImageFile' ||
    $d->getElementsByTagName('URL')->item(0)->textContent!==$png)
    throw new RuntimeException('Direct image XML payload invalid');
if($d->getElementsByTagName('Title')->item(0)->textContent!=='media test')
    throw new RuntimeException('Media screen label must be lowercase');
foreach(['https://example.org/file.png','http://10.0.0.1/x.png',
         'http://10.0.0.1/sccp-manager/../admin.png'] as $bad) {
    try {
        serviceAppScreens::document($bad);
        throw new RuntimeException('Unsafe image URL accepted: '.$bad);
    } catch(InvalidArgumentException $expected) {}
}
$source=file_get_contents(__DIR__.'/../sccpManClasses/serviceAppScreens.php');
$expected="http_build_query(['XML'=>" . '$document' . "])";
if(!str_contains($source,$expected) ||
    str_contains($source,"$"."name.'.xml'"))
    throw new RuntimeException('Media transport must push ImageFile XML directly');
$worker=file_get_contents(__DIR__.'/../scripts/phone-media-test.php');
if(!str_contains($worker,'usleep(750000)') ||
    !str_contains($worker,'break;') ||
    !str_contains($worker,'sleep(30)'))
    throw new RuntimeException('Worker must pace frames, abort failed pushes and delay cleanup');
echo "Media XML direct push, URL validation, pacing and cleanup regression checks passed\n";
