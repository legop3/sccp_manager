<?php
namespace FreePBX\modules\Sccp_manager;

/** Isolated atomic state for one installed service. */
final class serviceAppStorage {
    private $id;
    private $path;
    public function __construct($id) {
        if (!preg_match('/^[a-z][a-z0-9-]{0,39}$/D',(string)$id))
            throw new \InvalidArgumentException('Invalid service identifier');
        $this->id=$id;
        $this->path='/var/spool/asterisk/sccp-manager-app-'.$id.'.json';
    }
    public function transaction(callable $callback) {
        if (is_link($this->path)) throw new \RuntimeException('Invalid service storage path');
        $fp=@fopen($this->path,'c+');
        if (!$fp) throw new \RuntimeException('Cannot open service storage');
        try {
            if (!flock($fp,LOCK_EX)) throw new \RuntimeException('Cannot lock service storage');
            rewind($fp);
            $state=json_decode(stream_get_contents($fp),true);
            if (!is_array($state)) $state=[];
            $value=$callback($state);
            $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($json===false || strlen($json)>262144)
                throw new \RuntimeException('Service state exceeds 256 KiB');
            rewind($fp);
            if (!ftruncate($fp,0) || fwrite($fp,$json)!==strlen($json) || !fflush($fp))
                throw new \RuntimeException('Service state write failed');
            return $value;
        } finally {
            flock($fp,LOCK_UN);
            fclose($fp);
        }
    }
    /** Release a previously claimed cooldown after a failed operation. */
    public function releaseCooldown($user) {
        $key=hash('sha256',(string)$user);
        $this->transaction(static function(array &$state)use($key){
            if(isset($state['cooldowns']) && is_array($state['cooldowns']))
                unset($state['cooldowns'][$key]);
        });
    }
    /** Read cooldown state without claiming another send. */
    public function cooldownRemaining($user) {
        return $this->transaction(static function(array &$state)use($user) {
            $key=hash('sha256',(string)$user);
            return max(0,(int)($state['cooldowns'][$key]??0)-time());
        });
    }
    /** Return seconds remaining, or 0 when this action was admitted. */
    public function cooldown($user,$seconds) {
        $seconds=max(1,min(86400,(int)$seconds));
        return $this->transaction(static function(array &$s)use($user,$seconds){
            $now=time();$key=hash('sha256',(string)$user);
            if(!isset($s['cooldowns']) || !is_array($s['cooldowns']))$s['cooldowns']=[];
            foreach($s['cooldowns'] as $k=>$until)
                if((int)$until<=$now)unset($s['cooldowns'][$k]);
            $wait=max(0,(int)($s['cooldowns'][$key]??0)-$now);
            if($wait===0)$s['cooldowns'][$key]=$now+$seconds;
            return $wait;
        });
    }
}
