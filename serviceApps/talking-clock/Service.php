<?php
return static function(array $ctx,array $q) {
    if(($q['op']??'')==='speak'){
        try {
            $ctx['audio']->call($ctx['config']['format']==='24h'?'sccp-manager-services-clock-24':'sccp-manager-services-clock');
            return $ctx['text']('Talking Clock','Calling to speak the current time.');
        }catch(\Throwable $e){
            error_log('SCCP talking clock: '.$e->getMessage());
            return $ctx['text']('Talking Clock','Audio calling unavailable.');
        }
    }
    return $ctx['menu']('Talking Clock',[
        ['Speak current time',$ctx['route'](['op'=>'speak'])],
        ['Refresh: '.date('g:i:s A'),$ctx['route']()]
    ]);
};
