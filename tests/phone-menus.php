<?php
// Standalone global-menu regression test. Run: php tests/phone-menus.php
error_reporting(E_ALL);
require_once __DIR__ . '/../sccpManClasses/phonecontent.class.php';
require_once __DIR__ . '/../sccpManClasses/phonemenus.class.php';

use FreePBX\modules\Sccp_manager\phonecontent;
use FreePBX\modules\Sccp_manager\phonemenus;

function ensure($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
}

class MenusParent {
    private $settings = array();
    public $phonecontent;
    public $phonemenus;
    public function getConfig($key) { return $this->settings[$key] ?? null; }
    public function setConfig($key, $value) { $this->settings[$key] = $value; }
    public function vpnSettings() { return array('pbx_ip' => '192.168.0.25'); }
}

$tmp = sys_get_temp_dir() . '/sccp-menus-' . bin2hex(random_bytes(6));
mkdir($tmp);
try {
    $parent = new MenusParent();
    $parent->phonecontent = new phonecontent($parent, $tmp, $tmp);
    $parent->phonemenus = new phonemenus($parent);
    $menus = $parent->phonemenus;
    ensure($menus->config()['enabled'] === false, 'Must not change existing phones on upgrade');
    $parent->phonecontent->publishDirectory();
    ensure(is_file($tmp . '/directory.xml'), 'Canonical Directories XML must exist');
    ensure(!is_file($tmp . '/phonebook.xml'), 'Do not create extra Phone Book page');
    ensure(is_file($tmp . '/services.xml'), 'Legacy Services XML');
    ensure(strpos(file_get_contents($tmp . '/phone.php'), 'doc=') === false,
        'Endpoint should be generic rather than hardcoded to a single contact page');

    $template = __DIR__ . '/../conf/SEP0000000000.cnf.xml_79df_template';
    $phone = simplexml_load_file($template);
    $menus->applyProvisioning($phone);
    ensure(!isset($phone->phoneServices), 'Disabled mode must not inject phone services');

    $data = $menus->config();
    $data['enabled'] = true;
    $data['services'] = array(
        array('name' => 'Information', 'type' => 'text', 'value' => 'Hello <friends> & everyone'),
        array('name' => 'Website', 'type' => 'url', 'value' => 'http://example.com/phone.xml')
    );
    $data['submenus'] = array(array('id'=>'friends','name'=>'Extra Pages','items'=>array(
        array('name' => 'Call help', 'type' => 'dial', 'value' => '*80')
    )));
    $menus->save(json_encode($data));
    ensure($menus->config()['enabled'] === true, 'Enabled saved');
    ensure(is_file($tmp . '/pc-sub-friends.xml'), 'Submenu generated');
    ensure(is_file($tmp . '/pc-text-services-0.xml'), 'Text page generated');
    $dir = simplexml_load_file($tmp . '/directory.xml');
    ensure(count($dir->MenuItem) === 2, 'Direct directory must contain Favorites and All Extensions');
    ensure((string)$dir->MenuItem[0]->Name === 'Favorites' &&
        (string)$dir->MenuItem[1]->Name === 'All Extensions',
        'Physical Directories button screen must never open a Phone Book intermediary');
    $text = simplexml_load_file($tmp . '/pc-text-services-0.xml');
    ensure((string) $text->Text === 'Hello <friends> & everyone', 'Text page escaping');
    $sub = simplexml_load_file($tmp . '/pc-sub-friends.xml');
    ensure((string) $sub->MenuItem[0]->URL === 'Dial:*80', 'Dial action URL');

    $phone = simplexml_load_file($template);
    $menus->applyProvisioning($phone);
    ensure((string) $phone->phoneServices->provisioning === '0', 'Internal mode');
    ensure(count($phone->phoneServices->phoneService) === 4, '1 Directories + 2 Services + 1 Messages');
    ensure((string) $phone->phoneServices->phoneService[0]['type'] === '1', 'Directory category');
    ensure((string) $phone->phoneServices->phoneService[3]['type'] === '2', 'Voicemail category');
    ensure((string) $phone->phoneServices->phoneService[3]->url === 'Application:Cisco/Voicemail',
        'Preserve native voicemail');
    ensure((string) $phone->phoneServices->phoneService[0]->url ===
        'http://192.168.0.25/sccp-manager/phone.php?doc=directory',
        'Physical Directories button provisions the same direct root as the generated XML');
    ensure(strpos($phone->asXML(), 'Application:Cisco/MissedCalls') === false,
        'Call history entries really removed');
    ensure((string) $phone->devicePool->callManagerGroup->members->member->callManager->ports->ethernetPhonePort === '2000',
        'Other phone configuration must not be altered');

    foreach (array(
        array('enabled' => true, 'services' => array(), 'messages' => $data['messages'], 'submenus' => array()),
        array_replace($data, array('messages' => array())),
        array_replace($data, array('services' => array(array('name'=>'Bad', 'type'=>'url', 'value'=>'file:///etc/passwd')))),
        array_replace($data, array('submenus' => array(array('id'=>'../escape', 'name'=>'No', 'items'=>array()))))
    ) as $bad) {
        try {
            $menus->save(json_encode($bad));
            throw new RuntimeException('Invalid menu configuration was accepted.');
        } catch (InvalidArgumentException $ok) {}
    }
    ensure($menus->config()['enabled'] === true, 'Invalid updates must not change saved menus');
    // The signed SEP config is regenerated by normal Save Menus, and once
    // after upgrade on the next Phone Content visit: no second button.
    $menusView = file_get_contents(__DIR__ . '/../views/content.menus.php');
    $content = file_get_contents(__DIR__ . '/../sccpManClasses/phonecontent.class.php');
    $bmo = file_get_contents(__DIR__ . '/../sccpManTraits/bmoFunctions.php');
    $module = file_get_contents(__DIR__ . '/../Sccp_manager.class.php');
    ensure(strpos($menusView, 'publish_buttons') === false &&
           strpos($content, "case 'publish_buttons'") === false &&
           strpos($bmo, "'publish_buttons'") === false,
           'No redundant manual phone configuration publishing action remains');
    ensure(strpos($bmo, "'save_menus'") !== false &&
           strpos($bmo, 'phoneContentRegenerate(false)') !== false,
           'Saving menus already automatically regenerates signed phone configs');
    ensure(strpos($module, "phonecontent_directories_signed_1736") !== false &&
           strpos($module, "phoneContentRegenerate(false)") !== false,
           'Upgraded button URL is automatically signed on the next Phone Content visit');
    ensure(strpos($menusView, 'Saving menus automatically regenerates') !== false,
           'Menus editor accurately describes automatic provisioning');
    $data['enabled'] = false;
    $menus->save(json_encode($data));
    $phone = simplexml_load_file($template);
    $menus->applyProvisioning($phone);
    ensure(!isset($phone->phoneServices), 'Disabling custom menus restores legacy provisioning');
    echo "Phone menu integration tests passed.\n";
} finally {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($tmp);
}
