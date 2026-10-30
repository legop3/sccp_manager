<?php
$flags = $this->phonemenus->serviceFlags();
$weather = $this->getConfig('phonecontent_weather');
if (!is_array($weather)) $weather = array('name'=>'Columbus, OH','lat'=>39.9612,'lon'=>-82.9988);
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
<hr>
<h4>Weather location</h4>
<p class="help-block">Set the shared forecast location. Coordinates use decimal degrees.</p>
<div class="row">
<div class="col-sm-4"><label>Location name<input class="form-control" name="weather_name" required maxlength="64" value="<?php echo htmlspecialchars($weather['name'],ENT_QUOTES,'UTF-8'); ?>"></label></div>
<div class="col-sm-3"><label>Latitude<input class="form-control" name="weather_lat" type="number" step="any" min="-90" max="90" required value="<?php echo htmlspecialchars((string)$weather['lat'],ENT_QUOTES,'UTF-8'); ?>"></label></div>
<div class="col-sm-3"><label>Longitude<input class="form-control" name="weather_lon" type="number" step="any" min="-180" max="180" required value="<?php echo htmlspecialchars((string)$weather['lon'],ENT_QUOTES,'UTF-8'); ?>"></label></div>
</div>
<button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> Save Services</button>
</form>
<p class="help-block" style="margin-top:12px">Menu changes apply on the next Services button press. Changes to physical button destinations require updated signed phone provisioning.</p>
</div>
