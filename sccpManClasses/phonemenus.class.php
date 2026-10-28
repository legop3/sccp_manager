<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Shared Cisco 79xx button menus. The database describes the menus; this
 * class publishes Cisco XML and applies service provisioning to signed SEP XML.
 * Disabled by default so existing phones keep their original behavior.
 */
class phonemenus
{
    private $parent;

    public function __construct($parent = null) { $this->parent = $parent; }

    public static function types()
    {
        return array(
            'phonebook' => 'Managed Address Book',
            'missed' => 'Missed Calls (native)',
            'received' => 'Received Calls (native)',
            'placed' => 'Placed Calls (native)',
            'voicemail' => 'Voicemail (native)',
            'url' => 'Cisco XML URL',
            'dial' => 'Dial Number',
            'text' => 'Text Page',
            'submenu' => 'Submenu'
        );
    }

    public function defaults()
    {
        $labels = $this->parent->phonecontent->directoryLabels();
        return array(
            'enabled' => false,
            'directories' => array(
                array('name' => $labels['menu_entry'], 'type' => 'phonebook', 'value' => '')
            ),
            'services' => array(
                array('name' => $labels['services_entry'], 'type' => 'phonebook', 'value' => '')
            ),
            'messages' => array(
                array('name' => 'Voicemail', 'type' => 'voicemail', 'value' => '')
            ),
            'submenus' => array()
        );
    }

    public function config()
    {
        $saved = $this->parent->getConfig('phonecontent_menus');
        return is_array($saved) ? $saved : $this->defaults();
    }

    private function label($text, $max = 48)
    {
        if (!is_string($text)) throw new \InvalidArgumentException('Expected text.');
        $text = trim($text);
        if ($text === '' || strlen($text) > $max || !preg_match('//u', $text) ||
            preg_match('/[\x00-\x1F\x7F]/', $text)) {
            throw new \InvalidArgumentException('Menu names must be 1–' . $max . ' UTF-8 bytes without control characters.');
        }
        return $text;
    }

    private function validateRows($rows, $section, $submenuIds)
    {
        if (!is_array($rows) || count($rows) > 25) {
            throw new \InvalidArgumentException('A menu must contain at most 25 entries.');
        }
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('Invalid menu entry.');
            $type = $row['type'] ?? '';
            if (!is_string($type) || !isset(self::types()[$type])) {
                throw new \InvalidArgumentException('Unknown menu entry type.');
            }
            $name = $this->label($row['name'] ?? null);
            $value = $row['value'] ?? '';
            if (!is_string($value)) throw new \InvalidArgumentException('Invalid menu entry value.');
            $value = trim($value);
            if (!preg_match('//u', $value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException('Invalid characters in menu entry.');
            }
            if ($section === 'messages' && !in_array($type, array('voicemail', 'url'), true)) {
                throw new \InvalidArgumentException('Messages supports the native voicemail application or an XML service URL.');
            }
            if ($section !== 'messages' && $type === 'voicemail') {
                throw new \InvalidArgumentException('Native Voicemail belongs under Messages.');
            }
            if ($section === 'submenu' && $type === 'submenu') {
                throw new \InvalidArgumentException('Nested submenus are limited to one level.');
            }
            switch ($type) {
                case 'url':
                    $parts = parse_url($value);
                    if (strlen($value) > 240 || $parts === false ||
                        !in_array(strtolower($parts['scheme'] ?? ''), array('http','https'), true) ||
                        empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                        throw new \InvalidArgumentException('XML URL must be an HTTP(S) URL (max 240 characters).');
                    }
                    break;
                case 'dial':
                    if (!preg_match('/^[0-9*#]{1,32}$/D', $value)) {
                        throw new \InvalidArgumentException('Dial numbers may contain only digits, * and # (max 32).');
                    }
                    break;
                case 'text':
                    if ($value === '' || strlen($value) > 1200) {
                        throw new \InvalidArgumentException('Text pages require 1–1200 bytes of text.');
                    }
                    break;
                case 'submenu':
                    if (!in_array($value, $submenuIds, true)) {
                        throw new \InvalidArgumentException('Submenu target no longer exists.');
                    }
                    break;
                default:
                    $value = '';
            }
            $out[] = array('name' => $name, 'type' => $type, 'value' => $value);
        }
        return $out;
    }

    public function save($json)
    {
        if (!is_string($json) || strlen($json) > 40000) {
            throw new \InvalidArgumentException('Menu configuration is too large.');
        }
        $incoming = json_decode($json, true);
        if (!is_array($incoming)) throw new \InvalidArgumentException('Invalid menu configuration.');
        $subs = $incoming['submenus'] ?? array();
        if (!is_array($subs) || count($subs) > 12) {
            throw new \InvalidArgumentException('At most 12 submenus are supported.');
        }
        $ids = array();
        foreach ($subs as $sub) {
            $id = $sub['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9-]{0,23}$/D', $id) || in_array($id, $ids, true)) {
                throw new \InvalidArgumentException('Invalid or duplicate submenu ID.');
            }
            $ids[] = $id;
        }
        $out = array('enabled' => ($incoming['enabled'] ?? false) === true);
        foreach (array('directories', 'services', 'messages') as $section) {
            $out[$section] = $this->validateRows($incoming[$section] ?? null, $section, $ids);
        }
        if (!$out['messages'] || count($out['messages']) !== 1) {
            throw new \InvalidArgumentException('Messages requires exactly one entry to keep the Messages button working.');
        }
        if (!$out['directories'] || !$out['services']) {
            throw new \InvalidArgumentException('Directories and Services must each contain at least one entry.');
        }
        $out['submenus'] = array();
        foreach ($subs as $sub) {
            $out['submenus'][] = array(
                'id' => $sub['id'],
                'name' => $this->label($sub['name'] ?? null),
                'items' => $this->validateRows($sub['items'] ?? null, 'submenu', $ids)
            );
        }
        $old = $this->parent->getConfig('phonecontent_menus');
        // Fail before changing stored settings if the publishing directory is unwritable.
        $this->parent->setConfig('phonecontent_menus', $out);
        try {
            $this->parent->phonecontent->publishDirectory();
        } catch (\Throwable $e) {
            $this->parent->setConfig('phonecontent_menus', $old);
            throw $e;
        }
    }

    private function urlFor($item, $section, $index, $base)
    {
        switch ($item['type']) {
            case 'phonebook':
                return $base . '/phone.php?doc=' .
                    (count($this->parent->phonecontent->contacts()) <= 30 ? 'contacts-1' : 'phonebook');
            case 'missed': return 'Application:Cisco/MissedCalls';
            case 'received': return 'Application:Cisco/ReceivedCalls';
            case 'placed': return 'Application:Cisco/PlacedCalls';
            case 'voicemail': return 'Application:Cisco/Voicemail';
            case 'url': return $item['value'];
            case 'dial': return 'Dial:' . $item['value'];
            case 'text': return $base . '/phone.php?doc=pc-text-' . $section . '-' . $index;
            case 'submenu': return $base . '/phone.php?doc=pc-sub-' . $item['value'];
        }
        throw new \LogicException('Unexpected menu type.');
    }

    private function child(\DOMDocument $doc, \DOMElement $parent, $name, $value)
    {
        $node = $doc->createElement($name);
        $node->appendChild($doc->createTextNode((string) $value));
        $parent->appendChild($node);
        return $node;
    }

    private function menuXml($items, $title, $prompt, $section, $base)
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->appendChild($doc->createElement('CiscoIPPhoneMenu'));
        $this->child($doc, $root, 'Title', $title);
        $this->child($doc, $root, 'Prompt', $prompt);
        foreach ($items as $i => $item) {
            $entry = $root->appendChild($doc->createElement('MenuItem'));
            $this->child($doc, $entry, 'Name', $item['name']);
            $this->child($doc, $entry, 'URL', $this->urlFor($item, $section, $i, $base));
        }
        return $doc->saveXML();
    }

    /**
     * Rebuilds legacy XML menus and supporting pages. No phone reboot is
     * required to see updated HTTP content.
     */
    public function publish()
    {
        $config = $this->config();
        $base = $this->parent->phonecontent->baseUrl();
        if ($base === '') throw new \RuntimeException('Set the Phone Content PBX IPv4 address first.');
        $labels = $this->parent->phonecontent->directoryLabels();
        $files = array();
        $files['directory.xml'] = $this->menuXml($config['directories'], $labels['menu_title'], $labels['menu_prompt'], 'directories', $base);
        $files['services.xml'] = $this->menuXml($config['services'], $labels['services_title'], $labels['services_prompt'], 'services', $base);
        foreach ($config['submenus'] as $sub) {
            $files['pc-sub-' . $sub['id'] . '.xml'] = $this->menuXml($sub['items'], $sub['name'], 'Select an item', 'sub-' . $sub['id'], $base);
        }
        foreach (array_merge(array(
            'directories' => $config['directories'],
            'services' => $config['services']
        ), array_reduce($config['submenus'], function ($carry, $sub) {
            $carry['sub-' . $sub['id']] = $sub['items'];
            return $carry;
        }, array())) as $section => $entries) {
            foreach ($entries as $i => $item) {
                if ($item['type'] !== 'text') continue;
                $doc = new \DOMDocument('1.0', 'UTF-8');
                $root = $doc->appendChild($doc->createElement('CiscoIPPhoneText'));
                $this->child($doc, $root, 'Title', $item['name']);
                $this->child($doc, $root, 'Text', $item['value']);
                $files['pc-text-' . $section . '-' . $i . '.xml'] = $doc->saveXML();
            }
        }
        foreach ($files as $filename => $xml) {
            $this->parent->phonecontent->publishMenuDocument($filename, $xml);
        }
        // Remove only generated helper pages, not custom files in the web root.
        foreach (glob($this->parent->phonecontent->publicPath() . '/pc-*.xml') ?: array() as $path) {
            $file = basename($path);
            if (!isset($files[$file]) && preg_match('/^pc-(sub|text)-[a-z0-9-]+\.xml$/D', $file)
                && is_file($path) && !is_link($path)) @unlink($path);
        }
    }

    /** Inject into final per-phone SEP document BEFORE the existing signing step. */
    public function applyProvisioning(\SimpleXMLElement $phone)
    {
        $config = $this->config();
        if (empty($config['enabled'])) return;
        $base = $this->parent->phonecontent->baseUrl();
        if ($base === '') throw new \RuntimeException('Phone Content PBX address is required for custom menus.');
        $root = dom_import_simplexml($phone);
        $doc = $root->ownerDocument;
        foreach (iterator_to_array($root->getElementsByTagName('phoneServices')) as $existing) {
            if ($existing->parentNode === $root) $root->removeChild($existing);
        }
        $services = $root->appendChild($doc->createElement('phoneServices'));
        $this->child($doc, $services, 'provisioning', '0');
        foreach (array('directories' => '1', 'services' => '0', 'messages' => '2') as $section => $type) {
            foreach ($config[$section] as $i => $item) {
                $entry = $services->appendChild($doc->createElement('phoneService'));
                $entry->setAttribute('type', $type);
                $entry->setAttribute('category', '0');
                $this->child($doc, $entry, 'name', $item['name']);
                $this->child($doc, $entry, 'url', $this->urlFor($item, $section, $i, $base));
                $this->child($doc, $entry, 'vendor', '');
                $this->child($doc, $entry, 'version', '');
            }
        }
    }
}
