<?php
/** Cisco phone interface for uploaded media. All visible labels lowercase. */
return static function(array $ctx,array $q) {
    $media=$ctx['media'];
    $op=(string)($q['op']??'list');
    if($op==='stop') {
        $media->stop();
        return $ctx['text']('media player','stopping playback');
    }
    if($op==='play') {
        try {
            $line=(string)($ctx['line']??'');
            $media->play((string)($q['file']??''),$line);
            return $ctx['text']('media player','starting playback. hang up to stop, or open this service again.');
        }catch(\Throwable $e) {
            error_log('SCCP media service: '.$e->getMessage());
            return $ctx['text']('media player',strtolower($e->getMessage()));
        }
    }
    $entries=[];
    if($now=$media->nowPlaying()) {
        $entries[]=['stop playback',$ctx['route'](['op'=>'stop'])];
        $entries[]=['now playing: '.(string)($now['name']??'media'),
            $ctx['route'](['op'=>'status'])];
        if($op==='status') {
            $status=$media->imageStatus();
            $image=is_array($status)?(' screen: '.(string)($status['phase']??'unknown').
                ' '.(string)($status['detail']??'')):' screen: no image status yet';
            return $ctx['text']('media player','playing: '.(string)($now['name']??'media').$image);
        }
    }
    foreach($media->library() as $row)
        $entries[]=[(string)$row['name'],$ctx['route'](['op'=>'play','file'=>$row['id']])];
    if(!$entries)return $ctx['text']('media player','no media uploaded. add files in sccp manager.');
    return $ctx['menu']('media player',$entries);
};
