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
    /** A short FFmpeg-generated clip paced by each handset image GET. */
    public function adaptiveTest() {
        if(!is_executable('/usr/bin/ffmpeg'))
            throw new \RuntimeException('ffmpeg is not installed');
        if(!function_exists('proc_open'))
            throw new \RuntimeException('php proc_open is unavailable');
        $script=dirname(__DIR__).'/scripts/phone-media-adaptive.php';
        if(!is_file($script))throw new \RuntimeException('Adaptive media worker missing');
        $device=$this->device;
        register_shutdown_function(static function()use($device,$script){
            if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
            else {while(ob_get_level()>0)@ob_end_flush();@flush();}
            $cmd='/usr/bin/php '.escapeshellarg($script).' '.escapeshellarg($device);
            exec($cmd.' >/dev/null 2>&1 </dev/null &');
        });
    }
    public function library() {
        require_once __DIR__.'/serviceMediaLibrary.php';
        return serviceMediaLibrary::rows();
    }
    public function nowPlaying() {
        $meta='/var/spool/asterisk/sccp-media-'.$this->device.'.json';
        if(!is_file($meta) || is_link($meta))return null;
        $item=json_decode((string)@file_get_contents($meta),true);
        if(!is_array($item) || empty($item['file']) ||
           (int)($item['expires']??0)<time())return null;
        return $item;
    }
    public function stop() {
        $item=$this->nowPlaying();
        if(!$item)return false;
        $run=(string)($item['run']??'');
        if(!preg_match('/^[a-f0-9]{16}$/D',$run))return false;
        $path='/var/spool/asterisk/sccp-media-'.$this->device.'-'.$run.'.stop';
        if(is_link($path))return false;
        return @file_put_contents($path,'stop',LOCK_EX)!==false;
    }
    public function play($id,$line) {
        require_once __DIR__.'/serviceMediaLibrary.php';
        serviceMediaLibrary::find($id);
        if(!preg_match('/^[0-9]{1,16}$/D',(string)$line))
            throw new \InvalidArgumentException('invalid media line');
        if(!is_executable('/usr/bin/ffmpeg'))
            throw new \RuntimeException('ffmpeg is not installed');
        if($this->nowPlaying()) {
            $this->stop();
            throw new \RuntimeException('stop current playback before starting another');
        }
        $script=dirname(__DIR__).'/scripts/phone-media-play.php';
        if(!is_file($script))throw new \RuntimeException('media playback worker missing');
        $device=$this->device;
        register_shutdown_function(static function()use($device,$id,$line,$script){
            if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
            else {while(ob_get_level()>0)@ob_end_flush();@flush();}
            $cmd='/usr/bin/php '.escapeshellarg($script).' '.
                escapeshellarg($device).' '.escapeshellarg($id).' '.escapeshellarg($line);
            exec($cmd.' >/dev/null 2>&1 </dev/null &');
        });
    }
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
