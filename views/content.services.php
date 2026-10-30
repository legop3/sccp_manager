<?php
$flags = $this->phonemenus->serviceFlags();
$names = array('weather' => 'Weather', 'messages' => 'Messages',
    'alarms' => 'Alarms & Reminders', 'news' => 'News & RSS', 'games' => 'Games');
?>
<div class="container-fluid">
<h3><?php echo _('Built-in Services'); ?></h3>
<p>Enabled services appear automatically under the physical Services button. The Messages button always opens visual voicemail, and Directories always opens Favorites.</p>
<form method="post" action="config.php?display=sccp_content#services">
<input type="hidden" name="content_action" value="services_save">
<?php foreach ($names as $id => $label): ?>
<div class="checkbox"><label><input type="checkbox" name="services[<?php echo $id; ?>]" value="1" <?php echo !empty($flags[$id]) ? 'checked' : ''; ?>>
<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></label></div>
<?php endforeach; ?>
<button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> Save Services</button>
</form>
<p class="help-block" style="margin-top:12px">Menu changes apply on the next Services button press. Changes to physical button destinations require updated signed phone provisioning.</p>
</div>
