<?php
// Standalone regression test; no FreePBX server or external services required.
error_reporting(E_ALL);
require __DIR__ . '/../sccpManClasses/phonecontent.class.php';
require __DIR__ . '/../sccpManClasses/phonemenus.class.php';
use FreePBX\modules\Sccp_manager\phonecontent;
use FreePBX\modules\Sccp_manager\phonemenus;

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
class FakeContentDb {
    public $writes = array();
    public function write($table, $data, $mode) {
        $this->writes[] = array($table, $data, $mode);
    }
}
class FakeContentParent {
    private $config = array();
    public $sccpvalues = array();
    public $dbinterface;
    public $phonecontent;
    public $phonemenus;
    public function __construct() {
        $this->dbinterface = new FakeContentDb();
    }
    public function getConfig($key) { return $this->config[$key] ?? null; }
    public function setConfig($key, $value) { $this->config[$key] = $value; }
    public function vpnSettings() { return array('pbx_ip' => '192.168.0.25'); }
}
$base = sys_get_temp_dir() . '/sccp-content-test-' . bin2hex(random_bytes(4));
mkdir($base);
mkdir($base . '/tftp');
mkdir($base . '/web');
try {
    $parent = new FakeContentParent();
    // FreePBX AMPWEBROOT already IS /var/www/html. The old code mistakenly
    // took dirname(), writing the directory outside Apache's document root.
    $GLOBALS['amp_conf']['AMPWEBROOT'] = $base . '/web';
    $defaultContent = new phonecontent($parent);
    check($defaultContent->publicPath() === $base . '/web/sccp-manager',
        'Directory must be inside the actual web root');
    $content = new phonecontent($parent, $base . '/tftp', $base . '/web');
    $parent->phonecontent = $content;
    $parent->phonemenus = new phonemenus($parent);
    check($content->baseUrl() === 'http://192.168.0.25/sccp-manager', 'PBX URL');
    $content->publishDirectory();
    check(is_file($base . '/web/phone.php'), 'PHP XML service endpoint must exist');
    check(strpos(file_get_contents($base . '/web/phone.php'), 'Content-Type: text/xml') !== false, 'Cisco XML MIME type');
    check(strpos(file_get_contents($base . '/web/directory.xml'), 'CiscoIPPhoneMenu') !== false,
        'Empty directory');
    $content->saveContact('', 'Alice & Bob', '150');
    $content->saveContact('', 'Garage', '*80');
    $xml = simplexml_load_file($base . '/web/contacts-1.xml');
    check(count($xml->DirectoryEntry) === 2, 'Directory entries');
    $menu = simplexml_load_file($base . '/web/directory.xml');
    check(count($menu->MenuItem) === 1, 'Built-in call history must not be duplicated');
    check((string) $menu->MenuItem[0]->Name === 'Phone Book', 'Phone book menu first');
    check((string) $menu->MenuItem[0]->URL === 'http://192.168.0.25/sccp-manager/phone.php?doc=contacts-1',
        'Directory menu links to contacts');

    check((string) $xml->DirectoryEntry[0]->Name === 'Alice & Bob', 'XML escaping');
    $id = $content->contacts()[0]['id'];
    $content->saveContact($id, 'Alice', '151');
    check((string) simplexml_load_file($base . '/web/contacts-1.xml')->DirectoryEntry[0]->Telephone === '151',
        'Edit contacts');
    try {
        $content->saveContact('', 'Unsafe', '123</Telephone>');
        throw new RuntimeException('Rejected unsafe dial string was accepted');
    } catch (InvalidArgumentException $ok) {}
    $content->removeContact($id);
    check(count($content->contacts()) === 1, 'Delete contacts');
    for ($i=0;$i<33;$i++) $content->saveContact('', 'Person ' . $i, (string) (200 + $i));
    $menu = simplexml_load_file($base . '/web/directory.xml');
    check($menu->getName() === 'CiscoIPPhoneMenu', 'Directory pagination');
    check(file_exists($base . '/web/contacts-2.xml'), 'Second contact page');
    check(count($menu->MenuItem) === 1, 'Top-level menu is independent from contact pagination');
    $book = simplexml_load_file($base . '/web/phonebook.xml');
    check(count($book->MenuItem) === 2, 'Expanded phone book indexes both pages');
    check((string) $menu->MenuItem[0]->URL === 'http://192.168.0.25/sccp-manager/phone.php?doc=phonebook',
        'Phone Book entry must not point back to directory itself');

    // Every visible Cisco XML Title/Prompt/Name must be configurable. The
    // settings update must republish immediately without changing phone URLs
    // or regenerating signed SEP provisioning.
    $labels = $content->directoryLabels();
    check($labels['menu_entry'] === 'Phone Book', 'Existing directory label default');
    $custom = array(
        'menu_title' => 'My directories',
        'menu_prompt' => 'Choose a list',
        'menu_entry' => 'People & Teams',
        'contacts_title' => 'Family & Friends',
        'contacts_prompt' => 'Pick <one>',
        'services_title' => 'Tools',
        'services_prompt' => 'Choose an action',
        'services_entry' => 'Call contacts'
    );
    $contactCount = count($content->contacts());
    $content->saveDirectoryLabels($custom);
    check($content->directoryLabels() === $custom, 'Custom directory labels persisted');
    check(count($content->contacts()) === $contactCount, 'Customizing text did not modify contacts');
    $menu = simplexml_load_file($base . '/web/directory.xml');
    check((string) $menu->Title === 'My directories', 'Custom menu title');
    check((string) $menu->Prompt === 'Choose a list', 'Custom menu prompt');
    check((string) $menu->MenuItem[0]->Name === 'People & Teams',
        'Custom menu entry label persisted');
    $book = simplexml_load_file($base . '/web/phonebook.xml');
    check((string) $book->MenuItem[0]->Name === 'People & Teams 1-30',
        'Custom entry label used for paginated phone book');
    $page = simplexml_load_file($base . '/web/contacts-1.xml');
    check((string) $page->Title === 'Family & Friends', 'Custom contact title');
    check((string) $page->Prompt === 'Pick <one>', 'Escaped custom prompt');
    $services = simplexml_load_file($base . '/web/services.xml');
    check((string) $services->Title === 'Tools', 'Custom services title');
    check((string) $services->Prompt === 'Choose an action', 'Custom services prompt');
    check((string) $services->MenuItem[0]->Name === 'Call contacts', 'Custom services entry');
    check(strpos(file_get_contents($base . '/web/directory.xml'), 'People &amp; Teams') !== false,
        'Special characters safely escaped in Cisco XML');
    try {
        $bad = $custom;
        $bad['menu_entry'] = str_repeat('x', 61);
        $content->saveDirectoryLabels($bad);
        throw new RuntimeException('Overlong menu label accepted');
    } catch (InvalidArgumentException $ok) {}
    check($content->directoryLabels() === $custom, 'Invalid update changed saved text');
    try {
        $bad = $custom;
        $bad['contacts_title'] = "Foo\nBar";
        $content->saveDirectoryLabels($bad);
        throw new RuntimeException('Control character accepted');
    } catch (InvalidArgumentException $ok) {}
    check($content->directoryLabels() === $custom, 'Invalid control character changed saved text');
    check(count($parent->dbinterface->writes) === 0, 'No implicit DB mutations');
    $changed = $content->syncUrls();
    check(count($changed) === 2, 'Autofill both phone URLs');
    check($parent->sccpvalues['dev_directoryURL']['data'] ===
        'http://192.168.0.25/sccp-manager/phone.php?doc=directory', 'Directory URL');
    $parent->sccpvalues['dev_servicesURL']['data'] = 'http://my-service.example/xml';
    check(count($content->syncUrls()) === 0, 'Never overwrite custom service URL');
    check(count($content->syncUrls(true)) === 1, 'Explicit activation should replace custom service URL');
    check($parent->sccpvalues['dev_servicesURL']['data'] ===
        'http://192.168.0.25/sccp-manager/phone.php?doc=services', 'Managed service URL is active');
    check(count($content->restoreUrls()) === 2, 'Restore original URL settings');
    check($parent->sccpvalues['dev_servicesURL']['data'] === 'http://my-service.example/xml',
        'Prior manually configured URL recovered');
    $content->enableWallpapers();
    check($parent->sccpvalues['backgroundImageAccess']['data'] === 'on', 'Image setting');
    if (function_exists('imagecreatetruecolor')) {
        $sample = imagecreatetruecolor(16, 16);
        $render = new ReflectionMethod(phonecontent::class, 'renderPng');
        $png = $render->invoke($content, $sample, 320, 212);
        imagedestroy($sample);
        check(substr($png, 1, 3) === 'PNG', 'PNG output signature');
        check(ord($png[25]) === 2, 'Wallpaper PNG must use opaque RGB, not RGBA');
        $imageInfo = getimagesizefromstring($png);
        check($imageInfo[0] === 320 && $imageInfo[1] === 212, 'Cisco 7945G output dimensions');
    }
    $parent->setConfig('phonecontent_wallpapers', array(
        array('id' => '0123456789abcdef', 'name' => 'Beach')));
    $content->publishWallpapers();
    $wallpapers = simplexml_load_file($base . '/tftp/Desktops/320x212x16/List.xml');
    check((string) $wallpapers->ImageItem[0]['URL'] ===
        'TFTP:Desktops/320x212x16/PC-0123456789abcdef.png', 'Wallpaper index');
    $parent->setConfig('phonecontent_ringtones', array(
        array('id' => 'abcdef0123456789', 'name' => 'Doorbell', 'ext' => 'wav')));
    $content->publishRingtones();
    $rings = simplexml_load_file($base . '/tftp/ringlist.xml');
    check((string) $rings->Ring[0]->FileName === 'PC-abcdef0123456789.raw', 'RingList index');
    // Preserve existing manually-managed catalogs rather than deleting them.
    file_put_contents($base . '/tftp/ringlist.xml', '<CiscoIPPhoneRingList/>');
    try {
        $content->publishRingtones();
        throw new RuntimeException('Unmanaged RingList was overwritten');
    } catch (RuntimeException $e) {
        check(strpos($e->getMessage(), 'not managed') !== false, 'Index conflict error');
    }
    echo "Phone content integration tests passed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($base);
}
