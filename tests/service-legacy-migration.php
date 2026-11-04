<?php
/** Removed trinkets no longer register or run; keep native voicemail intact. */
require_once __DIR__.'/../sccpManClasses/serviceRegistry.class.php';
require_once __DIR__.'/../sccpManClasses/phonemenus.class.php';
use FreePBX\modules\Sccp_manager\serviceRegistry;
use FreePBX\modules\Sccp_manager\phonemenus;

$registry=new serviceRegistry();
$apps=$registry->manifests();
foreach(['messages','alarms','games','news'] as $removed) {
    if(isset($apps[$removed]))
        throw new RuntimeException('Removed service still registered: '.$removed);
    if(file_exists(__DIR__.'/../serviceApps/'.$removed.'/Service.php'))
        throw new RuntimeException('Removed service still deployed: '.$removed);
}
if(!isset($apps['weather']))throw new RuntimeException('Weather was removed');
$menus=new phonemenus(new class {
    public function getConfig($key){return null;}
    public $phonecontent;
    public function __construct() {
        $this->phonecontent=new class {
            public function directoryLabels(){return ['services_title'=>'Services'];}
        };
    }
});
$defaults=$menus->defaults();
if(($defaults['messages'][0]['type']??'')!=='voicemail')
    throw new RuntimeException('Native Messages button voicemail was changed');
foreach(['messages','alarms','games','news'] as $removed)
    if(array_key_exists($removed,$menus->serviceFlags()))
        throw new RuntimeException('Deleted service still appears in GUI: '.$removed);
$entry=file_get_contents(__DIR__.'/../sccpManClasses/phoneservices.class.php');
if(str_contains($entry,"serviceApps/alarms/Tick.php") ||
    str_contains($entry,"function tick("))
    throw new RuntimeException('Removed alarm scheduler still callable');
$install=file_get_contents(__DIR__.'/../install.php');
if(!str_contains($install,'@unlink($oldServicesCron)'))
    throw new RuntimeException('Old alarm cron cleanup missing');
if(str_contains($install,'$tick=__DIR__'))
    throw new RuntimeException('Old alarm cron still installed');
echo "Retired services absent, native voicemail retained, stale cron cleaned up\n";
