<?php
// Invoked once per minute by /etc/cron.d/sccp-manager-services.
if(PHP_SAPI!=='cli')exit(1);
ini_set('display_errors','0');
try {
    $bootstrap_settings['freepbx_auth']=false;
    require_once '/etc/freepbx.conf';
    require_once __DIR__.'/../Sccp_manager.class.php';
    \FreePBX::create()->Sccp_manager->phoneservices->tick();
} catch(\Throwable $e) {
    error_log('SCCP service alarm runner: '.$e->getMessage());
    exit(1);
}
