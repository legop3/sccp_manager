<?php
namespace FreePBX\modules\Sccp_manager;

/** Asterisk call-backed audio session adapter for an authenticated handset. */
final class serviceAppAudio {
    private $device;
    private $line;
    public function __construct($device,array $identity) {
        if (!phonevoicemail::validDevice($device))
            throw new \InvalidArgumentException('Invalid handset');
        $line=(string)($identity['line']??'');
        if (!preg_match('/^[0-9]{1,16}$/D',$line))
            throw new \InvalidArgumentException('Invalid handset line');
        $this->device=$device;
        $this->line=$line;
    }
    /**
     * Run a module-owned dialplan context after XML flush. The application
     * chooses a context, but cannot inject a shell command or external URL.
     */
    public function call($context) {
        if (!is_string($context) ||
            !preg_match('/^sccp-manager-services-[a-z0-9-]{1,40}$/D',$context))
            throw new \InvalidArgumentException('Invalid audio context');
        if (!is_file('/etc/asterisk/sccp-manager-voicemail.conf'))
            throw new \RuntimeException('Service dialplan not installed');
        $line=$this->line;
        register_shutdown_function(static function()use($line,$context){
            if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
            else {while(ob_get_level()>0)@ob_end_flush();@flush();}
            $cmd='channel originate SCCP/'.$line.'/aa=1w/ringer=silent extension s@'.$context;
            $out=[];$code=0;
            exec('/usr/sbin/asterisk -rx '.escapeshellarg($cmd).' 2>&1',$out,$code);
            if($code!==0)error_log('SCCP service audio: '.implode(' ',array_slice($out,-3)));
        });
    }
}
