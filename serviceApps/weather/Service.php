<?php
/** Existing weather behavior, now owned by the manifest-based service. */
return static function(array $ctx,array $q) {
    $legacy=$ctx['legacy'];
    $device=$ctx['device'];
    $line=$ctx['user'];
    $route=$ctx['route'];
    $app='weather';
    $op=(string)($q['op']??'');
    ob_start();
    try {
        $ctxSettings=$ctx['config'];
        $render=static function()use($legacy,$device,$line,$route,$app,$op,$q,$ctxSettings) {
        if($app==='weather') {
            $settings=$ctxSettings;
            $lat=(float)($settings['lat']??39.9612);$lon=(float)($settings['lon']??-82.9988);
            $api='https://api.open-meteo.com/v1/forecast?'.http_build_query([
                'latitude'=>$lat,'longitude'=>$lon,'current'=>'temperature_2m,relative_humidity_2m,wind_speed_10m',
                'daily'=>'temperature_2m_max,temperature_2m_min','temperature_unit'=>'fahrenheit',
                'wind_speed_unit'=>'mph','timezone'=>'auto','forecast_days'=>4]);
            $data=json_decode($legacy->fetch($api),true);
            if(!is_array($data)||!isset($data['current'])){$legacy->xml('Weather','Forecast unavailable.');return;}
            $c=$data['current'];$d=$data['daily']??[];
            $rows=[(string)($settings['name']??'Weather'),'Current: '.($c['temperature_2m']??'?').' F',
                'Humidity: '.($c['relative_humidity_2m']??'?').'%',
                'Wind: '.($c['wind_speed_10m']??'?').' mph','Forecast:'];
            foreach(($d['time']??[]) as $i=>$date)$rows[]=$date.' '.($d['temperature_2m_min'][$i]??'?').' - '.($d['temperature_2m_max'][$i]??'?').' F';
            $legacy->xml('Weather',implode("\n",$rows));return;
        }
        };
        $render();
        return ob_get_clean();
    } catch(\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
};
