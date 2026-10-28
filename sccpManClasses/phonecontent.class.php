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
            'menu_title' => 'Directories',
            'menu_prompt' => 'Select a directory',
            'menu_entry' => 'Phone Book',
            'contacts_title' => 'Phone Book',
            'contacts_prompt' => 'Select a contact to call',
            'services_title' => 'Phone Services',
            'services_prompt' => 'Choose a service',
            'services_entry' => 'Address Book'
        );
    }

    public function directoryLabels()
    {
        $defaults = self::defaultDirectoryLabels();
        $saved = $this->parent->getConfig('phonecontent_directory_labels');
        return is_array($saved) ? array_replace(
            $defaults, array_intersect_key($saved, $defaults)
        ) : $defaults;
    }

    public function saveDirectoryLabels($submitted)
    {
        if (!is_array($submitted)) {
            throw new \InvalidArgumentException('Missing directory display text.');
        }
        $validated = array();
        foreach (self::defaultDirectoryLabels() as $key => $default) {
            if (!isset($submitted[$key]) || !is_string($submitted[$key])) {
                throw new \InvalidArgumentException('Missing directory field: ' . $key);
            }
            $value = trim($submitted[$key]);
            // The 7945G has a small LCD. Limit lengths without limiting the
            // user's choice of language; DOMDocument escapes XML characters.
            $limit = strpos($key, 'prompt') !== false ? 80 : 60;
            if ($value === '' || strlen($value) > $limit ||
                !preg_match('//u', $value) ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException(
                    'Invalid text for ' . $key . ' (1–' . $limit . ' UTF-8 bytes).');
            }
            $validated[$key] = $value;
        }
        $this->parent->setConfig('phonecontent_directory_labels', $validated);
        // URLs and signed SEP phone provisioning are unchanged. The next
        // Directories/Services request gets the updated static XML.
        $this->publishDirectory();
    }

    public function baseUrl()
    {
        $vpn = method_exists($this->parent, 'vpnSettings') ? $this->parent->vpnSettings() : array();
        $host = trim((string) ($this->parent->getConfig('phonecontent_host') ?: ($vpn['pbx_ip'] ?? '')));
        if ($host === '' && method_exists($this->parent, 'vpnDetectedPbxIp')) {
            $host = $this->parent->vpnDetectedPbxIp();
        }
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return '';
        }
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
if (!preg_match('/^(directory|services|contacts-[1-9][0-9]*)$/D', $doc)) {
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
readfile($path);
PHP;
        $this->publishFile($this->public . '/phone.php', $script . "\n");
    }

    public function publishDirectory()
    {
        $base = $this->baseUrl();
        if ($base === '') {
            throw new \RuntimeException('Set the PBX address in Phone Content before publishing the directory.');
        }
        $this->publishPhoneEndpoint();
        $labels = $this->directoryLabels();
        $contacts = $this->contacts();
        usort($contacts, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        // Directory screens handle only a limited number of items per request.
        // Single-page directory for <= 30 contacts; otherwise link to paged XML.
        $chunks = array_chunk($contacts, 30);
        if (!$chunks) $chunks = array(array());
        // Cisco's Directories URL must always return CiscoIPPhoneMenu, not a
        // directory object directly. A menu can then link to one or more
        // CiscoIPPhoneDirectory documents containing up to 32 entries.
        foreach ($chunks as $i => $chunk) {
            list($doc, $root) = $this->xml('CiscoIPPhoneDirectory');
            $this->element($doc, $root, 'Title', $labels['contacts_title']);
            $this->element($doc, $root, 'Prompt', $labels['contacts_prompt']);
            foreach ($chunk as $entry) {
                $row = $root->appendChild($doc->createElement('DirectoryEntry'));
                $this->element($doc, $row, 'Name', $entry['name']);
                $this->element($doc, $row, 'Telephone', $entry['number']);
            }
            $this->publishFile($this->public . '/contacts-' . ($i + 1) . '.xml', $doc->saveXML());
        }

        list($doc, $root) = $this->xml('CiscoIPPhoneMenu');
        $this->element($doc, $root, 'Title', $labels['menu_title']);
        $this->element($doc, $root, 'Prompt', $labels['menu_prompt']);
        // The Cisco handset renders its own Missed/Received/Placed Calls.
        // Do not echo them into the remote XML menu: that creates duplicates.
        // The phone book is the first (and normally only) custom item.
        foreach ($chunks as $i => $chunk) {
            $item = $root->appendChild($doc->createElement('MenuItem'));
            $label = count($chunks) === 1 ? $labels['menu_entry'] :
                $labels['menu_entry'] . ' ' . ($i * 30 + 1) . '-' . ($i * 30 + count($chunk));
            $this->element($doc, $item, 'Name', $label);
            $this->element($doc, $item, 'URL', $base . '/phone.php?doc=contacts-' . ($i + 1));
        }
        $this->publishFile($this->public . '/directory.xml', $doc->saveXML());

        // Retire old pages when the directory shrinks. Otherwise deleted
        // contacts remain available at old static URLs.
        foreach (glob($this->public . '/contacts-*.xml') ?: array() as $oldPage) {
            if (!preg_match('/^contacts-([0-9]+)\.xml$/D', basename($oldPage), $match)) continue;
            if ((int) $match[1] > count($chunks)) {
                if (is_file($oldPage) && !is_link($oldPage)) @unlink($oldPage);
            }
        }
        // Optional Services button integration, without needing a separate server.
        list($doc, $root) = $this->xml('CiscoIPPhoneMenu');
        $this->element($doc, $root, 'Title', $labels['services_title']);
        $this->element($doc, $root, 'Prompt', $labels['services_prompt']);
        $item = $root->appendChild($doc->createElement('MenuItem'));
        $this->element($doc, $item, 'Name', $labels['services_entry']);
        $this->element($doc, $item, 'URL', $base . '/phone.php?doc=directory');
        $this->publishFile($this->public . '/services.xml', $doc->saveXML());
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
        foreach (array('dev_directoryURL' => '/phone.php?doc=directory',
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
        $xml = $this->public . '/directory.xml';
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
            'expected_url' => $base ? $base . '/phone.php?doc=directory' : '',
            'url_matches' => $url !== '' && $url === $base . '/phone.php?doc=directory',
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
            case 'directory_text': $this->saveDirectoryLabels($post['directory_text'] ?? null);
                return 'Directory display text saved.';
            case 'contact': $this->saveContact((string) ($post['id'] ?? ''),
                $post['contact_name'] ?? '', $post['contact_number'] ?? ''); return 'Contact saved.';
            case 'delete_contact': $this->removeContact((string) ($post['id'] ?? '')); return 'Contact removed.';
            case 'wallpaper': $this->addWallpaper($files['media'] ?? null, $post['label'] ?? ''); return 'Wallpaper published.';
            case 'delete_wallpaper': $this->removeWallpaper((string) ($post['id'] ?? '')); return 'Wallpaper removed.';
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
