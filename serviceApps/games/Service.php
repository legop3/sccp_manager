<?php
/** Existing games behavior, now owned by the manifest-based service. */
return static function(array $ctx,array $q) {
    $legacy=$ctx['legacy'];
    $device=$ctx['device'];
    $line=$ctx['user'];
    $route=$ctx['route'];
    $app='games';
    $op=(string)($q['op']??'');
    ob_start();
    try {
        $render=static function()use($legacy,$device,$line,$route,$app,$op,$q) {
        if($app==='games') {
            if($op==='coin'){$legacy->xml('Coin Flip',random_int(0,1)?'Heads':'Tails');return;}
            if($op==='dice'){$legacy->xml('Dice Roll','You rolled '.random_int(1,6));return;}
            if($op==='eight'){$answers=['Yes','No','Probably','Ask again later','Definitely','Unlikely','Outlook good','Cannot predict now'];$legacy->xml('Magic 8 Ball',$answers[random_int(0,count($answers)-1)]);return;}
            $trivia=[
                ['How many days in a leap year?',['365','366','364','367'],1],
                ['Which planet is known as the Red Planet?',['Venus','Mars','Saturn','Mercury'],1],
                ['How many sides does a hexagon have?',['5','6','7','8'],1],
                ['What color do blue and yellow make?',['Purple','Orange','Green','Red'],2],
                ['Which ocean is the largest?',['Atlantic','Indian','Arctic','Pacific'],3],
                ['What is 12 times 12?',['124','144','142','154'],1],
                ['Which gas do plants absorb?',['Oxygen','Helium','Carbon Dioxide','Nitrogen'],2],
                ['How many minutes are in an hour?',['30','90','100','60'],3]
            ];
            if($op==='trivia'){
                $n=isset($q['id']) ? filter_var($q['id'],FILTER_VALIDATE_INT) : random_int(0,count($trivia)-1);
                if($n===false || $n<0 || $n>=count($trivia)){$legacy->xml('Trivia','Question unavailable.');return;}
                if(isset($q['answer'])){
                    $a=filter_var($q['answer'],FILTER_VALIDATE_INT);
                    $legacy->xml('Trivia',$a===$trivia[$n][2]?'Correct!':'Not quite. Answer: '.$trivia[$n][1][$trivia[$n][2]]);
                    return;
                }
                $entries=[];
                foreach($trivia[$n][1] as $a=>$choice)$entries[]=[$choice,$route(['app'=>'games','op'=>'trivia','id'=>$n,'answer'=>$a])];
                $legacy->menu('Trivia: '.$trivia[$n][0],$entries);return;
            }
            if($op==='ttt-start') {
                $entries=[];
                foreach($legacy->recipients($device) as $other=>$info)
                    $entries[]=['Extension '.$info['line'],$route(['app'=>'games','op'=>'ttt-new','other'=>$other])];
                if(!$entries){$legacy->xml('Tic-Tac-Toe','No other SCCP phones connected.');return;}
                $legacy->menu('Challenge a phone',$entries);return;
            }
            if($op==='ttt-new') {
                $other=(string)($q['other']??'');
                if(!isset($legacy->recipients($device)[$other])){$legacy->xml('Tic-Tac-Toe','Other phone unavailable.');return;}
                $pair=[$device,$other];sort($pair,SORT_STRING);
                $id=implode('-',$pair);
                $legacy->transact(function(&$s)use($id,$device,$other){
                    $s['games'][$id]=['players'=>[$device,$other],'board'=>array_fill(0,9,'-'),
                        'turn'=>$device,'winner'=>null,'created'=>time()];
                });
                $legacy->push($other,$legacy->url($other).'&app=games&op=ttt-board&other='.$device);
                $legacy->menu('Tic-Tac-Toe',[['Open Game',$route(['app'=>'games','op'=>'ttt-board','other'=>$other])]]);return;
            }
            if($op==='ttt-board' || $op==='ttt-move') {
                $other=(string)($q['other']??'');
                if(!phonevoicemail::validDevice($other)){$legacy->xml('Tic-Tac-Toe','Invalid opponent.');return;}
                $pair=[$device,$other];sort($pair,SORT_STRING);$id=implode('-',$pair);
                $move=($op==='ttt-move')?filter_var($q['cell']??null,FILTER_VALIDATE_INT):null;
                $game=$legacy->transact(function(&$s)use($id,$device,$move){
                    $game=$s['games'][$id]??null;
                    if(!is_array($game) || !in_array($device,$game['players']??[],true))return null;
                    if($move!==null && $move!==false && $move>=0 && $move<9 &&
                        $game['winner']===null && $game['turn']===$device && $game['board'][$move]==='-'){
                        $mark=$device===$game['players'][0]?'X':'O';
                        $game['board'][$move]=$mark;
                        $wins=[[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
                        foreach($wins as $row)if($game['board'][$row[0]]===$mark &&
                            $game['board'][$row[1]]===$mark && $game['board'][$row[2]]===$mark)$game['winner']=$device;
                        if($game['winner']===null && !in_array('-', $game['board'],true))$game['winner']='draw';
                        $game['turn']=$device===$game['players'][0]?$game['players'][1]:$game['players'][0];
                        $s['games'][$id]=$game;
                    }
                    return $game;
                });
                if(!$game){$legacy->xml('Tic-Tac-Toe','No game with that phone.');return;}
                $board=$game['board'];
                $state=$game['winner']==='draw'?'Draw!':($game['winner']!==null
                    ? ($game['winner']===$device?'You win!':'You lose!') :
                    ($game['turn']===$device?'Your turn':'Their turn'));
                $entries=[];
                for($i=0;$i<9;$i++)$entries[]=[
                    'Cell '.($i+1).' ['.$board[$i].']',
                    $route(['app'=>'games','op'=>'ttt-move','other'=>$other,'cell'=>$i])];
                $entries[]=['Refresh',$route(['app'=>'games','op'=>'ttt-board','other'=>$other])];
                $legacy->menu('Tic-Tac-Toe: '.$state,$entries);return;
            }
            $rows=[['Trivia',$route(['app'=>'games','op'=>'trivia'])],
                ['Tic-Tac-Toe',$route(['app'=>'games','op'=>'ttt-start'])],
                ['Flip a Coin',$route(['app'=>'games','op'=>'coin'])],
                ['Roll a Die',$route(['app'=>'games','op'=>'dice'])],
                ['Magic 8 Ball',$route(['app'=>'games','op'=>'eight'])]];
            $legacy->menu('Games',$rows);return;
        }
        };
        $render();
        return ob_get_clean();
    } catch(\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
};
