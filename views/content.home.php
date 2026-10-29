<?php
$home = new \FreePBX\modules\Sccp_manager\homeScreenConfig($this);
$config = $home->get();
$choices = $home::options();
$names = array('onhook'=>'Idle / On Hook','offhook'=>'Off Hook','offhookfeat'=>'Off Hook Features',
'digitsfoll'=>'Dialing Digits','ringout'=>'Outgoing Ringing','connected'=>'Connected Call',
'conntrans'=>'Transfer in Progress','connconf'=>'Conference','onhold'=>'Call on Hold',
'ringin'=>'Incoming Call','onhint'=>'Busy Line / Hint','onstealable'=>'Stealable Call',
'holdconf'=>'Held Conference');
$esc = static function($value) {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');};
?>
<div class="container-fluid">
<h2>Home Screen &amp; Native Softkeys</h2>
<p>This controls the native SCCP buttons displayed along the bottom of Cisco phones, not XML application softkeys. Buttons use their real chan_sccp actions and firmware labels; renaming an action is not supported.</p>
<form method="post" action="config.php?display=sccp_content#home">
<input type="hidden" name="content_action" value="home_softkeys_save">
<label style="display:block;margin:16px 0"><input type="checkbox" name="home[enabled]" value="1" <?php echo $config['enabled']?'checked':''; ?>>
 Enable the managed native softkey layout</label>
<p class="text-muted">When enabled, SCCP Manager writes the <code><?php echo $esc($home::SET_NAME); ?></code> softkey set into generated <code>sccp.conf</code>. Assign this named set to phones in their Soft Keyset setting. When disabled, existing phone softkey sets remain unchanged.</p>
<div class="row">
<?php foreach ($names as $state=>$name): ?>
<div class="col-lg-6 col-md-6" style="margin-bottom:16px">
<label for="home-<?php echo $esc($state); ?>"><?php echo $esc($name); ?> <small>(<?php echo $esc($state); ?>)</small></label>
<input class="form-control sccp-home-layout" id="home-<?php echo $esc($state); ?>"
 name="home[states][<?php echo $esc($state); ?>]" maxlength="240" type="text"
 value="<?php echo $esc(implode(',',$config['states'][$state])); ?>"
 data-state="<?php echo $esc($state); ?>">
<small class="text-muted">Comma-separated keys in display order. First four are shown before More on most 7945G screens.</small>
</div>
<?php endforeach; ?>
</div>
<p><strong>Available native actions:</strong> <?php foreach ($choices as $key=>$label): ?><code title="<?php echo $esc($label); ?>"><?php echo $esc($key); ?></code> <?php endforeach; ?></p>
<button class="btn btn-primary" type="submit">Save native softkeys</button>
</form>
<hr>
<h3>About the home-screen message</h3>
<p>The firmware-generated <em>Your Current Options</em> and <em>You Have VoiceMail</em> prompts are not part of the native softkey layout. The existing Phone Content notification system can show custom status messages, but replacing these firmware prompts permanently is not verified. This editor deliberately leaves message-waiting indication intact.</p>
</div>
