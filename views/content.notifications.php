<?php
$esc=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$notifications=(new \FreePBX\modules\Sccp_manager\notificationConfig($this))->all();
$rings=$this->phonecontent->ringtones();
?>
<div class="container-fluid">
<h3>Notifications</h3>
<p>Voicemail, missed calls and scheduled content. Phone's native voicemail indicators are unchanged.</p>
<form method="post" action="config.php?display=sccp_content#notifications">
<input type="hidden" name="content_action" value="notifications_save">
<?php foreach ($notifications as $kind=>$settings): ?>
<fieldset class="panel panel-default" style="padding:16px;margin:12px 0">
<legend style="width:auto;padding:0 10px"><?php echo $esc(ucfirst($kind)); ?></legend>
<label><input type="checkbox" name="notifications[<?php echo $kind;?>][enabled]" value="1" <?php echo $settings['enabled']?'checked':''; ?>> Enable</label>
<div class="row">
<div class="col-sm-5 form-group"><label>Title</label><input class="form-control" name="notifications[<?php echo $kind;?>][title]" maxlength="500" value="<?php echo $esc($settings['title']); ?>"></div>
<div class="col-sm-7 form-group"><label>Message</label><textarea class="form-control" rows="2" name="notifications[<?php echo $kind;?>][message]"><?php echo $esc($settings['message']); ?></textarea></div>
</div>
<div class="row">
<div class="col-sm-4 form-group"><label>Display</label><select class="form-control" name="notifications[<?php echo $kind;?>][style]">
<?php foreach (['text'=>'Full-screen text','status'=>'Small status popup','image'=>'Image with text (scheduled)','sound'=>'Sound only'] as $value=>$label): ?>
<option value="<?php echo $value;?>" <?php echo $settings['style']===$value?'selected':''; ?>><?php echo $esc($label);?></option>
<?php endforeach; ?></select></div>
<div class="col-sm-4 form-group"><label>Sound</label><select class="form-control" name="notifications[<?php echo $kind;?>][sound]">
<option value="">Silent</option><?php foreach ($rings as $ring):?>
<option value="<?php echo $esc($ring['id']);?>" <?php echo $settings['sound']===$ring['id']?'selected':'';?>><?php echo $esc($ring['name']);?></option>
<?php endforeach;?></select></div>
<div class="col-sm-4 form-group"><label>Duration (seconds)</label><input class="form-control" type="number" min="2" max="60" name="notifications[<?php echo $kind;?>][duration]" value="<?php echo (int)$settings['duration'];?>"></div>
</div>
<?php if ($kind==='scheduled'): ?>
<div class="form-group"><label>Built-in content source</label><select class="form-control" name="notifications[scheduled][source]">
<?php foreach (['custom'=>'Custom title, message and URLs','dog'=>'Random dog photo + breed','xkcd'=>'Latest xkcd comic + caption'] as $value=>$label): ?>
<option value="<?php echo $esc($value); ?>" <?php echo ($settings['source']??'custom')===$value?'selected':''; ?>><?php echo $esc($label); ?></option>
<?php endforeach; ?></select><p class="help-block">Built-in sources fetch an updated image and caption at send time. Select Image with text as the display type.</p></div>
<div class="row">
<div class="col-sm-6 form-group"><label>Repeat every X minutes (when no daily time)</label><input class="form-control" type="number" min="1" max="10080" name="notifications[scheduled][interval]" value="<?php echo (int)$settings['interval'];?>"></div>
<div class="col-sm-6 form-group"><label>Or daily at HH:MM</label><input class="form-control" type="time" name="notifications[scheduled][time]" value="<?php echo $esc($settings['time']);?>"></div>
</div>
<?php foreach (['title_url'=>'Title text URL','message_url'=>'Message text URL','image_url'=>'Image URL'] as $key=>$label):?>
<div class="form-group"><label><?php echo $esc($label);?></label><input class="form-control" type="url" name="notifications[scheduled][<?php echo $key;?>]" value="<?php echo $esc($settings[$key]);?>" placeholder="https://"></div>
<?php endforeach;?>
<?php endif;?>
</fieldset>
<?php endforeach;?>
<p class="help-block">Image display is intended for scheduled notifications with an Image URL. Text is rendered beneath the image.</p><p class="help-block">Voicemail placeholders: {caller}, {number}, {received}, {date}, {time}, {length}, {extension}, {count}. Missed call: {caller}, {number}, {when}, {date}, {time}, {reason}, {extension}. Names and durations appear when available in Asterisk records. Sounds reuse uploaded ringtones.</p>
<button type="submit" class="btn btn-primary">Save notification settings</button>
</form>
<form method="post" action="config.php?display=sccp_content#notifications" style="margin-top:15px">
<input type="hidden" name="content_action" value="notifications_test">
<button type="submit" class="btn btn-default">Send test scheduled notification to all phones</button>
</form>
</div>