<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}
$display_info = _('Phone Content');
$module = \FreePBX::create()->Sccp_manager;
if (($_SERVER['REQUEST_METHOD']??'')==='POST' &&
    in_array((string)($_POST['content_action']??''),['media_upload','media_delete'],true)) {
    try {
        $module->phoneContentMessage=$module->phonecontent->handle($_POST,$_FILES);
    } catch (\Throwable $e) {
        $module->phoneContentError=$e->getMessage();
    }
}
$display_page = $module->phoneContentShowPage();
if (!empty(\FreePBX::create()->Sccp_manager->phoneContentError)) {
    echo '<div class="alert alert-danger">' .
        htmlspecialchars(\FreePBX::create()->Sccp_manager->phoneContentError, ENT_QUOTES, 'UTF-8') . '</div>';
}
if (!empty(\FreePBX::create()->Sccp_manager->phoneContentMessage)) {
    echo '<div class="alert alert-success">' .
        htmlspecialchars(\FreePBX::create()->Sccp_manager->phoneContentMessage, ENT_QUOTES, 'UTF-8') . '</div>';
}
include('page.html.php');
?>
<script>
(function () {
    // After a normal multipart form POST, keep the tab the user was editing.
    var tab = window.location.hash.slice(1);
    if (['general', 'services', 'wallpapers', 'ringtones', 'notifications'].indexOf(tab) !== -1) {
        $(function () {
            $('.change-tab a[href="#' + tab + '"]').tab('show');
        });
    }
})();
</script>
