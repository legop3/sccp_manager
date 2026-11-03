<?php
return static function(array $ctx,array $q) {
    if(($q['op']??'')==='speak'){
        $wait=$ctx['cooldown'](10);
        if($wait>0)return $ctx['text']('Talking Clock','Try again in '.$wait.' seconds.');
        try {
            $ctx['clockCall']();
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
