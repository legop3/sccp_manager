<?php
return static function(array $ctx,array $q) {
    $op=(string)($q['op']??'');
    if(!in_array($op,['cat','dog'],true))return $ctx['menu']('Random Animals',[
        ['Broadcast a Cat',$ctx['route'](['op'=>'cat'])],
        ['Broadcast a Dog',$ctx['route'](['op'=>'dog'])]
    ]);
    $wait=$ctx['cooldown'](3600);
    if($wait>0)return $ctx['text']('Random Animals','Try again in '.(int)ceil($wait/60).' minutes.');
    try {
        $image=$ctx['animal']($op);
        [$sent,$total]=$ctx['broadcast']('Random '.ucfirst($op),'A random '.$op.' has arrived!',$image);
        return $ctx['text']('Random Animals','Sent to '.$sent.' of '.$total.' phones.');
    }catch(\Throwable $e){
        error_log('SCCP random animal: '.$e->getMessage());
        return $ctx['text']('Random Animals','Animal image unavailable; please try later.');
    }
};
