<?php
namespace FreePBX\modules\Sccp_manager {
    // Lightweight stand-ins for the external/handset boundaries. The real
    // notificationMessage value object is used without modification.
    final class phonevoicemail {
        public static function validDevice($id) {
            return is_string($id) && preg_match('/^SEP[0-9A-F]{12}$/D',$id);
        }
    }
    final class notificationDelivery {
        public function __construct($module) {}
        public function devices() {return [
            'SEP000000000001'=>'10.254.77.11',
            'SEP000000000002'=>'10.254.77.12'
        ];}
        public function configuredSound($id) {return $id==='1122334455667788'?'PC-1122334455667788.raw':null;}
    }
    final class notificationConfig {
        public function __construct($module) {}
        public function appearance() {return ['theme'=>'custom','stripe_position'=>'top'];}
    }
    final class phoneNotifications {
        public static $rendered=[];
        public static function graphic($module,$fields,$appearance,$device) {
            self::$rendered[]=compact('fields','appearance','device');
            return '<CiscoIPPhoneImageFile><Title>Test</Title>'.
                '<LocationX>0</LocationX><LocationY>0</LocationY>'.
                '<URL>http://example.test/generated-'.$device.'.png</URL>'.
                '</CiscoIPPhoneImageFile>';
        }
    }
    final class notificationQueue {
        public static $sent=[];
        public static $reject=false;
        public function __construct($module) {}
        public function send($device,$ip,notificationMessage $message) {
            self::$sent[]=compact('device','ip','message');
            return self::$reject?['ok'=>false,'status'=>503]:['ok'=>true,'status'=>200];
        }
    }
}
namespace {
    require_once __DIR__.'/../sccpManClasses/notificationMessage.php';
    require_once __DIR__.'/../sccpManClasses/serviceAppNotifications.php';
    use FreePBX\modules\Sccp_manager\serviceAppNotifications as Adapter;
    use FreePBX\modules\Sccp_manager\notificationQueue as Queue;
    use FreePBX\modules\Sccp_manager\phoneNotifications as Renderer;
    function verifyNotify($ok,$reason) {
        if(!$ok)throw new RuntimeException($reason);
    }
    $module=new class {
        public $phoneservices;
        public function __construct() {
            $this->phoneservices=new class {
                public function url($id) {return 'http://10.254.77.1/sccp-manager/service.php?phone='.$id.'&key=test';}
            };
        }
    };
    $cat=new Adapter($module,'catdog');
    [$sent,$total]=$cat->broadcast('Random cat','A cat has arrived','image-bytes','all');
    verifyNotify($sent===2 && $total===2,'Broadcast failed to select all connected phones');
    verifyNotify(count(Queue::$sent)===2,'Broadcast must enqueue per phone');
    foreach(Queue::$sent as $i=>$item) {
        $id='SEP00000000000'.($i+1);
        verifyNotify($item['device']===$id,'Delivery must select the actual session device');
        verifyNotify($item['ip']==='10.254.77.1'.($i+1),'Delivery must use the VPN session IP');
        verifyNotify(str_contains($item['message']->payload()['actions']['Open'],$id),
            'Open URL must be generated per recipient');
    }
    verifyNotify(Renderer::$rendered[0]['appearance']['theme']==='custom',
        'Services must use the global notification appearance');
    verifyNotify(Renderer::$rendered[0]['fields']['image']==='image-bytes',
        'Services must pass optional image bytes to the shared renderer');
    Queue::$sent=[];
    $messages=new Adapter($module,'messages');
    [$sent,$total]=$messages->send([
        'title'=>'New message','primary'=>'Hello','secondary'=>'From ext 150'
    ],'SEP000000000002',static function($phone) {
        return ['Read'=>['url'=>'http://10.254.77.1/sccp-manager/service.php?phone='.
            $phone.'&app=messages&op=read','clear'=>true]];
    },'1122334455667788');
    verifyNotify($sent===1 && $total===1 && count(Queue::$sent)===1,'Targeted notification failed');
    $payload=Queue::$sent[0]['message']->payload();
    verifyNotify($payload['actions']['Read']['clear']===true,
        'Existing clear-on-activation option must be forwarded');
    verifyNotify(str_contains($payload['actions']['Read']['url'],'SEP000000000002'),
        'Targeted actions must belong to recipient');
    verifyNotify($payload['sound']==='PC-1122334455667788.raw',
        'Existing uploaded ringtone must be resolved through notificationDelivery');
    [$sent,$total]=$messages->send(['title'=>'Reminder','primary'=>'Take a break'],'self',[],
        '','SEP000000000001');
    verifyNotify($sent===1 && $total===1,'Self scope did not select requester');
    verifyNotify(count(Queue::$sent)===2,'Separate events must be sent separately to queue');
    [$sent,$total]=$messages->send(['title'=>'Game','primary'=>'Invite'],'SEP000000000003');
    verifyNotify($sent===0 && $total===0,'Disconnected phone must not receive a push');
    Queue::$reject=true;
    $before=count(Queue::$sent);
    [$sent,$total]=$messages->send(['title'=>'Unavailable','primary'=>'Message'],
        'SEP000000000001');
    verifyNotify($sent===0 && $total===1 && count(Queue::$sent)===$before+1,
        'Unaccepted pushes must go through the queue and report failure');
    Queue::$reject=false;
    try {
        $messages->send(['title'=>'Bad'],'SEP000000000001',['A'=>'Dial:1'],'invalid-sound');
        throw new RuntimeException('Unknown ringtone accepted');
    } catch(InvalidArgumentException $expected) {}
    $before=count(Queue::$sent);
    [$sent,$total]=$messages->send(['title'=>'Bad'],'SEP000000000001',
        ['A'=>'Dial:1','B'=>'Dial:2','C'=>'Dial:3','D'=>'Dial:4']);
    verifyNotify($sent===0 && $total===1 && count(Queue::$sent)===$before,
        'Invalid actions must not bypass notificationMessage validation');
    $legacy=file_get_contents(__DIR__.'/../sccpManClasses/serviceLegacySupport.php');
    verifyNotify(!str_contains($legacy,'function push(') &&
        !str_contains($legacy,'phoneNotifications::graphic(') &&
        !str_contains($legacy,'new notificationQueue('),
        'Legacy notification renderer or sender still exists');
    $adapter=file_get_contents(__DIR__.'/../sccpManClasses/serviceAppNotifications.php');
    verifyNotify(str_contains($adapter,'phoneNotifications::graphic(') &&
        str_contains($adapter,'new notificationMessage(') &&
        str_contains($adapter,'$queue->send('),
        'Service adapter must reuse the universal render, message and queue');
    Queue::$sent=[];
    [$now,$targets,$deferred]=$cat->broadcast('Random dog','A dog arrived',
        'image-bytes','all','SEP000000000001',true);
    verifyNotify($now===1 && $targets===2 && $deferred===1,
        'Broadcast must include sender but postpone its push');
    verifyNotify(count(Queue::$sent)===1 && Queue::$sent[0]['device']==='SEP000000000002',
        'Other phones must receive push before requester');
    register_shutdown_function(static function() {
        verifyNotify(count(Queue::$sent)===2 &&
            Queue::$sent[1]['device']==='SEP000000000001',
            'Originating handset must get its own notification after XML response');
        $action=Queue::$sent[1]['message']->payload()['actions']['Open']??'';
        verifyNotify(str_contains($action,'SEP000000000001'),
            'Deferred sender action must be authenticated for itself');
        echo "Deferred sender notification fired after response flush\n";
    });
    echo "Unified service notifications, targeting, per-phone actions and sounds passed\n";
}
