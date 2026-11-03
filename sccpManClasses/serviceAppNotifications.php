<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * The only notification-producing interface used by phone Services.
 *
 * Applications supply content, recipients and optional actions/sound ID.
 * The existing notification renderer supplies the global visual theme;
 * notificationMessage validates actions; notificationQueue owns history,
 * dismissal, context navigation and the authenticated handset push.
 */
final class serviceAppNotifications {
    private $module;
    private $id;

    public function __construct($module,$id) {
        if(!is_string($id) || !preg_match('/^[a-z][a-z0-9-]{0,39}$/D',$id))
            throw new \InvalidArgumentException('Invalid service identifier');
        $this->module=$module;
        $this->id=$id;
    }

    /**
     * @param array $fields renderer fields (title, primary, secondary, detail,
     *                      timestamp, optional image bytes).
     * @param string|array $targets 'all', 'self', SEP identifier, or SEP list.
     * @param array|callable $actions fixed actions or recipient-specific
     *                                callable(string $device): array.
     * @param string $soundId an uploaded ringtone ID, or empty for silence.
     * @param string|null $requester required for 'self' delivery.
     * @return array [accepted push count, matched connected phone count].
     *
     * Failed pushes can still be in the persistent notification history:
     * notificationQueue records a notification before attempting its push.
     */
    public function send(array $fields,$targets='all',$actions=[],$soundId='',$requester=null) {
        foreach(['title','primary','secondary','detail'] as $key) {
            if(isset($fields[$key]) && !is_string($fields[$key]))
                throw new \InvalidArgumentException('Invalid notification text: '.$key);
        }
        if(isset($fields['image']) && !is_string($fields['image']))
            throw new \InvalidArgumentException('Invalid notification image');
        if(!is_array($actions) && !is_callable($actions))
            throw new \InvalidArgumentException('Invalid notification actions');
        if(!is_string($soundId))
            throw new \InvalidArgumentException('Invalid notification sound');
        if(!is_array($targets) && !is_string($targets))
            throw new \InvalidArgumentException('Invalid notification recipients');

        $delivery=new notificationDelivery($this->module);
        $connected=$delivery->devices();
        if($targets==='all') {
            $selected=$connected;
        } elseif($targets==='self') {
            if(!is_string($requester) || !phonevoicemail::validDevice($requester))
                throw new \InvalidArgumentException('Invalid requesting phone');
            $selected=isset($connected[$requester])?[$requester=>$connected[$requester]]:[];
        } else {
            $ids=is_array($targets)?$targets:[$targets];
            $selected=[];
            foreach($ids as $id) {
                if(!phonevoicemail::validDevice($id))
                    throw new \InvalidArgumentException('Invalid recipient phone');
                if(isset($connected[$id]))$selected[$id]=$connected[$id];
            }
        }
        if(!$selected)return [0,0];

        $appearance=(new notificationConfig($this->module))->appearance();
        $sound=$soundId===''?null:$delivery->configuredSound($soundId);
        if($soundId!=='' && $sound===null)
            throw new \InvalidArgumentException('Notification sound must be an uploaded ringtone');
        $queue=new notificationQueue($this->module);
        $fields+=['title'=>'Notification','primary'=>'','secondary'=>'',
            'detail'=>'','timestamp'=>date('m/d/Y g:i A')];
        $accepted=0;
        foreach($selected as $device=>$ip) {
            try {
                $deviceActions=is_callable($actions)?$actions($device):$actions;
                if(!is_array($deviceActions))
                    throw new \InvalidArgumentException('Recipient actions must be an array');
                $graphic=phoneNotifications::graphic($this->module,$fields,$appearance,$device);
                if(!$graphic)throw new \RuntimeException('Notification image rendering failed');
                $message=new notificationMessage($deviceActions,$graphic,$sound);
                $result=$queue->send($device,$ip,$message);
                if(!empty($result['ok']))$accepted++;
                else error_log('SCCP service notification '.$this->id.' / '.$device.
                    ': push not accepted (queue entry retained), HTTP '.(int)($result['status']??0));
            }catch(\Throwable $e) {
                error_log('SCCP service notification '.$this->id.' / '.$device.': '.$e->getMessage());
            }
        }
        return [$accepted,count($selected)];
    }

    /** Existing broadcast API retained as a thin adapter, not another sender. */
    public function broadcast($title,$caption,$image,$scope='all',$requester=null) {
        if(!is_string($image) || $image==='')
            throw new \InvalidArgumentException('Broadcast requires image bytes');
        return $this->send(['title'=>(string)$title,'primary'=>(string)$caption,
            'image'=>$image],$scope,
            function($device) {
                $url=$this->module->phoneservices->url($device);
                return ['Open'=>$url.'&app='.rawurlencode($this->id)];
            },'', $requester);
    }
}
