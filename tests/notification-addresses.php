<?php
require_once __DIR__.'/../sccpManClasses/notificationDelivery.class.php';
$delivery=file_get_contents(__DIR__.'/../sccpManClasses/notificationDelivery.class.php');
$queue=file_get_contents(__DIR__.'/../sccpManClasses/notificationQueue.class.php');
$config=file_get_contents(__DIR__.'/../sccpManClasses/notificationConfig.class.php');
if (substr_count($delivery,'phoneNotifications::graphic(')!==1)
    throw new RuntimeException('Every delivery kind must use exactly one graphical renderer call');
if (!str_contains($delivery, "new notificationMessage('',\$actions,\$graphic"))
    throw new RuntimeException('Delivery must pass inline graphic directly to shared message');
foreach (['scheduledImageXml','publicDocument(','displayUrl(','notification-display.php'] as $unused)
    if (str_contains($delivery,$unused))
        throw new RuntimeException('Obsolete delivery path: '.$unused);
if (str_contains($queue,'notification-display.php') || str_contains($queue,'sound-only'))
    throw new RuntimeException('Queue must not retain old URL-based or sound-only paths');
if (str_contains($config,'readfile($file)'))
    throw new RuntimeException('Old XML display endpoint still generated');
$service=file_get_contents(__DIR__.'/../sccpManClasses/phoneservices.class.php');
if (!str_contains($service,"new notificationMessage('', ['Open'=>\$url],\$graphic)"))
    throw new RuntimeException('Services must send inline graphical notifications');
echo "Single notification delivery path passed\n";
