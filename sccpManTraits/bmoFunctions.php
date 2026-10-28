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
                    // Existing content is live: a contact edit republishes XML
                    // immediately. Updating the PBX address or enabling a
                    // wallpaper regenerates signed phone configuration as part
                    // of that save, without a second "Apply" button.
                    $this->phoneContentMessage = $this->phonecontent->handle($_POST, $_FILES);
                    if (in_array($action, array('address', 'wallpaper'), true)) {
                        $this->phoneContentMessage .= ' ' . $this->phoneContentRegenerate(false);
                    } elseif ($action === 'contact') {
                        // On the initial contact creation, publish the phone
                        // URLs if not yet installed, but do not sign/restart on
                        // subsequent ordinary edits.
                        $urls = $this->phonecontent->syncUrls();
                        if ($urls) {
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
