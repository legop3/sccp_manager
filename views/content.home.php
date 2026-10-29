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
<small class="text-muted">First four normally appear before More. Use the controls below to add, remove, or reorder keys; the text field also accepts a comma-separated layout.</small>
<div class="sccp-home-visual" data-for="home-<?php echo $esc($state); ?>" style="margin-top:8px"></div>
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

<script>
(function(){
const options = <?php echo json_encode($choices, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
function attach(target) {
 const input=document.getElementById(target.getAttribute('data-for'));
 if(!input) return;
 const parse=()=>input.value.split(',').map(k=>k.trim().toLowerCase()).filter(Boolean);
 function render(){
  const keys=parse(); target.replaceChildren();
  const row=document.createElement('div'); row.style.cssText='display:flex;flex-wrap:wrap;gap:6px;margin-bottom:7px';
  keys.forEach((k,i)=>{
   const chip=document.createElement('span');chip.style.cssText='display:inline-flex;gap:3px;align-items:center;border:1px solid #9ca8b4;border-radius:5px;padding:4px 6px';
   const name=document.createElement('span');name.textContent=options[k]||k;chip.appendChild(name);
   function btn(label,title,fn){const b=document.createElement('button');b.type='button';b.textContent=label;b.title=title;b.className='btn btn-default btn-xs';b.addEventListener('click',fn);chip.appendChild(b);}
   btn('←','Move earlier',()=>{if(i>0){[keys[i-1],keys[i]]=[keys[i],keys[i-1]];input.value=keys.join(',');render();}});
   btn('→','Move later',()=>{if(i<keys.length-1){[keys[i+1],keys[i]]=[keys[i],keys[i+1]];input.value=keys.join(',');render();}});
   btn('×','Remove',()=>{keys.splice(i,1);input.value=keys.join(',');render();});
   row.appendChild(chip);
  });
  target.appendChild(row);
  const pick=document.createElement('select');pick.className='form-control';pick.style.cssText='width:auto;display:inline-block;max-width:220px;margin-right:7px';
  const placeholder=document.createElement('option');placeholder.value='';placeholder.textContent='Add a softkey…';pick.appendChild(placeholder);
  Object.entries(options).forEach(([key,label])=>{if(keys.includes(key))return;const o=document.createElement('option');o.value=key;o.textContent=label;pick.appendChild(o);});
  target.appendChild(pick);
  const add=document.createElement('button');add.type='button';add.className='btn btn-default btn-sm';add.textContent='Add';add.addEventListener('click',()=>{if(pick.value&&keys.length<15){keys.push(pick.value);input.value=keys.join(',');render();}});
  target.appendChild(add);
 }
 input.addEventListener('change',render);render();
}
document.querySelectorAll('.sccp-home-visual').forEach(attach);
})();
</script>
