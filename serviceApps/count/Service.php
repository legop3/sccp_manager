<?php
/** Shared global counter. The database lock prevents lost increments. */
return static function(array $ctx,array $q) {
    $op=(string)($q['op']??'');
    $count=$ctx['transaction'](static function(array &$s)use($op) {
        $s['count']=max(0,(int)($s['count']??0));
        if ($op==='increment') $s['count']++;
        return $s['count'];
    });
    return $ctx['menu']('Biggest Number: '.$count,[
        ['Add 1',$ctx['route'](['op'=>'increment'])],
        ['Refresh',$ctx['route']()]
    ]);
};
