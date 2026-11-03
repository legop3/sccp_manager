<?php
return static function(array $ctx,array $q) {
    $op=(string)($q['op']??'');
    if(!in_array($op,['cat','dog'],true))return $ctx['menu']('catdog',[
        ['Broadcast a Cat',$ctx['route'](['op'=>'cat'])],
        ['Broadcast a Dog',$ctx['route'](['op'=>'dog'])]
    ]);
    $wait=$ctx['storage']->cooldown($ctx['user'],3600);
    if($wait>0)return $ctx['text']('catdog','Try again in '.(int)ceil($wait/60).' minutes.');
    try {
        $source=require __DIR__.'/AnimalSource.php';
        $image=$source($op);
        [$sent,$total]=$ctx['notifications']->broadcast('Random '.ucfirst($op),'A random '.$op.' has arrived!',$image);
        return $ctx['text']('catdog','Sent to '.$sent.' of '.$total.' phones.');
    }catch(\Throwable $e){
        error_log('SCCP random animal: '.$e->getMessage());
        return $ctx['text']('catdog','Animal image unavailable; please try later.');
    }
};
