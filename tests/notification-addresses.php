<?php
require_once __DIR__.'/../sccpManClasses/notificationDelivery.class.php';
$delivery=file_get_contents(__DIR__.'/../sccpManClasses/notificationDelivery.class.php');
$queue=file_get_contents(__DIR__.'/../sccpManClasses/notificationQueue.class.php');
$config=file_get_contents(__DIR__.'/../sccpManClasses/notificationConfig.class.php');
if (substr_count($delivery,'phoneNotifications::graphic(')!==1)
    throw new RuntimeException('Every delivery kind must use exactly one graphical renderer call');
if (!str_contains($delivery, "new notificationMessage(\$actions,\$graphic"))
    throw new RuntimeException('Delivery must pass inline graphic directly to shared message');
foreach (['scheduledImageXml','publicDocument(','displayUrl(','notification-display.php'] as $unused)
    if (str_contains($delivery,$unused))
        throw new RuntimeException('Obsolete delivery path: '.$unused);
if (str_contains($queue,'notification-display.php') || str_contains($queue,'sound-only'))
    throw new RuntimeException('Queue must not retain old URL-based or sound-only paths');
if (str_contains($config,'readfile($file)'))
    throw new RuntimeException('Old XML display endpoint still generated');
$service=file_get_contents(__DIR__.'/../sccpManClasses/serviceLegacySupport.php');
if (!str_contains($service,"new notificationMessage(['Open'=>\$url],\$graphic)"))
    throw new RuntimeException('Migrated services must send inline graphical notifications');
$imageRenderer=file_get_contents(__DIR__.'/../sccpManClasses/phoneNotifications.class.php');
$source=file_get_contents(__DIR__.'/../sccpManClasses/notificationSources.class.php');
$admin=file_get_contents(__DIR__.'/../sccpManTraits/bmoFunctions.php');
$nav=file_get_contents(__DIR__.'/../sccpManClasses/notificationNavigation.php');
if (str_contains($imageRenderer,'public static function execute(') ||
    str_contains($imageRenderer,'function previewFields(') ||
    str_contains($imageRenderer,'SoftKeyItem'))
    throw new RuntimeException('Renderer contains old push, preview, or softkey implementation');
if (!str_contains($source,'public static function image(') || str_contains($source,'imagepng('))
    throw new RuntimeException('Source layer must fetch image bytes, not render another notification');
if (str_contains($admin,'notifications_preview') || str_contains($admin,'previewFields('))
    throw new RuntimeException('Obsolete AJAX preview handler remains');
if (str_contains($nav,'notification_entry') || str_contains($queue,'notification_entry'))
    throw new RuntimeException('Retired notification entry navigation path remains');
if (str_contains($config,"'style'=>'text'") ||
    str_contains($config,"'show_number'") ||
    str_contains($config,"'show_details'"))
    throw new RuntimeException('Old appearance or display mode settings remain');
echo "Single notification delivery path passed\n";
