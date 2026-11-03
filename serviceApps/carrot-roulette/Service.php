<?php
return static function(array $ctx,array $q) {
    if (($q['op']??'')!=='roll')
        return $ctx['menu']('Carrot Roulette',[['Try your luck',$ctx['route'](['op'=>'roll'])]]);
    return $ctx['text']('Carrot Roulette',random_int(1,235)===1?
        'YOU FOUND THE CARROT!':'No carrot this time. (1 in 235)');
};
