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
class FakeCoreExtensions {
    public $users = array();
    public function getAllUsers() { return $this->users; }
}
class FakeContentParent {
    private $config = array();
    public $sccpvalues = array();
    public $dbinterface;
    public $phonecontent;
    public $phonemenus;
    public $FreePBX;
    public function __construct() {
        $this->dbinterface = new FakeContentDb();
        $this->FreePBX = new \stdClass();
        $this->FreePBX->Core = new FakeCoreExtensions();
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
    // An upgrade must delete the retired intermediate menu artifacts.
    file_put_contents($base . '/web/directory.xml', '<CiscoIPPhoneMenu/>');
    file_put_contents($base . '/web/phonebook.xml', '<CiscoIPPhoneMenu/>');
    $content->publishDirectory();
    check(!is_file($base . '/web/directory.xml') &&
          !is_file($base . '/web/phonebook.xml'),
          'Publishing direct Favorites cleans stale two-level directory files');
    check(is_file($base . '/web/phone.php'), 'PHP XML service endpoint must exist');
    $vmEndpoint = file_get_contents($base . '/web/voicemail.php');
    check(strpos($vmEndpoint, "\$bootstrap_settings['freepbx_auth'] = false;") !== false,
        'Standalone voicemail endpoint explicitly disables FreePBX admin authentication');
    check(strpos($vmEndpoint, "'/admin/modules/sccp_manager/Sccp_manager.class.php'") !== false,
        'Standalone voicemail endpoint loads the BMO module class from the known local path');
    check(strpos($vmEndpoint, "phonevoicemail->handle(\$_GET)") !== false,
        'Voicemail handler still validates the per-phone bearer token');
    check(strpos($vmEndpoint, "header('Content-Length: ' . strlen(\$response));") !== false,
        'HTTP endpoint declares full XML body length before delayed call');
    check(strpos($vmEndpoint, "@\$parsed->loadXML(\$response, LIBXML_NONET)") !== false,
        'Phone-facing output must be validated as XML');
    check(strpos($vmEndpoint, "header('Connection: close');") !== false,
        'Phone can complete XML response before playback starts');
    check(strpos(file_get_contents($base . '/web/phone.php'), 'Content-Type: text/xml') !== false, 'Cisco XML MIME type');
    check(strpos(file_get_contents($base . '/web/contacts-1.xml'), 'CiscoIPPhoneDirectory') !== false &&
        !is_file($base . '/web/directory.xml'), 'Directories opens native Favorites list; no chooser generated');
    // Reproduce the real Cisco 7945G XML Error [4] report: the HTTP body
    // contains ten valid FreePBX extensions but a >32 character prompt.
    $allPhones = array(
        array('extension'=>'111', 'name'=>'Upstairs Phone'),
        array('extension'=>'112', 'name'=>'polycom conference'),
        array('extension'=>'150', 'name'=>'Chime'),
        array('extension'=>'42', 'name'=>'Rotated'),
        array('extension'=>'6144711930', 'name'=>'Home Phone'),
        array('extension'=>'67', 'name'=>'Shizu'),
        array('extension'=>'700', 'name'=>'bignuts'),
        array('extension'=>'7677', 'name'=>'Rossa'),
        array('extension'=>'777', 'name'=>'Roomba Phone'),
        array('extension'=>'899', 'name'=>'iTalkBB')
    );
    $parent->FreePBX->Core->users = $allPhones;
    $longPrompt = 'some of these may be disconnected or not work at all';
    check(strlen($longPrompt) > 32, 'Reproduction prompt exceeds Cisco schema');
    $parent->setConfig('phonecontent_directory_labels', array(
        'extensions_title'=>'All numbers show heres',
        'extensions_prompt'=>$longPrompt,
        'contacts_prompt'=>str_repeat('F', 40)
    ));
    $content->publishDirectory();
    $phoneXml = simplexml_load_file($base . '/web/extensions-1.xml');
    check($phoneXml !== false && $phoneXml->getName() === 'CiscoIPPhoneDirectory',
        'All Extensions produces a Cisco directory object');
    check(count($phoneXml->DirectoryEntry) === 10,
        'Every real extension is present without needing a separate SCCP lookup');
    check((string)$phoneXml->Title === 'All numbers show heres',
        'Custom All Extensions title is retained');
    check(strlen((string)$phoneXml->Prompt) <= 32,
        'All Extensions never publishes the prompt that causes XML Error [4]');
    check((string)$phoneXml->Prompt === substr($longPrompt, 0, 32),
        'Legacy long prompt is automatically shortened on XML output');
    check(strlen((string)simplexml_load_file($base . '/web/contacts-1.xml')->Prompt) <= 32,
        'Favorites prompt is protected by the same XML schema limit');
    check(strlen((string)simplexml_load_file($base . '/web/contacts-1.xml')->Prompt) <= 32,
        'The directly provisioned Favorites prompt is protected');
    check(!preg_match('/[^\x00-\x7F]/', phonecontent::shortCiscoField(str_repeat('x', 31).'éextra')),
        'UTF-8 clipping preserves valid encoded characters');
    check(preg_match('//u', phonecontent::shortCiscoField(str_repeat('x', 31).'éextra')) === 1,
        'UTF-8 clipping does not split a multibyte character');
    check(strlen(phonecontent::shortCiscoField(str_repeat('x', 31).'éextra')) <= 32,
        'UTF-8 clipping stays inside schema byte limit');
    try {
        $content->saveDirectoryLabels(array('extensions_prompt'=>$longPrompt));
        throw new RuntimeException('New overlength prompt accepted');
    } catch (InvalidArgumentException $ok) {}
    $parent->setConfig('phonecontent_directory_labels', null);
    $parent->FreePBX->Core->users = array();
    $content->publishDirectory();

    $content->saveContact('', 'Alice & Bob', '150');
    $content->saveContact('', 'Garage', '*80');
    $xml = simplexml_load_file($base . '/web/contacts-1.xml');
    check(count($xml->DirectoryEntry) === 2, 'Directory entries');
    check($xml->getName() === 'CiscoIPPhoneDirectory' && !isset($xml->MenuItem),
        'Pressing Directories opens the Favorites entries, never a chooser');
    $keys = $xml->SoftKeyItem;
    check(count($keys) === 4, 'Four phone-native directory softkeys');
    check((string)$keys[0]->URL === 'SoftKey:Dial' &&
          (string)$keys[1]->URL === 'SoftKey:EditDial' &&
          (string)$keys[2]->Name === 'All' &&
          (string)$keys[2]->URL === 'http://192.168.0.25/sccp-manager/phone.php?doc=extensions-1' &&
          (string)$keys[3]->URL === 'SoftKey:Exit',
          'Dial/EditDial are preserved and All opens the full extension list');

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
    $pageOne = simplexml_load_file($base . '/web/contacts-1.xml');
    check($pageOne->getName() === 'CiscoIPPhoneDirectory' &&
          count($pageOne->DirectoryEntry) === 30, 'Even many Favorites open directly to the first 30 contacts');
    check(file_exists($base . '/web/contacts-2.xml'), 'Second Favorites page exists');
    check(!file_exists($base . '/web/directory.xml') &&
          !file_exists($base . '/web/phonebook.xml'), 'No redundant directory chooser exists');
    check(count(simplexml_load_file($base . '/web/favorites-pages.xml')->MenuItem) === 2,
        'Pages softkey opens a page index only when requested');
    check((string)$pageOne->SoftKeyItem[1]->Name === 'Pages' &&
          (string)$pageOne->SoftKeyItem[1]->URL === 'http://192.168.0.25/sccp-manager/phone.php?doc=favorites-pages' &&
          (string)$pageOne->SoftKeyItem[2]->URL === 'http://192.168.0.25/sccp-manager/phone.php?doc=extensions-1',
        'Pages and All are softkeys; navigation never blocks Favorites');

    // Connectivity -> Extensions is the authoritative source, including SCCP.
    $parent->FreePBX->Core->users = array(
        '111'=>array('extension'=>'111','name'=>'Desk Phone'),
        '150'=>array('extension'=>'150','name'=>'Upstairs Phone'),
        '170'=>array('extension'=>'170','name'=>'Front Room')
    );
    $content->publishDirectory();
    $extensions = simplexml_load_file($base . '/web/extensions-1.xml');
    check(count($extensions->DirectoryEntry) === 3, 'FreePBX extensions appear exactly once');
    check((string)$extensions->DirectoryEntry[0]->Name === 'Desk Phone (111)',
        'FreePBX extension display names are used');
    $content->saveExtensionChoices(array('111'=>'favorite','150'=>'hidden','170'=>'directory'));
    check($content->extensionChoices() === array('111'=>'favorite','150'=>'hidden'),
        'Shared favorite and hidden settings are persisted');
    $extensions = simplexml_load_file($base . '/web/extensions-1.xml');
    check(count($extensions->DirectoryEntry) === 2, 'Hidden extensions do not appear in All Extensions');
    check(strpos($extensions->asXML(), '150') === false, 'Hidden extension excluded from XML');
    $favoritePages = file_get_contents($base . '/web/contacts-1.xml') .
        file_get_contents($base . '/web/contacts-2.xml');
    check(strpos($favoritePages, 'Desk Phone') !== false,
        'Favorite extension appears with existing manually added contacts');
    check(strpos($favoritePages, 'Upstairs Phone') === false,
        'Hidden extension does not appear in Favorites');
    try {
        $content->saveExtensionChoices(array('999'=>'hidden'));
        throw new RuntimeException('Unknown extension accepted');
    } catch (InvalidArgumentException $ok) {}
    check($content->extensionChoices() === array('111'=>'favorite','150'=>'hidden'),
        'Rejected extension settings leave saved choices unchanged');
    $parent->FreePBX->Core->users['180'] = array('extension'=>'180','name'=>'New Extension');
    $content->publishDirectory();
    check(strpos(file_get_contents($base . '/web/extensions-1.xml'), 'New Extension') !== false,
        'New FreePBX extension appears after directory republishing');

    // A single editor saves imported extensions and custom contacts together.
    // Existing manually created contacts are Favorites unless hidden.
    $custom = $content->contacts();
    $customId = $custom[0]['id'];
    check($content->contactVisibility() === array(), 'Legacy custom contacts remain favorites');
    $content->saveDirectoryChoices(
        array('111'=>'favorite','150'=>'hidden','170'=>'directory','180'=>'directory'),
        array($customId=>'hidden')
    );
    check($content->contactVisibility() === array($customId=>'hidden'),
        'Custom contact visibility saved together with extension visibility');
    check($content->extensionChoices() === array('111'=>'favorite','150'=>'hidden'),
        'Unified save retains extension favorite and hidden selections');
    $favoriteXml = file_get_contents($base . '/web/contacts-1.xml') .
        file_get_contents($base . '/web/contacts-2.xml');
    check(strpos($favoriteXml, 'Garage') === false,
        'Hidden custom contact disappears from published phone Favorites');
    check(strpos($favoriteXml, 'Desk Phone') !== false,
        'Other favorites stay visible when a custom contact is hidden');
    try {
        $content->saveDirectoryChoices(array('111'=>'directory'),
            array($customId=>'not-a-real-visibility'));
        throw new RuntimeException('Invalid custom visibility accepted');
    } catch (InvalidArgumentException $ok) {}
    check($content->contactVisibility() === array($customId=>'hidden') &&
        $content->extensionChoices() === array('111'=>'favorite','150'=>'hidden'),
        'Invalid unified selection changes neither saved config');
    $content->saveDirectoryChoices(
        array('111'=>'favorite','150'=>'hidden','170'=>'directory','180'=>'directory'),
        array($customId=>'favorite')
    );
    check($content->contactVisibility() === array(), 'Hidden custom contact can be restored to Favorites');
    check(strpos(file_get_contents($base . '/web/contacts-1.xml'), 'Garage') !== false,
        'Restored custom favorite republishes immediately');
    $view = file_get_contents(__DIR__ . '/../views/content.contacts.php');
    check(substr_count($view, '<table ') === 1,
        'Directory editor contains one table for FreePBX and custom contacts');
    check(strpos($view, 'content_action" value="directory_choices"') !== false &&
        strpos($view, "'contact_choice['") !== false &&
        strpos($view, "'extension_choice['") !== false,
        'Unified editor saves both sources through one action');
    check(strpos($view, 'pc-directory-search') !== false &&
        strpos($view, 'pc-directory-filter') !== false,
        'All directory entries share search and visibility filters');
    check(strpos($view, 'sccp-extension-table') === false &&
        strpos($view, 'sccp-contact-table') === false,
        'Both legacy stacked directory tables are removed');


    // Favorites is the first phone-button screen. Labels map to the actual
    // two contact lists, not a fictitious intermediate chooser.
    $labels = $content->directoryLabels();
    check($labels['contacts_title'] === 'Favorites',
        'Default Directories title belongs to Favorites');
    check(!isset($labels['menu_title']) && !isset($labels['favorites_entry']) &&
          !isset($labels['extensions_entry']),
        'Obsolete chooser labels are not editable');
    $custom = array(
        'contacts_title' => 'Family & Friends',
        'contacts_prompt' => 'Pick <one>',
        'extensions_title' => 'Everyone on PBX',
        'extensions_prompt' => 'Choose who to call',
        'services_title' => 'Tools',
        'services_prompt' => 'Choose an action'
    );
    $contactCount = count($content->contacts());
    $content->saveDirectoryLabels($custom);
    check($content->directoryLabels() === $custom, 'Actual list labels persisted');
    check(count($content->contacts()) === $contactCount, 'Labels did not modify contacts');
    $favorites = simplexml_load_file($base . '/web/contacts-1.xml');
    $all = simplexml_load_file($base . '/web/extensions-1.xml');
    check((string)$favorites->Title === 'Family & Friends' &&
          (string)$favorites->Prompt === 'Pick <one>' &&
          (string)$all->Title === 'Everyone on PBX' &&
          (string)$all->Prompt === 'Choose who to call',
          'Both directory lists use their own configured labels');
    check((string)$favorites->SoftKeyItem[2]->Name === 'All' &&
          (string)$favorites->SoftKeyItem[2]->URL ===
          'http://192.168.0.25/sccp-manager/phone.php?doc=extensions-1',
          'Favorites has an All shortcut without a preceding menu');
    check((string)$all->SoftKeyItem[2]->Name === 'Favs' &&
          (string)$all->SoftKeyItem[2]->URL ===
          'http://192.168.0.25/sccp-manager/phone.php?doc=contacts-1',
          'All Extensions has the reverse Favorites shortcut');
    $content->saveDirectoryLabels(array('services_title'=>'My Services'));
    check((string)simplexml_load_file($base . '/web/services.xml')->Title === 'My Services' &&
          (string)simplexml_load_file($base . '/web/contacts-1.xml')->Title === 'Family & Friends',
          'Services and directory text can be changed independently');
    $editor = file_get_contents(__DIR__ . '/../views/content.contacts.php');
    check(strpos($editor, 'Directories opens Favorites immediately') !== false &&
          strpos($editor, 'pc-label-preview-screen') !== false &&
          strpos($editor, "phonebook_title") === false,
          'Label editor describes the actual softkey navigation, not a chooser');
    $preError = $content->directoryLabels();
    try {
        $content->saveDirectoryLabels(array('contacts_title'=>"Invalid\nTitle"));
        throw new RuntimeException('Invalid directory title accepted');
    } catch (InvalidArgumentException $ok) {}
    check($content->directoryLabels() === $preError, 'Invalid title did not change saved text');
    try {
        $content->saveDirectoryLabels(array('menu_title'=>'Obsolete Chooser'));
        throw new RuntimeException('Unused chooser field accepted');
    } catch (InvalidArgumentException $ok) {}
    check($content->directoryLabels() === $preError, 'Unused chooser fields cannot be saved');

    check(count($parent->dbinterface->writes) === 0, 'No implicit DB mutations');
    $changed = $content->syncUrls();
    check(count($changed) === 2, 'Autofill both phone URLs');
    check($parent->sccpvalues['dev_directoryURL']['data'] ===
        'http://192.168.0.25/sccp-manager/phone.php?doc=contacts-1', 'Direct Favorites URL');
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
