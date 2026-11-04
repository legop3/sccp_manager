<?php
/**
 * One global, persistent, increment-only counter for all SCCP handsets.
 * Existing data stays in serviceAppStorage('count'), outside module releases.
 */
return static function(array $ctx,array $q) {
    $op=(string)($q['op']??'');
    if($op!=='' && $op!=='increment')
        return $ctx['text']('Count','Unknown counter action.');

    $count=$ctx['storage']->transaction(static function(array &$state)use($op) {
        $value=$state['count']??0;
        // Do not silently reset persistent data if it is malformed.
        if(!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/D',$value)))
            throw new \RuntimeException('Invalid saved counter value');
        $checked=filter_var($value,FILTER_VALIDATE_INT);
        if($checked===false)
            throw new \RuntimeException('Counter value is out of range');
        $value=$checked;
        if($value<0)throw new \RuntimeException('Invalid saved counter value');
        if($op==='increment') {
            if($value===PHP_INT_MAX)
                throw new \RuntimeException('Counter has reached the maximum supported value');
            ++$value;
            $state['count']=$value;
        }
        return $value;
    });

    return $ctx['menu']('Count: '.$count,[
        ['Add 1',$ctx['route'](['op'=>'increment'])],
        ['Refresh',$ctx['route']()]
    ]);
};
