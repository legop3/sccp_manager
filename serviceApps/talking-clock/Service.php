<?php
return static function(array $ctx,array $q) {
    return $ctx['menu']('Talking Clock',[
        [date('D M j, g:i:s A'),$ctx['route'](['op'=>'show'])],
        ['Refresh',$ctx['route']()]
    ]);
};
