<?php
// Standalone regression test; no FreePBX server or external services required.
error_reporting(E_ALL);
require __DIR__ . '/../sccpManClasses/phonecontent.class.php';
use FreePBX\modules\Sccp_manager\phonecontent;

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
    check($content->baseUrl() === 'http://192.168.0.25/sccp-manager', 'PBX URL');
    $content->publishDirectory();
    check(strpos(file_get_contents($base . '/web/directory.xml'), 'CiscoIPPhoneDirectory') !== false,
        'Empty directory');
    $content->saveContact('', 'Alice & Bob', '150');
    $content->saveContact('', 'Garage', '*80');
    $xml = simplexml_load_file($base . '/web/directory.xml');
    check(count($xml->DirectoryEntry) === 2, 'Directory entries');
    check((string) $xml->DirectoryEntry[0]->Name === 'Alice & Bob', 'XML escaping');
    $id = $content->contacts()[0]['id'];
    $content->saveContact($id, 'Alice', '151');
    check((string) simplexml_load_file($base . '/web/directory.xml')->DirectoryEntry[0]->Telephone === '151',
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
    check(count($parent->dbinterface->writes) === 0, 'No implicit DB mutations');
    $changed = $content->syncUrls();
    check(count($changed) === 2, 'Autofill both phone URLs');
    check($parent->sccpvalues['dev_directoryURL']['data'] ===
        'http://192.168.0.25/sccp-manager/directory.xml', 'Directory URL');
    $parent->sccpvalues['dev_servicesURL']['data'] = 'http://my-service.example/xml';
    check(count($content->syncUrls()) === 0, 'Never overwrite custom service URL');
    check(count($content->syncUrls(true)) === 1, 'Explicit activation should replace custom service URL');
    check($parent->sccpvalues['dev_servicesURL']['data'] ===
        'http://192.168.0.25/sccp-manager/services.xml', 'Managed service URL is active');
    check(count($content->restoreUrls()) === 2, 'Restore original URL settings');
    check($parent->sccpvalues['dev_servicesURL']['data'] === 'http://my-service.example/xml',
        'Prior manually configured URL recovered');
    $content->enableWallpapers();
    check($parent->sccpvalues['backgroundImageAccess']['data'] === 'on', 'Image setting');
    $parent->setConfig('phonecontent_wallpapers', array(
        array('id' => '0123456789abcdef', 'name' => 'Beach')));
    $content->publishWallpapers();
    $wallpapers = simplexml_load_file($base . '/tftp/Desktops/320x212x16/List.xml');
    check((string) $wallpapers->ImageItem[0]['URL'] ===
        'TFTP:Desktops/320x212x16/PC-0123456789abcdef.png', 'Wallpaper index');
    $parent->setConfig('phonecontent_ringtones', array(
        array('id' => 'abcdef0123456789', 'name' => 'Doorbell', 'ext' => 'wav')));
    $content->publishRingtones();
    $rings = simplexml_load_file($base . '/tftp/RingList.xml');
    check((string) $rings->Ring[0]->FileName === 'PC-abcdef0123456789.raw', 'RingList index');
    // Preserve existing manually-managed catalogs rather than deleting them.
    file_put_contents($base . '/tftp/RingList.xml', '<CiscoIPPhoneRingList/>');
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
