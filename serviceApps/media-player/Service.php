<?php
/**
 * Proof of transport: genuine SCCP/Asterisk audio call alongside quick
 * XML image pushes. No claim of true video decoding or synchronized playback.
 */
return static function(array $ctx,array $q) {
    if(($q['op']??'')==='benchmark'){
        try {
            $ctx['media']->benchmark($ctx['config']['test_frames']);
            return $ctx['text']('Media Player',
                'Starting audio call and image speed test. Check the screen and PBX logs for actual frame rate.');
        }catch(\Throwable $e){
            error_log('SCCP media player: '.$e->getMessage());
            return $ctx['text']('Media Player','Frame test could not start.');
        }
    }
    return $ctx['menu']('Media Player',[
        ['Audio + Video Speed Test',$ctx['route'](['op'=>'benchmark'])]
    ]);
};
