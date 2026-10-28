<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}
$display_info = _('Phone Content');
$display_page = \FreePBX::create()->Sccp_manager->phoneContentShowPage();
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
    if (['general', 'wallpapers', 'ringtones'].indexOf(tab) !== -1) {
        $(function () {
            $('.change-tab a[href="#' + tab + '"]').tab('show');
        });
    }
})();
</script>
