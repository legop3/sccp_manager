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
    ensure(is_file($tmp . '/phonebook.xml'), 'Phonebook must be independent of directory menu');
    ensure(is_file($tmp . '/services.xml'), 'Legacy Services XML');
    ensure(strpos(file_get_contents($tmp . '/phone.php'), 'doc=') === false,
        'Endpoint should be generic rather than hardcoded to a single contact page');

    $template = __DIR__ . '/../conf/SEP0000000000.cnf.xml_79df_template';
    $phone = simplexml_load_file($template);
    $menus->applyProvisioning($phone);
    ensure(!isset($phone->phoneServices), 'Disabled mode must not inject phone services');

    $data = $menus->config();
    $data['enabled'] = true;
    $data['directories'] = array(
        array('name' => 'Friends & Family', 'type' => 'phonebook', 'value' => ''),
        array('name' => 'More', 'type' => 'submenu', 'value' => 'friends')
    );
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
    ensure(count($dir->MenuItem) === 2, 'Editable directory entry count');
    ensure((string) $dir->MenuItem[0]->Name === 'Friends & Family', 'Directory name escaping');
    $text = simplexml_load_file($tmp . '/pc-text-services-0.xml');
    ensure((string) $text->Text === 'Hello <friends> & everyone', 'Text page escaping');
    $sub = simplexml_load_file($tmp . '/pc-sub-friends.xml');
    ensure((string) $sub->MenuItem[0]->URL === 'Dial:*80', 'Dial action URL');

    $phone = simplexml_load_file($template);
    $menus->applyProvisioning($phone);
    ensure((string) $phone->phoneServices->provisioning === '0', 'Internal mode');
    ensure(count($phone->phoneServices->phoneService) === 5, '2 directories + 2 services + 1 voicemail');
    ensure((string) $phone->phoneServices->phoneService[0]['type'] === '1', 'Directory category');
    ensure((string) $phone->phoneServices->phoneService[4]['type'] === '2', 'Voicemail category');
    ensure((string) $phone->phoneServices->phoneService[4]->url === 'Application:Cisco/Voicemail',
        'Preserve native voicemail');
    ensure(strpos($phone->asXML(), 'Application:Cisco/MissedCalls') === false,
        'Call history entries really removed');
    ensure((string) $phone->devicePool->callManagerGroup->members->member->callManager->ports->ethernetPhonePort === '2000',
        'Other phone configuration must not be altered');

    foreach (array(
        array('enabled' => true, 'directories' => array(), 'services' => $data['services'], 'messages' => $data['messages'], 'submenus' => array()),
        array_replace($data, array('messages' => array())),
        array_replace($data, array('directories' => array(array('name'=>'Bad', 'type'=>'url', 'value'=>'file:///etc/passwd')))),
        array_replace($data, array('submenus' => array(array('id'=>'../escape', 'name'=>'No', 'items'=>array()))))
    ) as $bad) {
        try {
            $menus->save(json_encode($bad));
            throw new RuntimeException('Invalid menu configuration was accepted.');
        } catch (InvalidArgumentException $ok) {}
    }
    ensure($menus->config()['enabled'] === true, 'Invalid updates must not change saved menus');
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
