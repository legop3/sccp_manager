<?php
namespace FreePBX\modules\Sccp_manager;

/** Bounded Cisco screen-frame transport experiments. */
final class serviceAppMedia {
    private $device;
    public function __construct($device) {
        if(!phonevoicemail::validDevice($device))
            throw new \InvalidArgumentException('Invalid media target');
        $this->device=$device;
    }
    /**
     * No cooldown. Existing worker's exclusive per-device lock prevents
     * overlapping streams. Repeated tests can start immediately after exit.
     */
    public function benchmark($frames=32) {
        $frames=(int)$frames;
        if($frames<1 || $frames>120)throw new \InvalidArgumentException('Invalid frame count');
        $script=dirname(__DIR__).'/scripts/phone-media-test.php';
        if(!is_file($script))throw new \RuntimeException('Media worker missing');
        $device=$this->device;
        register_shutdown_function(static function()use($device,$script,$frames){
            if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
            else {while(ob_get_level()>0)@ob_end_flush();@flush();}
            $cmd='/usr/bin/php '.escapeshellarg($script).' '.escapeshellarg($device).' '.escapeshellarg((string)$frames);
            exec($cmd.' >/dev/null 2>&1 </dev/null &');
        });
    }
}
