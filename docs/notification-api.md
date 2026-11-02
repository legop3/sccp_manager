# SCCP Manager notification API

Every phone notification uses a single graphical image pipeline.

1. The sender builds generic display fields: `title`, `primary`, `secondary`, `detail`, and `timestamp`.
2. The shared `phoneNotifications::graphic($module, $fields, $appearance, $device)` renders a `CiscoIPPhoneImageFile` XML document and its PNG using `notificationConfig::appearance()`.
3. The sender constructs `notificationMessage($actions, $xml, $optionalSound)`, with at most three actions.
4. `notificationQueue::send($device, $sessionIp, $message)` stores the image document, adds Clear as the first softkey, then pushes the queue URL to the Cisco phone.

There are no text, status, standalone image-source, URL-only, or sound-only notification modes. Sounds may accompany a graphical notification.

An action URL pointing to an SCCP Manager PHP service receives `notification_id` from `notificationQueue::contextLink()`. The opened service carries that context through its own menus and uses `notificationNavigation` for the Back or Clear softkeys. Direct `Dial:` actions invoke the phone's native dialing interface; reopening a notification after hanging up is not guaranteed by Cisco's XML browser.

The queue shows the newest notification first. Clearing it removes only that notification and displays the next available one. The queue rejects graphical XML that contains its own prompt or softkeys: the queue owns these controls.

Examples:

```php
$appearance=(new notificationConfig($module))->appearance();
$fields=[
    'title'=>'Reminder',
    'primary'=>'Check the door',
    'secondary'=>'',
    'detail'=>'',
    'timestamp'=>date('m/d/Y g:i A')
];
$xml=phoneNotifications::graphic($module,$fields,$appearance,$device);
$message=new notificationMessage(['Open'=>$appUrl], $xml);
$result=(new notificationQueue($module))->send($device,$sessionIp,$message);
```

All actual handset behavior still needs verification on the Cisco phones.
