<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Cisco 79xx Phone Content: a FreePBX-managed directory and TFTP media library.
 * Never exposes an upload handler to phones. They fetch only generated static XML,
 * PNG and raw u-law files. All mutations run inside authenticated Module Admin.
 */
class phonecontent
{
    private $parent;
    private $tftp;
    private $public;

    public function __construct($parent = null, $tftp = null, $public = null)
    {
        $this->parent = $parent;
        $this->tftp = $tftp ?: ($parent->sccppath['tftp_path'] ?? '/tftpboot');
        $root = rtrim((string) ($GLOBALS['amp_conf']['AMPWEBROOT'] ?? '/var/www/html'), '/');
        $this->public = $public ?: $root . '/sccp-manager';
    }

    public function contacts() { return $this->items('phonecontent_contacts'); }

    /** FreePBX Connectivity -> Extensions, including registered SCCP phones. */
    public function extensions()
    {
        $rows = array();
        try {
            // BMO modules are loaded via FreePBX::__get(); __isset() is
            // not guaranteed to report a lazily loaded Core instance.
            $core = isset($this->parent->FreePBX) ? $this->parent->FreePBX->Core : null;
            if ($core && method_exists($core, 'getAllUsers')) {
                foreach ((array)$core->getAllUsers() as $user) {
                    if (!is_array($user)) continue;
                    $number = (string)($user['extension'] ?? '');
                    if (!preg_match('/^[0-9]{1,16}$/D', $number)) continue;
                    $name = trim((string)($user['name'] ?? ''));
                    if ($name === '' || !preg_match('//u', $name)) {
                        $name = 'Extension ' . $number;
                    }
                    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
                    $rows[$number] = array('number'=>$number, 'name'=>$name);
                }
            }
        } catch (\Throwable $e) {
            error_log('sccp_manager address book extension lookup: ' . $e->getMessage());
        }
        uasort($rows, function ($a, $b) {
            $diff = strcasecmp($a['name'], $b['name']);
            return $diff ?: strnatcmp($a['number'], $b['number']);
        });
        return $rows;
    }

    public function extensionChoices()
    {
        $saved = $this->parent->getConfig('phonecontent_extension_choices');
        if (!is_array($saved)) return array();
        $choices = array();
        foreach ($saved as $number=>$choice) {
            if (preg_match('/^[0-9]{1,16}$/D', (string)$number) &&
                in_array($choice, array('favorite', 'hidden'), true)) {
                $choices[(string)$number] = $choice;
            }
        }
        return $choices;
    }

    public function saveExtensionChoices($submitted)
    {
        if (!is_array($submitted)) {
            throw new \InvalidArgumentException('Invalid extension directory settings.');
        }
        $known = $this->extensions();
        $choices = array();
        foreach ($submitted as $number=>$choice) {
            $number = (string)$number;
            if (!preg_match('/^[0-9]{1,16}$/D', $number) ||
                !array_key_exists($number, $known) ||
                !is_string($choice) ||
                !in_array($choice, array('directory', 'favorite', 'hidden'), true)) {
                throw new \InvalidArgumentException('Invalid extension directory selection.');
            }
            if ($choice !== 'directory') $choices[$number] = $choice;
        }
        $old = $this->parent->getConfig('phonecontent_extension_choices');
        $this->parent->setConfig('phonecontent_extension_choices', $choices);
        try {
            $this->publishDirectory();
        } catch (\Throwable $e) {
            $this->parent->setConfig('phonecontent_extension_choices', $old);
            throw $e;
        }
    }


    /** Existing manually added contacts stay Favorites unless explicitly hidden. */
    public function contactVisibility()
    {
        $saved = $this->parent->getConfig('phonecontent_contact_visibility');
        if (!is_array($saved)) return array();
        $visibility = array();
        foreach ($saved as $id=>$choice) {
            if (preg_match('/^[a-f0-9]{16}$/D', (string)$id) && $choice === 'hidden') {
                $visibility[(string)$id] = 'hidden';
            }
        }
        return $visibility;
    }

    /**
     * Save one unified directory table, not separate extension and custom
     * contact settings. Validate everything before mutating either config.
     */
    public function saveDirectoryChoices($extensionSubmitted, $contactSubmitted)
    {
        if (!is_array($extensionSubmitted) || !is_array($contactSubmitted)) {
            throw new \InvalidArgumentException('Invalid directory selections.');
        }
        $knownExtensions = $this->extensions();
        $knownContacts = array();
        foreach ($this->contacts() as $contact) {
            if (isset($contact['id'])) $knownContacts[(string)$contact['id']] = true;
        }
        $extensions = array();
        foreach ($extensionSubmitted as $number=>$choice) {
            $number = (string)$number;
            if (!preg_match('/^[0-9]{1,16}$/D', $number) ||
                !array_key_exists($number, $knownExtensions) ||
                !is_string($choice) ||
                !in_array($choice, array('directory', 'favorite', 'hidden'), true)) {
                throw new \InvalidArgumentException('Invalid extension visibility.');
            }
            if ($choice !== 'directory') $extensions[$number] = $choice;
        }
        $contacts = array();
        foreach ($contactSubmitted as $id=>$choice) {
            $id = (string)$id;
            if (!isset($knownContacts[$id]) ||
                !is_string($choice) ||
                !in_array($choice, array('favorite', 'hidden'), true)) {
                throw new \InvalidArgumentException('Invalid custom contact visibility.');
            }
            if ($choice === 'hidden') $contacts[$id] = 'hidden';
        }

        $oldExtensions = $this->parent->getConfig('phonecontent_extension_choices');
        $oldContacts = $this->parent->getConfig('phonecontent_contact_visibility');
        $this->parent->setConfig('phonecontent_extension_choices', $extensions);
        $this->parent->setConfig('phonecontent_contact_visibility', $contacts);
        try {
            $this->publishDirectory();
        } catch (\Throwable $e) {
            $this->parent->setConfig('phonecontent_extension_choices', $oldExtensions);
            $this->parent->setConfig('phonecontent_contact_visibility', $oldContacts);
            throw $e;
        }
    }

    public function wallpapers() { return $this->items('phonecontent_wallpapers'); }
    public function ringtones() { return $this->items('phonecontent_ringtones'); }
    public function publicPath() { return $this->public; }
    public function tftpPath() { return $this->tftp; }

    private function items($key)
    {
        $value = $this->parent->getConfig($key);
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : array();
    }

    /**
     * Text displayed by the Cisco XML directories and Services menus.
     * Keeping the default strings here preserves existing phone behavior.
     */
    public static function defaultDirectoryLabels()
    {
        return array(
            'contacts_title' => 'Favorites',
            'contacts_prompt' => 'Select a contact to call',
            'extensions_title' => 'All Extensions',
            'extensions_prompt' => 'Select an extension to call',
            'services_title' => 'Phone Services',
            'services_prompt' => 'Choose a service'
        );
    }

    /** Preserve UTF-8 boundaries when limiting Cisco XML titles/prompts. */
    public static function shortCiscoField($value)
    {
        $text = (string) $value;
        if (strlen($text) <= 32) return $text;
        $text = substr($text, 0, 32);
        while ($text !== '' && !preg_match('//u', $text)) {
            $text = substr($text, 0, -1);
        }
        return rtrim($text);
    }

    public function directoryLabels()
    {
        $defaults = self::defaultDirectoryLabels();
        $saved = $this->parent->getConfig('phonecontent_directory_labels');
        $labels = is_array($saved) ?
            array_replace($defaults, array_intersect_key($saved, $defaults)) : $defaults;
        // Old releases allowed 80-byte prompts and persisted them. Read
        // those settings safely on upgrade without making admins retype them.
        foreach ($labels as $key=>$value) {
            if (preg_match('/_(title|prompt)$/D', $key)) {
                $labels[$key] = self::shortCiscoField($value);
            }
        }
        return $labels;
    }

    public function saveDirectoryLabels($submitted)
    {
        if (!is_array($submitted)) {
            throw new \InvalidArgumentException('Missing directory display text.');
        }
        if (!$submitted) {
            throw new \InvalidArgumentException('No directory labels supplied.');
        }
        $validated = $this->directoryLabels();
        $allowed = self::defaultDirectoryLabels();
        foreach ($submitted as $key => $incoming) {
            if (!array_key_exists($key, $allowed) || !is_string($incoming)) {
                throw new \InvalidArgumentException('Unknown directory label field.');
            }
            $value = trim($incoming);
            // The 7945G has a small LCD. Limit lengths without limiting the
            // user's choice of language; DOMDocument escapes XML characters.
            $limit = preg_match('/_(title|prompt)$/D', $key) ? 32 : 60;
            if ($value === '' || strlen($value) > $limit ||
                !preg_match('//u', $value) ||
                preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException(
                    'Invalid text for ' . $key . ' (1–' . $limit . ' UTF-8 bytes).');
            }
            $validated[$key] = $value;
        }
        $previous = $this->parent->getConfig('phonecontent_directory_labels');
        $this->parent->setConfig('phonecontent_directory_labels', $validated);
        // Keep the old settings if publishing fails, rather than persisting
        // labels that the phones cannot actually retrieve.
        try {
            $this->publishDirectory();
        } catch (\Throwable $error) {
            $this->parent->setConfig('phonecontent_directory_labels', $previous);
            throw $error;
        }
    }

    /**
     * ocserv assigns the host side of its VPN pool to the first usable IPv4
     * address. Use it for VPN handsets, independently of a possibly overlapping
     * LAN subnet. A manual Phone Content host always wins.
     */
    public function vpnServiceHost()
    {
        $vpn = $this->parent->vpnSettings();
        if (empty($vpn['enabled'])) return '';
        $subnet = explode('/', (string) ($vpn['vpn_subnet'] ?? ''), 2);
        if (count($subnet) !== 2 || !ctype_digit($subnet[1])) return '';
        $bits = (int) $subnet[1];
        if ($bits < 1 || $bits > 30 ||
            !filter_var($subnet[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return '';
        $network = ip2long($subnet[0]);
        if ($network === false) return '';
        $mask = (-1 << (32 - $bits)) & 0xffffffff;
        return long2ip(($network & $mask) + 1);
    }

    public function baseUrl($deviceId = null)
    {
        // Explicit overrides remain authoritative for all phones.
        $manual = trim((string) ($this->parent->getConfig('phonecontent_host') ?: ''));
        if ($manual !== '') {
            $host = $manual;
        } else {
            $vpn = $this->parent->vpnSettings();
            $vpnHost = $this->vpnServiceHost();
            $vpnPhone = $deviceId !== null &&
                $this->parent->vpnprovisioning->enabledFor($deviceId);

            // For dynamic Cisco XML pages (voicemail, menus), use the same
            // address on which the phone reached us. Do not trust arbitrary
            // Host headers or change admin-initiated publishing defaults.
            $requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $phonePath = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            $vpnRequest = $deviceId === null && $vpnHost !== '' &&
                $requestHost === $vpnHost &&
                strpos($phonePath, '/sccp-manager/') !== false;

            $host = ($vpnPhone || $vpnRequest) && $vpnHost !== ''
                ? $vpnHost : (string) ($vpn['pbx_ip'] ?? '');
            if ($host === '' && method_exists($this->parent, 'vpnDetectedPbxIp')) {
                $host = $this->parent->vpnDetectedPbxIp();
            }
        }
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return '';
        return 'http://' . $host . '/sccp-manager';
    }

    public function setAddress($value)
    {
        $value = trim((string) $value);
        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException('Enter a reachable PBX IPv4 address (for example 192.168.0.25).');
        }
        $this->parent->setConfig('phonecontent_host', $value);
        $this->publishDirectory();
        $this->syncUrls(true);
    }

    private function ensureDirectory($dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir . '. Check ownership or reinstall as root using fwconsole.');
        }
        if (is_link($dir) || !is_writable($dir)) {
            throw new \RuntimeException('Content directory is unsafe or unwritable: ' . $dir);
        }
    }

    private function publishFile($path, $contents)
    {
        $dir = dirname($path);
        $this->ensureDirectory($dir);
        if (is_link($path)) {
            throw new \RuntimeException('Refusing symlink output ' . $path);
        }
        $temp = tempnam($dir, '.sccp-tmp-');
        if ($temp === false) throw new \RuntimeException('Cannot stage output in ' . $dir);
        try {
            if (file_put_contents($temp, $contents) === false) throw new \RuntimeException('Failed to write ' . $path);
            chmod($temp, 0644);
            if (!rename($temp, $path)) throw new \RuntimeException('Failed to publish ' . $path);
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
    }

    private function xml($root)
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        return array($xml, $xml->appendChild($xml->createElement($root)));
    }

    private function element($doc, $parent, $key, $value)
    {
        // Guard at the XML output boundary as well: FreePBX and existing
        // saved settings may supply text that bypasses form validation.
        if ($key === 'Title' || $key === 'Prompt') {
            $value = self::shortCiscoField($value);
        }
        $node = $doc->createElement($key);
        $node->appendChild($doc->createTextNode((string) $value));
        $parent->appendChild($node);
    }

    /**
     * Cisco XML services require MIME text/xml, not Apache's default
     * application/xml. This endpoint serves only our generated static XML.
     */
    private function publishPhoneEndpoint()
    {
        $script = <<<'PHP'
<?php
$doc = (string) ($_GET['doc'] ?? '');
if (!preg_match('/^(services|contacts-[1-9][0-9]*|extensions-[1-9][0-9]*|favorites-pages|extensions-pages|pc-(sub|text)-[a-z0-9-]+)$/D', $doc)) {
    http_response_code(404);
    exit;
}
$path = __DIR__ . '/' . $doc . '.xml';
if (!is_file($path) || is_link($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/xml; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$body = file_get_contents($path);
$lanBase = __SCCP_LAN_BASE__;
$vpnBase = __SCCP_VPN_BASE__;
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
if ($vpnBase !== '' && $host === parse_url($vpnBase, PHP_URL_HOST) &&
    $lanBase !== '' && $lanBase !== $vpnBase) {
    $body = str_replace($lanBase . '/', $vpnBase . '/', $body);
}
echo $body;
PHP;
        // Published menus contain absolute managed URLs. Rewrite only our own
        // LAN base when the phone actually fetched the page via the VPN address.
        // This keeps the very same shared menu files usable by LAN-only phones.
        $script = str_replace(
            array('__SCCP_LAN_BASE__', '__SCCP_VPN_BASE__'),
            array(var_export($this->baseUrl(), true),
                var_export($this->vpnServiceHost() !== ''
                    ? 'http://' . $this->vpnServiceHost() . '/sccp-manager' : '', true)),
            $script
        );
        $this->publishFile($this->public . '/phone.php', $script . "\n");
    }

    private function publishServiceEndpoint()
    {
        $src = __DIR__ . '/../services/service-entry.php';
        if (!is_file($src)) throw new \RuntimeException('Built-in services endpoint missing.');
        $this->publishFile($this->public . '/service.php', file_get_contents($src));
    }

    private function publishVoicemailEndpoint()
    {
        $script = <<<'PHP'
<?php
// Cisco phones must receive *only* one complete XML object, never PHP
// warnings, FreePBX bootstrap output, or half a response interrupted by a
// new auto-answer call. Keep all HTTP parsing behind a per-device token.
ini_set('display_errors', '0');
$initialLevel = ob_get_level();
ob_start();
$responseCode = 200;
try {
    $bootstrap_settings['freepbx_auth'] = false;
    require_once '/etc/freepbx.conf';
    // A FreePBX bootstrap can write non-XML notices. None belong on the phone.
    while (ob_get_level() > $initialLevel + 1) ob_end_flush();
    if (ob_get_length()) {
        error_log('sccp_manager visual voicemail: discarded unexpected FreePBX bootstrap output');
        ob_clean();
    }

    $moduleFile = rtrim((string) $amp_conf['AMPWEBROOT'], '/') .
        '/admin/modules/sccp_manager/Sccp_manager.class.php';
    if (!is_file($moduleFile)) {
        throw new \RuntimeException('SCCP Manager module class is missing.');
    }
    require_once $moduleFile;
    $module = \FreePBX::create()->Sccp_manager;
    $module->phonevoicemail->handle($_GET);
} catch (\Throwable $e) {
    error_log('sccp_manager visual voicemail bootstrap: ' . $e->getMessage());
    $responseCode = 503;
    while (ob_get_level() > $initialLevel + 1) ob_end_flush();
    ob_clean();
    echo '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneText><Title>Voicemail</Title><Text>Service unavailable</Text></CiscoIPPhoneText>';
}

while (ob_get_level() > $initialLevel + 1) ob_end_flush();
$response = ob_get_clean();
// Refuse to forward malformed PHP/bootstrap output to the phone. Cisco's
// XML Error 4 is difficult to diagnose, so log it and respond with valid XML.
$parsed = new \DOMDocument();
if (!is_string($response) || !@$parsed->loadXML($response, LIBXML_NONET) ||
    !in_array($parsed->documentElement->tagName, array('CiscoIPPhoneMenu','CiscoIPPhoneText','CiscoIPPhoneExecute'), true)) {
    error_log('sccp_manager visual voicemail: invalid generated XML response');
    $response = '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneText><Title>Voicemail</Title><Text>Unable to display voicemail menu</Text></CiscoIPPhoneText>';
    $responseCode = 200;
}
http_response_code($responseCode);
header('Content-Type: text/xml; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
// Cisco 79xx phones only omit a page URL from their Exit/Back history when
// the HTTP response carries an Expires date that is already in the past.
// Cache-Control: no-store alone does NOT suppress the handset history stack.
header('Expires: Thu, 01 Jan 1970 00:00:01 GMT');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($response));
header('Connection: close');
echo $response;
// With a complete Content-Length response the phone can finish parsing before
// register_shutdown_function begins an auto-answer playback call.
flush();

PHP;
        $this->publishFile($this->public . '/voicemail.php', $script . "\n");
    }


    /** Add phone-native dialing and one shortcut between the two directories. */
    private function directorySoftkeys(\DOMDocument $doc, \DOMElement $root, $base, $otherDoc, $otherLabel, $pageIndex = null)
    {
        $items = array(
            array('Dial', 'SoftKey:Dial', 1),
            $pageIndex === null
                ? array('EditDial', 'SoftKey:EditDial', 2)
                : array('Pages', $base . '/phone.php?doc=' . $pageIndex, 2),
            array($otherLabel, $base . '/phone.php?doc=' . $otherDoc, 3),
            array('Exit', 'SoftKey:Exit', 4)
        );
        foreach ($items as $item) {
            $key = $root->appendChild($doc->createElement('SoftKeyItem'));
            $this->element($doc, $key, 'Name', $item[0]);
            $this->element($doc, $key, 'URL', $item[1]);
            $this->element($doc, $key, 'Position', $item[2]);
        }
    }

    /** Always open page one directly; show a Pages softkey only when needed. */
    private function publishContactPages(array $rows, $prefix, $title, $prompt, $base, $otherDoc, $otherLabel)
    {
        $chunks = array_chunk($rows, 30);
        if (!$chunks) $chunks = array(array());
        if (count($chunks) > 30) {
            throw new \RuntimeException('Too many entries for one directory section (900 maximum).');
        }
        $pageIndex = $prefix === 'contacts' ? 'favorites-pages' : 'extensions-pages';
        $paged = count($chunks) > 1;
        foreach ($chunks as $i=>$chunk) {
            list($doc, $root) = $this->xml('CiscoIPPhoneDirectory');
            $this->element($doc, $root, 'Title', $title);
            $this->element($doc, $root, 'Prompt', $prompt);
            foreach ($chunk as $entry) {
                $row = $root->appendChild($doc->createElement('DirectoryEntry'));
                $this->element($doc, $row, 'Name', $entry['name']);
                $this->element($doc, $row, 'Telephone', $entry['number']);
            }
            $this->directorySoftkeys($doc, $root, $base, $otherDoc, $otherLabel,
                $paged ? $pageIndex : null);
            $this->publishFile($this->public . '/' . $prefix . '-' . ($i + 1) . '.xml', $doc->saveXML());
        }
        foreach (glob($this->public . '/' . $prefix . '-*.xml') ?: array() as $oldPage) {
            if (!preg_match('/^' . preg_quote($prefix, '/') . '-([0-9]+)\.xml$/D', basename($oldPage), $match)) continue;
            if ((int)$match[1] > count($chunks) && is_file($oldPage) && !is_link($oldPage)) @unlink($oldPage);
        }
        // A pager is an optional secondary navigation action, never the first
        // screen shown after pressing the physical Directories button.
        if ($paged) {
            list($doc, $root) = $this->xml('CiscoIPPhoneMenu');
            $this->element($doc, $root, 'Title', $title);
            $this->element($doc, $root, 'Prompt', $prompt);
            foreach ($chunks as $i=>$chunk) {
                $row = $root->appendChild($doc->createElement('MenuItem'));
                $this->element($doc, $row, 'Name', $title . ' ' .
                    ($i * 30 + 1) . '-' . ($i * 30 + count($chunk)));
                $this->element($doc, $row, 'URL', $base . '/phone.php?doc=' . $prefix . '-' . ($i + 1));
            }
            $this->publishFile($this->public . '/' . $pageIndex . '.xml', $doc->saveXML());
        } else {
            $old = $this->public . '/' . $pageIndex . '.xml';
            if (is_file($old) && !is_link($old)) @unlink($old);
        }
        return $base . '/phone.php?doc=' . $prefix . '-1';
    }

    public function publishDirectory()
    {
        $base = $this->baseUrl();
        if ($base === '') throw new \RuntimeException('Set the PBX address in Phone Content before publishing the directory.');
        $this->publishPhoneEndpoint();
        $this->publishVoicemailEndpoint();
        $this->publishServiceEndpoint();
        $labels = $this->directoryLabels();
        $choices = $this->extensionChoices();
        $extensions = $this->extensions();

        // Preserve existing manually entered contacts as shared Favorites.
        // Hidden overrides both sections when a manual contact references
        // a hidden extension. Avoid duplicate favorites by dialable number.
        $favorites = array();
        $favoriteNumbers = array();
        $customVisibility = $this->contactVisibility();
        foreach ($this->contacts() as $contact) {
            $number = (string)($contact['number'] ?? '');
            if (($customVisibility[$contact['id'] ?? ''] ?? '') === 'hidden' ||
                ($choices[$number] ?? '') === 'hidden') continue;
            $favorites[] = $contact;
            $favoriteNumbers[$number] = true;
        }
        $allExtensions = array();
        foreach ($extensions as $number=>$ext) {
            $choice = $choices[$number] ?? 'directory';
            if ($choice === 'hidden') continue;
            $display = array('name'=>$ext['name'] . ' (' . $number . ')', 'number'=>$number);
            $allExtensions[] = $display;
            if ($choice === 'favorite' && !isset($favoriteNumbers[$number])) {
                $favorites[] = $display;
                $favoriteNumbers[$number] = true;
            }
        }
        $sort = function ($a, $b) {
            $cmp = strcasecmp($a['name'], $b['name']);
            return $cmp ?: strnatcmp($a['number'], $b['number']);
        };
        usort($favorites, $sort);
        usort($allExtensions, $sort);
        // The physical Directories key lands on contacts-1 immediately.
        // Keep the All/Favs shortcuts symmetric. Nothing sits in front of
        // either list and both retain native Dial/EditDial functionality.
        $this->publishContactPages($favorites, 'contacts',
            $labels['contacts_title'], $labels['contacts_prompt'], $base, 'extensions-1', 'All');
        $this->publishContactPages($allExtensions, 'extensions',
            $labels['extensions_title'], $labels['extensions_prompt'], $base, 'contacts-1', 'Favs');
        $this->parent->phonemenus->publish();
        // Both intermediate menu files belong to the retired two-level
        // directory design. Remove them rather than leaving stale
        // alternatives alongside the canonical Favorites page.
        foreach (array('directory.xml', 'phonebook.xml') as $retired) {
            $path = $this->public . '/' . $retired;
            if (is_file($path) && !is_link($path)) @unlink($path);
        }
    }

    /** Only prevalidated generated XML filenames, within the managed web root. */
    public function publishMenuDocument($filename, $content)
    {
        if (!preg_match('/^(services|pc-(sub|text)-[a-z0-9-]+)\.xml$/D', (string) $filename)) {
            throw new \InvalidArgumentException('Invalid managed menu filename.');
        }
        $this->publishFile($this->public . '/' . $filename, $content);
    }

    /**
     * Fill only blank URLs (or URLs we previously managed). Never overwrite a
     * manually configured service. Existing signed SEP XML is not rewritten until
     * the administrator explicitly chooses Publish Phone Configurations.
     */
    public function syncUrls($force = false)
    {
        $base = $this->baseUrl();
        if (!$base) return array();
        $changed = array();
        foreach (array('dev_directoryURL' => '/phone.php?doc=contacts-1',
                       'dev_servicesURL' => '/phone.php?doc=services') as $key => $suffix) {
            $current = (string) ($this->parent->sccpvalues[$key]['data'] ?? '');
            $old = (string) $this->parent->getConfig('phonecontent_managed_' . $key);
            $new = $base . $suffix;
            if (!$force && $current !== '' && ($old === '' || $current !== $old)) continue;
            if ($current !== $new) {
                // Administrator explicitly enabled built-in services. Keep the
                // former URL so it can be restored if the feature is disabled.
                if ($force && $current !== $old && $current !== '' &&
                    $this->parent->getConfig('phonecontent_previous_' . $key) === null) {
                    $this->parent->setConfig('phonecontent_previous_' . $key, $current);
                }
                $record = $this->parent->sccpvalues[$key] ?? array();
                $record = array_merge(array(
                    'keyword' => $key, 'seq' => 10, 'type' => 0, 'systemdefault' => ''
                ), $record, array('keyword' => $key, 'data' => $new));
                $this->parent->dbinterface->write('sccpsettings', array($key => $record), 'update');
                $this->parent->sccpvalues[$key] = $record;
                $changed[] = $key;
            }
            $this->parent->setConfig('phonecontent_managed_' . $key, $new);
        }
        return $changed;
    }

    public function status()
    {
        $base = $this->baseUrl();
        $xml = $this->public . '/contacts-1.xml';
        $index = $this->tftp . '/Desktops/320x212x16/List.xml';
        $rings = $this->tftp . '/ringlist.xml';
        $conf = $this->parent->sccpvalues;
        $url = (string) ($conf['dev_directoryURL']['data'] ?? '');
        // Debian TFTP's actual served directory may differ from SCCP's
        // configured upload directory. Report a mismatch, rather than
        // incorrectly marking a wallpaper usable merely because it exists.
        $tftpServiceRoot = '';
        $tftpDefaults = '/etc/default/tftpd-hpa';
        if (is_readable($tftpDefaults)) {
            $defaults = file_get_contents($tftpDefaults);
            if (preg_match('/^\\s*TFTP_DIRECTORY\\s*=\\s*["\\x27]?([^"\\x27\\r\\n]+)["\\x27]?/m', $defaults, $match)) {
                $tftpServiceRoot = trim($match[1]);
            }
        }
        $status = array(
            'web_dir' => $this->public,
            'directory_file' => $xml,
            'directory_exists' => is_file($xml),
            'directory_readable' => is_readable($xml),
            'configured_url' => $url,
            'expected_url' => $base ? $base . '/phone.php?doc=contacts-1' : '',
            'url_matches' => $url !== '' && $url === $base . '/phone.php?doc=contacts-1',
            'wallpaper_index' => $index,
            'wallpaper_index_exists' => is_file($index),
            'wallpaper_index_readable' => is_readable($index),
            'wallpaper_signed' => is_file($index . '.sgn'),
            'wallpaper_requires_signing' => $this->needsSignedContent(),
            'wallpaper_count' => count($this->wallpapers()),
            'wallpaper_access_enabled' => in_array(
                strtolower((string) ($conf['backgroundImageAccess']['data'] ?? 'off')),
                array('on', 'true', '1'), true),
            'ring_index' => $rings,
            'ring_index_exists' => is_file($rings),
            'ring_index_readable' => is_readable($rings),
            'ring_count' => count($this->ringtones()),
            'tftp_root' => $this->tftp,
            'tftp_service_root' => $tftpServiceRoot,
            'tftp_root_matches' => $tftpServiceRoot === '' ||
                realpath($tftpServiceRoot) === realpath($this->tftp),
            'tftp_root_exists' => is_dir($this->tftp)
        );
        $status['wallpaper_files_ok'] = $status['wallpaper_count'] > 0;
        foreach ($this->wallpapers() as $item) {
            $id = $item['id'] ?? '';
            if (!preg_match('/^[0-9a-f]{16}$/D', (string) $id)) {
                $status['wallpaper_files_ok'] = false;
                continue;
            }
            $dir = dirname($index);
            if (!is_readable($dir . '/PC-' . $id . '.png') ||
                !is_readable($dir . '/TN-PC-' . $id . '.png')) {
                $status['wallpaper_files_ok'] = false;
            }
        }
        // Report the effective deployed config for the user's 7945G when
        // available. This is a disk check, not a promise of network delivery.
        $status['phones'] = array();
        foreach (glob($this->tftp . '/SEP*.cnf.xml') ?: array() as $file) {
            $name = basename($file);
            if (!preg_match('/^SEP[0-9A-F]{12}\.cnf\.xml$/D', $name)) continue;
            if (count($status['phones']) >= 30) break;
            $xmlContent = @file_get_contents($file);
            if ($xmlContent === false) continue;
            $document = new \DOMDocument();
            if (!@$document->loadXML($xmlContent, LIBXML_NONET)) continue;
            $find = function ($tag) use ($document) {
                return trim((string) ($document->getElementsByTagName($tag)->item(0)->textContent ?? ''));
            };
            $status['phones'][] = array(
                'id' => substr($name, 0, 15),
                'directory_url' => $find('directoryURL'),
                'services_url' => $find('servicesURL'),
                'wallpaper_enabled' => $find('backgroundImageAccess'),
                'signature_exists' => is_file($file . '.sgn')
            );
        }
        return $status;
    }

    public function restoreUrls()
    {
        $changed = array();
        foreach (array('dev_directoryURL', 'dev_servicesURL') as $key) {
            $current = (string) ($this->parent->sccpvalues[$key]['data'] ?? '');
            $old = (string) $this->parent->getConfig('phonecontent_managed_' . $key);
            if (!$old || $current !== $old) continue;
            $previous = (string) ($this->parent->getConfig('phonecontent_previous_' . $key) ?: '');
            $record = $this->parent->sccpvalues[$key];
            $record['data'] = $previous;
            $this->parent->dbinterface->write('sccpsettings', array($key => $record), 'update');
            $this->parent->sccpvalues[$key] = $record;
            $this->parent->setConfig('phonecontent_managed_' . $key, '');
            $changed[] = $key;
        }
        return $changed;
    }

    public function enableWallpapers()
    {
        $key = 'backgroundImageAccess';
        if (($this->parent->sccpvalues[$key]['data'] ?? '') === 'on') return;
        $record = $this->parent->sccpvalues[$key] ?? array();
        $record = array_merge(array(
            'keyword' => $key, 'seq' => 1, 'type' => 0, 'systemdefault' => 'off'
        ), $record, array('keyword' => $key, 'data' => 'on'));
        $this->parent->dbinterface->write('sccpsettings', array($key => $record), 'update');
        $this->parent->sccpvalues[$key] = $record;
    }

    public function saveContact($id, $name, $number)
    {
        $name = trim((string) $name);
        $number = trim((string) $number);
        if ($name === '' || strlen($name) > 100 ||
            !preg_match('/^[0-9+*#,.-]{1,40}$/D', $number)) {
            throw new \InvalidArgumentException('A contact needs a name (max 100 bytes) and dialable number (max 40).');
        }
        $rows = $this->contacts();
        if ($id !== '') {
            foreach ($rows as &$row) {
                if ($row['id'] === $id) {
                    $row['name'] = $name;
                    $row['number'] = $number;
                    unset($row);
                    $this->parent->setConfig('phonecontent_contacts', $rows);
                    $this->publishDirectory();
                    return;
                }
            }
            throw new \InvalidArgumentException('Unknown contact.');
        }
        if (count($rows) >= 300) throw new \RuntimeException('Directory is limited to 300 contacts.');
        $rows[] = array('id' => bin2hex(random_bytes(8)), 'name' => $name, 'number' => $number);
        $this->parent->setConfig('phonecontent_contacts', $rows);
        $this->publishDirectory();
    }

    public function removeContact($id)
    {
        $rows = $this->contacts();
        $rows = array_values(array_filter($rows, function ($row) use ($id) { return $row['id'] !== $id; }));
        $this->parent->setConfig('phonecontent_contacts', $rows);
        $this->publishDirectory();
    }

    private function upload($file, $max, $extensions)
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Upload failed. Choose a file and try again.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > $max) throw new \InvalidArgumentException('File exceeds the upload limit.');
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $extensions, true)) {
            throw new \InvalidArgumentException('Unsupported file type.');
        }
        return $ext;
    }

    private function managedIndex($path, $marker)
    {
        if (is_link($path)) throw new \RuntimeException('Unsafe symlinked Cisco index.');
        if (is_file($path) && strpos((string) file_get_contents($path), $marker) === false) {
            throw new \RuntimeException('Existing ' . basename($path) . ' is not managed by SCCP Manager. Back it up or migrate it before using this library.');
        }
    }

    private function renderPng($image, $width, $height)
    {
        $out = imagecreatetruecolor($width, $height);
        imagealphablending($out, true);
        imagesavealpha($out, false);
        imagefill($out, 0, 0, imagecolorallocate($out, 0, 0, 0));
        $sw = imagesx($image);
        $sh = imagesy($image);
        $scale = max($width / $sw, $height / $sh);
        $rw = (int) ceil($sw * $scale);
        $rh = (int) ceil($sh * $scale);
        imagecopyresampled($out, $image, (int) floor(($width - $rw) / 2),
            (int) floor(($height - $rh) / 2), 0, 0, $rw, $rh, $sw, $sh);
        ob_start();
        imagepng($out);
        $bytes = ob_get_clean();
        imagedestroy($out);
        if (!$bytes) throw new \RuntimeException('PNG conversion failed.');
        return $bytes;
    }

    public function addWallpaper($file, $label)
    {
        $this->upload($file, 8 * 1024 * 1024, array('png', 'jpg', 'jpeg', 'webp'));
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('PHP GD is required for wallpaper uploads (install php-gd).');
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || $info[0] < 1 || $info[1] < 1 ||
            $info[0] > 6000 || $info[1] > 6000 ||
            $info[0] * $info[1] > 24000000) {
            throw new \InvalidArgumentException('Image dimensions are unsupported.');
        }
        $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if (!$image) throw new \InvalidArgumentException('Cannot decode the image.');
        $rows = $this->wallpapers();
        if (count($rows) >= 50) {
            imagedestroy($image);
            throw new \RuntimeException('Cisco background menus support at most 50 custom images.');
        }
        $label = trim((string) $label);
        if ($label === '' || strlen($label) > 60) $label = 'Wallpaper ' . (count($rows) + 1);
        $id = bin2hex(random_bytes(8));
        $dir = $this->tftp . '/Desktops/320x212x16';
        $this->ensureDirectory($dir);
        $this->managedIndex($dir . '/List.xml', 'Managed by SCCP Manager');
        try {
            $large = $this->renderPng($image, 320, 212);
            $thumb = $this->renderPng($image, 80, 53);
        } finally {
            imagedestroy($image);
        }
        $this->publishFile($dir . '/PC-' . $id . '.png', $large);
        $this->publishFile($dir . '/TN-PC-' . $id . '.png', $thumb);
        // The web UI shows the real 320x212 artwork. The phone still uses the
        // small 80x53 thumbnail in its TFTP selection screen.
        $this->publishFile($this->public . '/wallpaper-' . $id . '.png', $large);
        $rows[] = array('id' => $id, 'name' => $label);
        $this->parent->setConfig('phonecontent_wallpapers', $rows);
        $this->publishWallpapers();
        $this->enableWallpapers();
    }

    /**
     * Cisco ITL-enabled 7945Gs fetch List.xml.sgn, not List.xml.
     * Use the exact root-owned TFTP signer already trusted by the phone.
     */
    private function needsSignedContent()
    {
        return realpath($this->tftp) === '/tftpboot' &&
            count(glob($this->tftp . '/ITLSEP*.tlv') ?: array()) > 0;
    }

    private function signContent($kind)
    {
        if (!$this->needsSignedContent()) return;
        $helper = '/usr/local/libexec/sccp-manager-vpn-helper';
        if (!is_executable($helper)) {
            throw new \RuntimeException(
                'Cisco phones have ITL trust, but the managed signing helper is not installed.');
        }
        $argv = array('/usr/bin/sudo', '-n', $helper, 'sign-content', $kind);
        $pipes = array();
        $proc = proc_open($argv, array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        ), $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start Cisco content signer');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new \RuntimeException('Could not sign Cisco ' . $kind .
                ': ' . substr(trim($stderr ?: $stdout), 0, 300));
        }
    }

    public function wallpaperSignaturesMissing()
    {
        if (!$this->wallpapers() || !$this->needsSignedContent()) return false;
        $dir = $this->tftp . '/Desktops/320x212x16';
        $files = array($dir . '/List.xml');
        foreach ($this->wallpapers() as $item) {
            $id = (string) ($item['id'] ?? '');
            if (!preg_match('/^[a-f0-9]{16}$/D', $id)) continue;
            $files[] = $dir . '/PC-' . $id . '.png';
            $files[] = $dir . '/TN-PC-' . $id . '.png';
        }
        foreach ($files as $file) {
            $signed = $file . '.sgn';
            if (!is_file($signed) ||
                filemtime($signed) < filemtime($file)) {
                return true;
            }
            // Earlier builds generated a valid signature over the bytes, but
            // wrote a basename-only HEADER_FILENAME. The 7945 rejects it
            // because it requested the TFTP-relative path (Desktops/...).
            // Check the literal filename in the signed TLV header and
            // regenerate automatically when the filename is incorrect.
            $expectedName = ltrim(substr($signed, strlen(rtrim($this->tftp, '/'))), '/');
            $handle = @fopen($signed, 'rb');
            if ($handle === false) return true;
            $header = fread($handle, 2048);
            fclose($handle);
            if ($header === false || strpos($header, $expectedName . "\0") === false) {
                return true;
            }
        }
        return false;
    }

    public function publishWallpapers()
    {
        $dir = $this->tftp . '/Desktops/320x212x16';
        $this->ensureDirectory($dir);
        $index = $dir . '/List.xml';
        $this->managedIndex($index, 'Managed by SCCP Manager');
        list($doc, $root) = $this->xml('CiscoIPPhoneImageList');
        $root->appendChild($doc->createComment(' Managed by SCCP Manager '));
        foreach ($this->wallpapers() as $item) {
            if (!preg_match('/^[a-f0-9]{16}$/D', $item['id'])) continue;
            $elem = $root->appendChild($doc->createElement('ImageItem'));
            $elem->setAttribute('Image', 'TFTP:Desktops/320x212x16/TN-PC-' . $item['id'] . '.png');
            $elem->setAttribute('URL', 'TFTP:Desktops/320x212x16/PC-' . $item['id'] . '.png');
        }
        $this->publishFile($index, $doc->saveXML());
        $this->signContent('wallpapers');
    }

    public function removeWallpaper($id)
    {
        $this->validateId($id);
        $rows = $this->wallpapers();
        $rows = array_values(array_filter($rows, function ($row) use ($id) { return $row['id'] !== $id; }));
        $this->parent->setConfig('phonecontent_wallpapers', $rows);
        $this->publishWallpapers();
        foreach (array($this->tftp . '/Desktops/320x212x16/PC-' . $id . '.png',
                       $this->tftp . '/Desktops/320x212x16/TN-PC-' . $id . '.png',
                       $this->public . '/wallpaper-' . $id . '.png') as $file) {
            if (is_file($file) && !is_link($file)) @unlink($file);
        }
    }

    public function addRingtone($file, $label)
    {
        $ext = $this->upload($file, 10 * 1024 * 1024, array('wav', 'mp3', 'ogg', 'flac', 'm4a'));
        $label = trim((string) $label);
        if ($label === '' || strlen($label) > 25) {
            throw new \InvalidArgumentException('Ringtone display name must be 1–25 bytes.');
        }
        $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' :
            (is_executable('/usr/local/bin/ffmpeg') ? '/usr/local/bin/ffmpeg' : '');
        if ($ffmpeg === '') throw new \RuntimeException('ffmpeg is required for ringtone uploads (install ffmpeg).');
        $rows = $this->ringtones();
        if (count($rows) >= 50) throw new \RuntimeException('Cisco supports at most 50 custom ringtones.');
        $this->ensureDirectory($this->tftp);
        $this->managedIndex($this->tftp . '/ringlist.xml', 'Managed by SCCP Manager');
        $id = bin2hex(random_bytes(8));
        $output = $this->tftp . '/PC-' . $id . '.raw';
        $cmd = array($ffmpeg, '-v', 'error', '-nostdin', '-y', '-i',
            $file['tmp_name'], '-vn', '-ac', '1', '-ar', '8000', '-t', '2.01',
            '-f', 'mulaw', $output);
        $pipes = array();
        $proc = proc_open($cmd, array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        ), $pipes);
        if (!is_resource($proc)) throw new \RuntimeException('Could not start ffmpeg.');
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0 || !is_file($output)) {
            @unlink($output);
            throw new \RuntimeException('Audio conversion failed: ' . substr($stderr, 0, 250));
        }
        $data = file_get_contents($output);
        // Cisco 79xx: headerless 8kHz 8-bit u-law, 240-16080 samples,
        // a multiple of 240. 0xFF represents silence at both ends.
        $length = min(16080, (int) floor(strlen($data) / 240) * 240);
        if ($length < 240) {
            @unlink($output);
            throw new \RuntimeException('Ringtone audio is too short.');
        }
        $data = substr($data, 0, $length);
        $data[0] = "\xFF";
        $data[$length - 1] = "\xFF";
        $this->publishFile($output, $data);
        $preview = $this->public . '/ringtone-' . $id . '.' . $ext;
        $this->ensureDirectory($this->public);
        if (!copy($file['tmp_name'], $preview)) {
            @unlink($output);
            throw new \RuntimeException('Could not store ringtone preview.');
        }
        chmod($preview, 0644);
        $rows[] = array('id' => $id, 'name' => $label, 'ext' => $ext);
        $this->parent->setConfig('phonecontent_ringtones', $rows);
        $this->publishRingtones();
    }

    public function publishRingtones()
    {
        $path = $this->tftp . '/ringlist.xml';
        $this->managedIndex($path, 'Managed by SCCP Manager');
        list($doc, $root) = $this->xml('CiscoIPPhoneRingList');
        $root->appendChild($doc->createComment(' Managed by SCCP Manager '));
        foreach ($this->ringtones() as $item) {
            if (!preg_match('/^[a-f0-9]{16}$/D', $item['id'])) continue;
            $ring = $root->appendChild($doc->createElement('Ring'));
            $this->element($doc, $ring, 'DisplayName', $item['name']);
            $this->element($doc, $ring, 'FileName', 'PC-' . $item['id'] . '.raw');
        }
        $this->publishFile($path, $doc->saveXML());
        $this->signContent('ringtones');
    }

    private function validateId($id)
    {
        if (!is_string($id) || !preg_match('/^[0-9a-f]{16}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid content identifier.');
        }
    }

    public function removeRingtone($id)
    {
        $this->validateId($id);
        $rows = $this->ringtones();
        foreach ($rows as $item) {
            if ($item['id'] === $id && preg_match('/^(wav|mp3|ogg|flac|m4a)$/', $item['ext'] ?? '')) {
                $preview = $this->public . '/ringtone-' . $id . '.' . $item['ext'];
                if (is_file($preview) && !is_link($preview)) @unlink($preview);
            }
        }
        $rows = array_values(array_filter($rows, function ($row) use ($id) { return $row['id'] !== $id; }));
        $this->parent->setConfig('phonecontent_ringtones', $rows);
        $this->publishRingtones();
        $file = $this->tftp . '/PC-' . $id . '.raw';
        if (is_file($file) && !is_link($file)) @unlink($file);
    }

    public function handle(array $post, array $files)
    {
        $action = (string) ($post['content_action'] ?? '');
        switch ($action) {
            case 'address': $this->setAddress($post['pbx_address'] ?? ''); return 'Directory URL updated.';
            case 'services_save':
                $this->parent->phonemenus->saveServices($post['services'] ?? array());
                if (isset($post['weather_name'], $post['weather_lat'], $post['weather_lon'])) {
                    $name = trim((string) $post['weather_name']);
                    $lat = filter_var($post['weather_lat'], FILTER_VALIDATE_FLOAT);
                    $lon = filter_var($post['weather_lon'], FILTER_VALIDATE_FLOAT);
                    if ($name === '' || strlen($name) > 64 ||
                        $lat === false || $lat < -90 || $lat > 90 ||
                        $lon === false || $lon < -180 || $lon > 180) {
                        throw new \InvalidArgumentException('Invalid weather location or coordinates.');
                    }
                    $this->parent->setConfig('phonecontent_weather',
                        array('name' => $name, 'lat' => $lat, 'lon' => $lon));
                }
                $feeds = array(
                    'bbc' => 'https://feeds.bbci.co.uk/news/rss.xml',
                    'bbc-world' => 'https://feeds.bbci.co.uk/news/world/rss.xml',
                    'bbc-technology' => 'https://feeds.bbci.co.uk/news/technology/rss.xml',
                    'bbc-sport' => 'https://feeds.bbci.co.uk/sport/rss.xml',
                    'npr' => 'https://feeds.npr.org/1001/rss.xml'
                );
                $feedId = (string) ($post['news_feed'] ?? 'bbc');
                if (!isset($feeds[$feedId])) throw new \InvalidArgumentException('Invalid news feed selection.');
                $this->parent->setConfig('phonecontent_news_feed', $feeds[$feedId]);
                $this->parent->phoneContentRegenerate(false);
                return 'Services updated; signed phone configurations refreshed (reboot phones to load new button destinations).';
            case 'save_menus': $this->parent->phonemenus->save($post['menu_config'] ?? '');
                return 'Services and Messages menus published.';
            case 'directory_text': $this->saveDirectoryLabels($post['directory_text'] ?? null);
                return 'Directory display text saved.';
            case 'contact': $this->saveContact((string) ($post['id'] ?? ''),
                $post['contact_name'] ?? '', $post['contact_number'] ?? ''); return 'Contact saved.';
            case 'delete_contact': $this->removeContact((string) ($post['id'] ?? '')); return 'Contact removed.';
            case 'directory_choices':
                $this->saveDirectoryChoices($post['extension_choice'] ?? array(), $post['contact_choice'] ?? array());
                return 'Shared directory updated.';
            case 'extension_choices': $this->saveExtensionChoices($post['extension_choice'] ?? array());
                return 'Shared Favorites and hidden extension settings saved.';
            case 'wallpaper': $this->addWallpaper($files['media'] ?? null, $post['label'] ?? ''); return 'Wallpaper published.';
            case 'delete_wallpaper': $this->removeWallpaper((string) ($post['id'] ?? '')); return 'Wallpaper removed.';
            case 'notifications_save': (new notificationConfig($this->parent))->save($post['notifications'] ?? []); return 'Notification settings saved.';
            case 'notifications_test': $sent=(new notificationDelivery($this->parent))->send('scheduled', [], null, true); return 'Test notification sent to ' . $sent . ' phone(s).';
            case 'ringtone': $this->addRingtone($files['media'] ?? null, $post['label'] ?? ''); return 'Ringtone published.';
            case 'delete_ringtone': $this->removeRingtone((string) ($post['id'] ?? '')); return 'Ringtone removed.';
            case 'restore_urls': $changed = $this->restoreUrls();
                return $changed ? 'Previous URL settings restored. Apply updated phone configurations next.' :
                    'No managed URL settings were active.';
            case 'urls': $this->publishDirectory(); $changed = $this->syncUrls(true);
                return $changed ? 'Built-in Directory and Services URLs activated. Apply updated phone configurations next.' :
                    'Directory published; built-in URL settings already active.';
            default: throw new \InvalidArgumentException('Unknown phone content action.');
        }
    }
}
