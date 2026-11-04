<?php
/**
 * Proof of transport: genuine SCCP/Asterisk audio call alongside quick
 * XML image pushes. No claim of true video decoding or synchronized playback.
 */
return static function(array $ctx,array $q) {
    if(($q['op']??'')==='benchmark'){
        try {
            $ctx['media']->benchmark($ctx['config']['test_frames']);
            return $ctx['text']('media player',
                'starting audio call and image speed test. check the screen and pbx logs for actual frame rate.');
        }catch(\Throwable $e){
            error_log('SCCP media player: '.$e->getMessage());
            return $ctx['text']('media player','frame test could not start.');
        }
    }
    return $ctx['menu']('media player',[
        ['audio + video speed test',$ctx['route'](['op'=>'benchmark'])]
    ]);
};
