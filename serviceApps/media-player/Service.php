<?php
/**
 * Proof of transport: genuine SCCP/Asterisk audio call alongside quick
 * XML image pushes. No claim of true video decoding or synchronized playback.
 */
return static function(array $ctx,array $q) {
    if(($q['op']??'')==='adaptive') {
        try {
            $ctx['media']->adaptiveTest();
            return $ctx['text']('media player',
                'starting an adaptive video test. each finished image download requests the next frame.');
        } catch(\Throwable $e) {
            error_log('SCCP adaptive media: '.$e->getMessage());
            return $ctx['text']('media player','adaptive test unavailable: '.strtolower($e->getMessage()));
        }
    }
    return $ctx['menu']('media player',[
        ['play adaptive video test',$ctx['route'](['op'=>'adaptive'])]
    ]);
};
