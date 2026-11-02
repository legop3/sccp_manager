# SCCP Manager notification API

Notification producers should use **only** the shared `notificationMessage` object
and `notificationQueue::send()`. Do not POST to `/CGI/Execute` directly or
modify queue storage from a producer.

```php
use FreePBX\modules\Sccp_manager\notificationMessage;
use FreePBX\modules\Sccp_manager\notificationQueue;

$message = new notificationMessage(
    '', // URL is optional if inline XML is supplied
    ['Open' => $appUrl], // up to three sender-defined actions
    '<CiscoIPPhoneText><Title>Reminder</Title><Text>Check the door</Text></CiscoIPPhoneText>'
);
$result = (new notificationQueue($module))->send($device, $sessionIp, $message);
if (!$result['ok']) error_log('Phone notification delivery failed: HTTP '.$result['status']);
```

The queue owns identity, storage, newest-first ordering, the first **Clear**
softkey, delivery and the return URL. The sender owns XML body, app-specific
actions and any menus. New visible notifications require a `CiscoIPPhoneImageFile` XML document. An optional URL identifies the source application, but cannot replace the image document. A `sound` may accompany the image. Sound-only pushes
have no UI to dismiss and thus aren't stored as visible queue entries.

When a notification action opens an application, the shared manager adds
`notification_id` to URLs in the SCCP Manager phone-content directory.
The application should pass that identity along in its own menu links.
Use `(new notificationQueue($module))->returnControls($device,$id)`
to retrieve an authenticated `return` URL and a `clear` URL. Provide a
**Back** softkey pointing to `return` rather than `SoftKey:Exit`
or `Init:Services`. Clearing removes the item and reveals the next entry;
returning retains it. Without passing the context through its menus, an
application cannot automatically recover navigation on Cisco 79xx firmware.

For an application URL whose page is the first notification screen,
`notification_entry=1` is included in the query. This permits the page
to expose Clear first and its regular navigation controls while keeping
the queue agnostic about the application's implementation.

Keep all URLs XML-escaped when rendering, especially ampersands in query
parameters. Use DOM text nodes or `htmlspecialchars(..., ENT_XML1)`.
Never set a DOM node's `nodeValue` directly to a raw URL.

The existing legacy generated notification-display URLs remain readable from prior queue entries
for queued entries from older releases; new producers should use inline XML
when possible. All actual Cisco XML behavior must be verified on physical
phones before marking a new application as production-ready.
