<?php
namespace FreePBX\modules\Sccp_manager;

/** Adapter to the module's existing themed renderer and notification queue. */
final class serviceAppNotifications {
    private $module;
    private $id;
    public function __construct($module,$id) {
        $this->module=$module;
        $this->id=$id;
    }
    public function broadcast($title,$caption,$image) {
        if (!is_string($image) || $image==='')
            throw new \InvalidArgumentException('Broadcast requires image bytes');
        $devices=(new notificationDelivery($this->module))->devices();
        if (!$devices) return [0,0];
        $theme=(new notificationConfig($this->module))->appearance();
        $queue=new notificationQueue($this->module);
        $count=0;
        foreach($devices as $device=>$ip){
            try {
                $graphic=phoneNotifications::graphic($this->module,[
                    'title'=>$title,'primary'=>$caption,'timestamp'=>date('m/d/Y g:i A'),
                    'image'=>$image
                ],$theme,$device);
                if(!$graphic)continue;
                $url=$this->module->phoneservices->url($device).'&app='.rawurlencode($this->id);
                $response=$queue->send($device,$ip,new notificationMessage(['Open'=>$url],$graphic));
                if(!empty($response['ok']))$count++;
            }catch(\Throwable $e){
                error_log('SCCP app broadcast '.$device.': '.$e->getMessage());
            }
        }
        return [$count,count($devices)];
    }
}
