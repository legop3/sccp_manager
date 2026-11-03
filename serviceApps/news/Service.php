<?php
/** Existing news behavior, now owned by the manifest-based service. */
return static function(array $ctx,array $q) {
    $legacy=$ctx['legacy'];
    $device=$ctx['device'];
    $line=$ctx['user'];
    $route=$ctx['route'];
    $app='news';
    $op=(string)($q['op']??'');
    ob_start();
    try {
        $ctxSettings=$ctx['config'];
        $render=static function()use($legacy,$device,$line,$route,$app,$op,$q,$ctxSettings) {
        if($app==='news') {
            $feed=(string)$ctxSettings['feed'];
            $raw=$legacy->fetch($feed);
            libxml_use_internal_errors(true);
            $rss=$raw!==''?simplexml_load_string($raw,'SimpleXMLElement',LIBXML_NONET):false;
            if(!$rss || !isset($rss->channel->item)){$legacy->xml('News','Feed unavailable.');return;}
            $items=$rss->channel->item;
            if($op==='story') {
                $n=filter_var($q['id']??null,FILTER_VALIDATE_INT);
                if($n===false||$n===null||$n<0||$n>=min(15,count($items))){$legacy->xml('News','Story unavailable.');return;}
                $legacy->xml('News',substr((string)$items[$n]->title."\n\n".strip_tags((string)$items[$n]->description),0,950));return;
            }
            $entries=[];
            foreach($items as $i=>$story){if($i>=15)break;$entries[]=[(string)$story->title,$route(['app'=>'news','op'=>'story','id'=>$i])];}
            $legacy->menu('News',$entries);return;
        }
        };
        $render();
        return ob_get_clean();
    } catch(\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
};
