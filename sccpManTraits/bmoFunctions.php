<?php

namespace FreePBX\modules\Sccp_manager\sccpManTraits;

trait bmoFunctions {

    //Need to reload freePBX for modifications below to work
/*
    //want to catch extensions
    public static function myConfigPageInits() {
        return array("extensions");
    }
*/

    public function doConfigPageInit($page) {
        if ($page === 'sccp_content') {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
                isset($_POST['content_action'])) {
                try {
                    $action = (string) $_POST['content_action'];
                    if ($action === 'notifications_preview') {
                        // Authorized FreePBX admin endpoint. Render using the exact
                        // same GD method as the outgoing Cisco notification.
                        header('Content-Type: application/json; charset=UTF-8');
                        header('Cache-Control: no-store');
                        try {
                            $kind=(string)($_POST['kind']??'');
                            if (!in_array($kind,array('voicemail','missed'),true)) throw new \InvalidArgumentException('Invalid preview kind.');
                            $base=(new \FreePBX\modules\Sccp_manager\notificationConfig($this))->all()[$kind];
                            $submitted=(array)($_POST['appearance']??array());
                            $base['theme']=in_array(($submitted['theme']??''),array('cisco','ocean','charcoal','plum','forest','custom'),true)?$submitted['theme']:$base['theme'];
                            foreach (array('show_number','show_details') as $key) $base[$key]=!empty($submitted[$key]);
                            $base['stripe_position']=in_array(($submitted['stripe_position']??''),array('off','header','top','bottom','background'),true)?$submitted['stripe_position']:'off';
                            $base['stripes']=array();
                            foreach (array_slice((array)($submitted['stripes']??array()),0,10) as $hex)
                                if (is_string($hex)&&preg_match('/^#[0-9a-f]{6}$/i',$hex)) $base['stripes'][]=$hex;
                            foreach (array('background','header','header_text','name_text','secondary_text','details_background','details_label','details_text','accent') as $key) {
                                $hex=(string)($submitted['colors'][$key]??'');
                                if (preg_match('/^#[0-9a-f]{6}$/i',$hex)) $base['colors'][$key]=$hex;
                            }
                            $sample=\FreePBX\modules\Sccp_manager\phoneNotifications::previewFields($this,$kind);
                            $xml=\FreePBX\modules\Sccp_manager\phoneNotifications::graphic($this,$kind,$sample,array(),$base);
                            if (!is_string($xml)||!preg_match('~<URL>([^<]+)</URL>~',$xml,$m)) throw new \RuntimeException('Preview renderer failed.');
                            echo json_encode(array('url'=>html_entity_decode($m[1],ENT_QUOTES,'UTF-8')));
                        } catch (\Throwable $e) {
                            http_response_code(400);
                            echo json_encode(array('error'=>$e->getMessage()));
                        }
                        exit;
                    }

                    // Existing content is live: a contact edit republishes XML
                    // immediately. Updating the PBX address or enabling a
                    // wallpaper regenerates signed phone configuration as part
                    // of that save, without a second "Apply" button.
                    $this->phoneContentMessage = $this->phonecontent->handle($_POST, $_FILES);
                    if (in_array($action, array('address', 'wallpaper', 'save_menus'), true)) {
                        $this->phoneContentMessage .= ' ' . $this->phoneContentRegenerate(false);
                    } elseif ($action === 'contact') {
                        // On the initial contact creation, publish the phone
                        // URLs if not yet installed, but do not sign/restart on
                        // subsequent ordinary edits.
                        $urls = $this->phonecontent->syncUrls();
                        if ($urls || count($this->phonecontent->contacts()) === 1) {
                            $this->phoneContentMessage .= ' ' . $this->phoneContentRegenerate(false);
                        }
                    }
                } catch (\Throwable $e) {
                    $this->phoneContentError = $e->getMessage();
                }
            }
            return;
        }
        if ($page === 'sccp_vpn' || ($page === 'sccpsettings' &&
            (isset($_POST['save_vpn']) || isset($_POST['vpn_action'])))) {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
                ($_POST['save_vpn'] ?? '') === '1') {
                try {
                    $this->vpnSaveFromPost($_POST);
                } catch (\Throwable $e) {
                    $this->vpnPageError = $e->getMessage();
                }
            } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
                      ($_POST['vpn_action'] ?? '') !== '') {
                try {
                    switch ($_POST['vpn_action']) {
                        case 'start': $this->vpnStartService(); break;
                        case 'regenerate': $this->vpnRegenerateSelected(); break;
                        case 'account': $this->vpnSetAccount($_POST); break;
                        case 'delete_account': $this->vpnDeleteAccount($_POST); break;
                        default: throw new \InvalidArgumentException('Unknown VPN operation');
                    }
                } catch (\Throwable $e) {
                    $this->vpnPageError = $e->getMessage();
                }
            }
            return;
        }
        if ($page == "extensions") {
        }
        $this->doGeneralPost();
    }

    // Try to change extensions which is part of core
/*
    public static function myGuiHooks() {
        return array('core');
    }
*/
/*
    public function doGuiHook(&$cc) {
        if ($_REQUEST['display'] == "extensions" ) {
      			if ($_REQUEST['tech_hardware'] == 'sccp_custom')  {

                this is the add extensions form
                On submit returns to extensions page. Users prefer that it returns
                To Sccp Phone.
                Below adds redirect URL, but it is not followed
                $cc->setRedirectURL("config.php?display=sccp_phone");
                so force redirect at end of addDevice in SccpClass

            }
        }
    }
*/
    /* unused but FPBX API requires it */

    public function install() {

    }

    /* unused but FPBX API requires it */

    public function uninstall() {

    }

    /* unused but FPBX API requires it */

    public function backup() {

    }

    /* unused but FPBX API requires it */

    public function restore($backup) {

    }

    public function getActionBar($request) {
        $buttons = array();
        switch ($request['display']) {
            case 'sccp_adv':
                if (empty($request['tech_hardware'])) {
                    break;
                }
                $buttons = array(
                    'submit' => array(
                        'name' => 'ajaxsubmit',
                        'id' => 'ajaxsubmit',
                        'value' => _("Save")
                    ),
                    'Save' => array(
                        'name' => 'ajaxsubmit2',
                        'id' => 'ajaxsubmit2',
                        'stayonpage' => 'yes',
                        'value' => _("Save + Continue")
                    ),
                    'cancel' => array(
                        'name' => 'cancel',
                        'id' => 'ajaxcancel',
                        'data-search' => '?display=sccp_adv',
                        'data-hash' => 'sccpdialplan',
                        'value' => _("Cancel")
                    ),
                );
                break;
            case 'sccp_phone':
                if (empty($request['tech_hardware'])) {
                    break;
                }
                $buttons = array(
                    'submit' => array(
                        'name' => 'ajaxsubmit',
                        'id' => 'ajaxsubmit',
                        'value' => _("Save")
                    ),
                    'Save' => array(
                        'name' => 'ajaxsubmit2',
                        'id' => 'ajaxsubmit2',
                        'stayonpage' => 'yes',
                        'value' => _("Save + Continue")
                    ),
                    'cancel' => array(
                        'name' => 'cancel',
                        'id' => 'ajaxcancel',
                        'data-search' => '?display=sccp_phone',
                        'data-hash' => 'sccpdevice',
                        'value' => _("Cancel")
                    ),
                );
                break;
            case 'sccpsettings':
                // TODO: Need to change to have save and save and continue
                $buttons = array(
                    'submit' => array(
                        'name' => 'ajaxsubmit',
                        'id' => 'ajaxsubmit',
                        'value' => _("Save")
                    ),
                    'reset' => array(
                        'name' => 'reset',
                        'id' => 'ajaxcancel',
                        'data-reload' => 'reload',
                        'value' => _("Cancel")
                    ),
                );
                break;
        }
        return $buttons;
    }

    public function getRightNav($request) {
        global $amp_conf;
        if (isset($request['tech_hardware']) && ($request['tech_hardware'] == 'cisco')) {
            return load_view($amp_conf['AMPWEBROOT'] .'/admin/modules/sccp_manager/views/hardware.rnav.php', array('request' => $request));
        }
    }

    public function doGeneralPost() {
        if (!isset($_REQUEST['Submit'])) {
            return;
        }
    }
}
?>
