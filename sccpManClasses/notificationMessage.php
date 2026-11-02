<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Immutable sender-to-notification-system contract.
 *
 * A sender supplies content and zero to three actions. The manager supplies
 * identity, queue state, Clear, push delivery, and the return context.
 * The sender's own application remains responsible for its menus.
 */
final class notificationMessage {
    private $url;
    private $xml;
    private $actions;
    private $sound;

    public function __construct($url, array $actions=[], $xml=null, $sound=null) {
        $url=(string)$url;
        if ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)))
            throw new \InvalidArgumentException('Invalid notification URL');
        if ($xml!==null && (!is_string($xml) || strlen($xml)>262144))
            throw new \InvalidArgumentException('Invalid notification XML');
        if ($xml!==null) {
            $doc=new \DOMDocument('1.0','UTF-8');
            if (!@$doc->loadXML($xml,LIBXML_NONET) || !$doc->documentElement ||
                !in_array($doc->documentElement->tagName,
                    ['CiscoIPPhoneImageFile'],true))
                throw new \InvalidArgumentException('Notification XML is not a supported Cisco document');
        }
        if ($xml===null && ($sound===null || $sound===''))
            throw new \InvalidArgumentException('Notification requires content or sound');
        if ($xml===null && $url!=='')
            throw new \InvalidArgumentException('Visual notifications require graphical XML');
        if (count($actions)>3)
            throw new \InvalidArgumentException('Notifications support up to three custom actions');
        foreach ($actions as $name=>$destination) {
            if (!is_string($name) || trim($name)==='' || strlen($name)>12 ||
                !is_string($destination) || $destination==='')
                throw new \InvalidArgumentException('Invalid notification action');
        }
        if ($sound!==null && $sound!=='' &&
            !preg_match('/^[A-Za-z0-9_.-]+\\.raw$/D',(string)$sound))
            throw new \InvalidArgumentException('Invalid notification sound filename');
        $this->url=$url;
        $this->xml=$xml;
        $this->actions=$actions;
        $this->sound=$sound;
    }
    public function payload() {
        return ['url'=>$this->url,'xml'=>$this->xml,
                'actions'=>$this->actions,'sound'=>$this->sound];
    }
}        if ($xml===null)
            throw new \InvalidArgumentException('Notification requires graphical XML');
        if (count($actions)>3)
            throw new \InvalidArgumentException('Notifications support up to three custom actions');
        foreach ($actions as $name=>$destination) {
            if (!is_string($name) || trim($name)==='' || strlen($name)>12 ||
                !is_string($destination) || $destination==='')
                throw new \InvalidArgumentException('Invalid notification action');
        }
        if ($sound!==null && $sound!=='' &&
            !preg_match('/^[A-Za-z0-9_.-]+\\.raw$/D',(string)$sound))
            throw new \InvalidArgumentException('Invalid notification sound filename');
        $this->url=$url;
        $this->xml=$xml;
        $this->actions=$actions;
        $this->sound=$sound;
    }
    public function payload() {
        return ['url'=>$this->url,'xml'=>$this->xml,
                'actions'=>$this->actions,'sound'=>$this->sound];
    }
}
