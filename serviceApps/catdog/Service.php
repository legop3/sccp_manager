<?php
/**
 * catdog: an interactive phone service for shared cat/dog picture broadcasts.
 * The actual popup, global appearance, navigation and notification history are
 * owned exclusively by SCCP Manager's existing notification pipeline.
 */
return static function(array $ctx,array $query) {
    $settings=$ctx['config'];
    $route=$ctx['route'];
    $menu=$ctx['menu'];
    $storage=$ctx['storage'];
    $user=$ctx['user'];
    $op=(string)($query['op']??'');

    $remaining=static function($seconds) {
        $minutes=(int)ceil(max(0,$seconds)/60);
        if($minutes<=0)return 'Ready now';
        if($minutes<60)return 'Ready in '.$minutes.' min';
        $hours=intdiv($minutes,60);
        $mins=$minutes%60;
        return 'Ready in '.$hours.'h'.($mins?' '.$mins.'m':'');
    };
    $home=static function($notice='')use($settings,$route,$menu,$storage,$user,$remaining) {
        $wait=$storage->cooldownRemaining($user);
        $entries=[];
        if($notice!=='')$entries[]=[substr($notice,0,64),$route()];
        if($wait>0) {
            $entries[] = [$remaining($wait),$route(['op'=>'status'])];
        } else {
            if(!empty($settings['allow_cats']))
                $entries[]=['Send a cat',$route(['op'=>'cat'])];
            if(!empty($settings['allow_dogs']))
                $entries[]=['Send a dog',$route(['op'=>'dog'])];
            if(empty($settings['allow_cats']) && empty($settings['allow_dogs']))
                $entries[]=['Cats and dogs disabled',$route(['op'=>'status'])];
        }
        $entries[]=['Refresh status',$route(['op'=>'status'])];
        $entries[]=['Services',$route(['app'=>'home'])];
        return $menu('catdog',$entries);
    };

    if($op==='' || $op==='status')return $home();

    if(!in_array($op,['cat','dog'],true))return $home('Unknown choice');
    if(($op==='cat' && empty($settings['allow_cats'])) ||
       ($op==='dog' && empty($settings['allow_dogs'])))
        return $home(ucfirst($op).' pictures are disabled');

    $wait=$storage->cooldown($user,(int)$settings['cooldown_minutes']*60);
    if($wait>0)return $home($remaining($wait));

    try {
        $fetch=$ctx['animalSource']??(require __DIR__.'/AnimalSource.php');
        $image=$fetch($op);
    }catch(\Throwable $e) {
        // No picture was delivered or enqueued, so the user can retry.
        $storage->releaseCooldown($user);
        error_log('SCCP catdog image fetch: '.$e->getMessage());
        return $menu('catdog',[
            ['Picture unavailable',$route()],
            ['Try '.($op==='cat'?'cat':'dog').' again',$route(['op'=>$op])],
            ['Services',$route(['app'=>'home'])]
        ]);
    }

    try {
        [$accepted,$connected]=$ctx['notifications']->broadcast(
            'Random '.ucfirst($op),
            'A random '.$op.' has arrived!',
            $image,$settings['delivery'],$ctx['device']
        );
    }catch(\Throwable $e) {
        // Notification errors can occur *after* the queue has saved an entry.
        // Do not refund the cooldown and create duplicate notifications.
        error_log('SCCP catdog delivery: '.$e->getMessage());
        return $home('Delivery error; check notifications');
    }

    if($connected===0) {
        // No recipient exists: nothing was placed in the queue.
        $storage->releaseCooldown($user);
        return $home('No connected target phones');
    }
    if($accepted===$connected) {
        return $home(ucfirst($op).' sent to '.$accepted.' phone'.($accepted===1?'':'s'));
    }
    // The shared queue retains entries even when a handset rejects its HTTP
    // push. Never call this an unsent picture or offer an immediate retry.
    return $home('Accepted '.$accepted.'/'.$connected.'; check inbox');
};
