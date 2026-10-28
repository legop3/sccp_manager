<?php
$esc=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$notifications=(new \FreePBX\modules\Sccp_manager\notificationConfig($this))->all();
$rings=$this->phonecontent->ringtones();
$palettes=[
 'rainbow'=>['#E40303','#FF8C00','#FFED00','#008026','#24408E','#732982'],
 'progress'=>['#E40303','#FF8C00','#FFED00','#008026','#24408E','#732982','#FFFFFF','#F5A9B8','#5BCEFA','#000000'],
 'trans'=>['#5BCEFA','#F5A9B8','#FFFFFF','#F5A9B8','#5BCEFA'],
 'bi'=>['#D60270','#D60270','#9B4F96','#0038A8','#0038A8'],
 'pan'=>['#FF218C','#FFD800','#21B1FF'],
 'lesbian'=>['#D52D00','#EF7627','#FF9A56','#FFFFFF','#D162A4','#B55690','#A30262'],
 'nonbinary'=>['#FFF430','#FFFFFF','#9C59D1','#2D2D2D'],
 'ace'=>['#000000','#A3A3A3','#FFFFFF','#800080'],
 'custom'=>[]
];
$colorLabels=['background'=>'Main background','header'=>'Header background','header_text'=>'Header text','name_text'=>'Caller name','secondary_text'=>'Phone number','details_background'=>'Details background','details_label'=>'Detail labels','details_text'=>'Detail values','accent'=>'Divider accent'];
$samples=[
 'voicemail'=>['caller'=>'Jane Smith','number'=>'555-0123','time'=>'2:35 PM','length'=>'0:42'],
 'missed'=>['caller'=>'John Smith','number'=>'555-0199','when'=>'Oct 28 3:14 PM']
];
?>
<style>
.sccp-notify-editor{max-width:1200px;margin:auto}.sccp-notify-editor .ne-top{margin-bottom:20px}
.sccp-notify-editor .ne-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.sccp-notify-editor .ne-tabs button{padding:11px 19px;border:1px solid #9ca9b5;background:transparent;border-radius:8px}
.sccp-notify-editor .ne-tabs button.active{background:#26649b;color:white}
.sccp-notify-editor .ne-panel{display:none}.sccp-notify-editor .ne-panel.active{display:block}
.sccp-notify-editor .ne-grid{display:grid;grid-template-columns:minmax(300px,1fr) minmax(300px,1fr);gap:22px}
.sccp-notify-editor .ne-card{border:1px solid #c4cbd2;border-radius:10px;padding:19px;margin-bottom:16px}
.sccp-notify-editor .ne-card h4{margin:0 0 15px}.sccp-notify-editor .ne-colors{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.sccp-notify-editor .ne-colors label,.sccp-notify-editor .ne-stripe-row{display:flex;align-items:center;justify-content:space-between;gap:8px}
.sccp-notify-editor input[type=color]{min-width:60px;width:70px;height:35px;padding:2px;cursor:pointer}
.sccp-notify-editor .ne-screen{display:block;width:100%;max-width:592px;aspect-ratio:296/156;image-rendering:auto;border-radius:5px;border:3px solid #666}
.sccp-notify-editor .ne-stripes{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.sccp-notify-editor .ne-stripe-row{display:inline-flex;padding:4px;border:1px solid #999;border-radius:4px}
.sccp-notify-editor .ne-preset{margin:3px}.sccp-notify-editor .ne-help{font-size:12px;opacity:.75}
@media(max-width:850px){.sccp-notify-editor .ne-grid{grid-template-columns:1fr}}
</style>
<div class="container-fluid sccp-notify-editor">
 <div class="ne-top"><h2>Notification Designer</h2><p>Configure each type separately. Graphical alerts are rendered as 296 × 156 PNG images by the PBX; softkeys remain actual Cisco phone controls.</p></div>
 <div class="ne-tabs" role="tablist">
  <button type="button" class="active" data-ne-tab="voicemail">Voicemail</button>
  <button type="button" data-ne-tab="missed">Missed calls</button>
  <button type="button" data-ne-tab="scheduled">Scheduled content</button>
 </div>
 <form method="post" action="config.php?display=sccp_content#notifications" id="ne-form">
 <input type="hidden" name="content_action" value="notifications_save">
 <?php foreach ($notifications as $kind=>$settings): ?>
 <section class="ne-panel <?php echo $kind==='voicemail'?'active':'';?>" data-ne-panel="<?php echo $kind;?>">
  <div class="ne-grid">
   <div>
    <div class="ne-card">
     <h4><?php echo $esc(['voicemail'=>'New voicemail','missed'=>'Missed call','scheduled'=>'Scheduled message'][$kind]);?></h4>
     <label><input type="checkbox" name="notifications[<?php echo $kind;?>][enabled]" value="1" <?php echo $settings['enabled']?'checked':'';?>> Enable notifications</label>
     <div class="form-group"><label>Sound</label><select class="form-control" name="notifications[<?php echo $kind;?>][sound]">
       <option value="">Silent</option><?php foreach ($rings as $ring): ?>
       <option value="<?php echo $esc($ring['id']);?>" <?php echo $settings['sound']===$ring['id']?'selected':'';?>><?php echo $esc($ring['name']);?></option>
       <?php endforeach;?></select></div>
     <?php if ($kind==='scheduled'): ?>
      <div class="form-group"><label>Display type</label><select class="form-control" name="notifications[scheduled][style]">
       <?php foreach (['text'=>'Full-screen text','status'=>'Small status popup','image'=>'Image with text','sound'=>'Sound only'] as $value=>$label):?>
       <option value="<?php echo $value;?>" <?php echo $settings['style']===$value?'selected':'';?>><?php echo $esc($label);?></option><?php endforeach;?></select></div>
     <?php else: ?>
      <input type="hidden" name="notifications[<?php echo $kind;?>][style]" value="text">
      <input type="hidden" name="notifications[<?php echo $kind;?>][title]" value="<?php echo $esc($settings['title']);?>">
      <input type="hidden" name="notifications[<?php echo $kind;?>][message]" value="<?php echo $esc($settings['message']);?>">
      <input type="hidden" name="notifications[<?php echo $kind;?>][duration]" value="<?php echo (int)$settings['duration'];?>">
      <p class="ne-help">Actions are integrated with FreePBX. Voicemail: Listen, Details, Messages, Dismiss. Missed calls: Call Back, Dismiss.</p>
     <?php endif;?>
    </div>
    <?php if ($kind==='scheduled'): ?>
     <div class="ne-card"><h4>Content & schedule</h4>
      <div class="form-group"><label>Source</label><select class="form-control" name="notifications[scheduled][source]">
       <?php foreach (['custom'=>'Custom URLs or text','dog'=>'Random dog photo and breed','xkcd'=>'Latest xkcd comic and caption'] as $value=>$label):?>
       <option value="<?php echo $value;?>" <?php echo ($settings['source']??'custom')===$value?'selected':'';?>><?php echo $esc($label);?></option><?php endforeach;?></select></div>
      <div class="row"><div class="col-sm-6 form-group"><label>Interval in minutes</label><input class="form-control" type="number" min="1" max="10080" name="notifications[scheduled][interval]" value="<?php echo (int)$settings['interval'];?>"></div>
      <div class="col-sm-6 form-group"><label>Or daily time</label><input class="form-control" type="time" name="notifications[scheduled][time]" value="<?php echo $esc($settings['time']);?>"></div></div>
      <div class="form-group"><label>Title</label><input class="form-control" name="notifications[scheduled][title]" value="<?php echo $esc($settings['title']);?>"></div>
      <div class="form-group"><label>Message</label><textarea class="form-control" name="notifications[scheduled][message]" rows="3"><?php echo $esc($settings['message']);?></textarea></div>
      <?php foreach (['title_url'=>'Title URL','message_url'=>'Message URL','image_url'=>'Image URL'] as $key=>$label):?>
       <div class="form-group"><label><?php echo $label;?></label><input class="form-control" type="url" name="notifications[scheduled][<?php echo $key;?>]" value="<?php echo $esc($settings[$key]);?>"></div>
      <?php endforeach;?>
      <div class="form-group"><label>Duration (status display)</label><input class="form-control" type="number" min="2" max="60" name="notifications[scheduled][duration]" value="<?php echo (int)$settings['duration'];?>"></div>
     </div>
    <?php else: ?>
     <div class="ne-card"><h4>Design & layout</h4>
      <div class="form-group"><label>Base theme</label><select class="form-control ne-theme" name="notifications[<?php echo $kind;?>][theme]">
       <?php foreach (['ocean'=>'Ocean','charcoal'=>'Charcoal','plum'=>'Plum','forest'=>'Forest','custom'=>'Custom'] as $value=>$label):?>
       <option value="<?php echo $value;?>" <?php echo $settings['theme']===$value?'selected':'';?>><?php echo $label;?></option><?php endforeach;?></select></div>
      <label><input type="checkbox" name="notifications[<?php echo $kind;?>][show_number]" value="1" <?php echo $settings['show_number']?'checked':'';?>> Display caller number</label>
      <label style="margin-left:15px"><input type="checkbox" name="notifications[<?php echo $kind;?>][show_details]" value="1" <?php echo $settings['show_details']?'checked':'';?>> Display details</label>
      <h4 style="margin-top:18px">Section colors</h4><div class="ne-colors">
       <?php $defaults=['background'=>'#13293D','header'=>'#1E425B','header_text'=>'#5AC4F5','name_text'=>'#FAFCFF','secondary_text'=>'#A8CEE5','details_background'=>'#13293D','details_label'=>'#A8CEE5','details_text'=>'#FAFCFF','accent'=>'#5AC4F5'];
       foreach ($colorLabels as $key=>$label): ?>
       <label><?php echo $label;?><input type="color" name="notifications[<?php echo $kind;?>][colors][<?php echo $key;?>]" value="<?php echo $esc($settings['colors'][$key]??$defaults[$key]);?>"></label>
       <?php endforeach;?></div>
     </div>
     <div class="ne-card"><h4>Stripes & pride palettes</h4>
      <div class="form-group"><label>Placement</label><select class="form-control" name="notifications[<?php echo $kind;?>][stripe_position]">
       <?php foreach (['off'=>'None','header'=>'Header','top'=>'Top accent','bottom'=>'Bottom accent','background'=>'Background with readable content panel'] as $value=>$label):?>
       <option value="<?php echo $value;?>" <?php echo ($settings['stripe_position']??'off')===$value?'selected':'';?>><?php echo $label;?></option><?php endforeach;?></select></div>
      <div><strong>Color presets</strong></div>
      <?php foreach (['rainbow'=>'Rainbow','progress'=>'Progress','trans'=>'Trans','bi'=>'Bisexual','pan'=>'Pansexual','lesbian'=>'Lesbian','nonbinary'=>'Nonbinary','ace'=>'Asexual'] as $name=>$label): ?>
       <button type="button" class="btn btn-default btn-sm ne-preset" data-preset="<?php echo $name;?>" data-kind="<?php echo $kind;?>"><?php echo $label;?></button>
      <?php endforeach;?>
      <p class="ne-help">Presets fill the stripe colors below. Edit any stripe individually afterward.</p>
      <div class="ne-stripes" data-stripes="<?php echo $kind;?>">
       <?php for($i=0;$i<10;$i++):?>
       <label class="ne-stripe-row">#<?php echo $i+1;?><input type="color" data-stripe-color value="<?php echo $esc($settings['stripes'][$i]??'#FFFFFF');?>">
       <input type="checkbox" data-stripe-active <?php echo isset($settings['stripes'][$i])?'checked':'';?>><input type="hidden" name="notifications[<?php echo $kind;?>][stripes][<?php echo $i;?>]" value="<?php echo $esc($settings['stripes'][$i]??'');?>"></label>
       <?php endfor;?>
      </div>
     </div>
    <?php endif;?>
   </div>
   <div>
    <?php if ($kind!=='scheduled'): ?>
     <div class="ne-card"><h4>Actual rendered preview</h4>
      <?php
       $xml=\FreePBX\modules\Sccp_manager\phoneNotifications::graphic($this,$kind,$samples[$kind],[],$settings);
       $preview='';
       if (is_string($xml) && preg_match('~<URL>([^<]+)</URL>~',$xml,$match)) $preview=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');
      ?>
      <?php if ($preview):?><img class="ne-screen" src="<?php echo $esc($preview);?>" alt="Actual Cisco phone PNG preview">
      <?php else:?><p>Unable to generate PNG preview. Check PHP GD and your public content folder.</p><?php endif;?>
      <p class="ne-help">This PNG was generated using exactly the same PHP renderer as the real notifications. Save to regenerate the preview with changed settings.</p>
     </div>
    <?php else: ?><div class="ne-card"><h4>Scheduled notifications</h4><p>Scheduled images use the incoming feed image; they are not generated with the call/voicemail graphic renderer.</p><p>Use the source and schedule controls to configure delivery.</p></div><?php endif;?>
   </div>
  </div>
 </section>
 <?php endforeach;?>
 <button type="submit" class="btn btn-primary btn-lg">Save notification settings</button>
 </form>
 <form method="post" action="config.php?display=sccp_content#notifications" style="margin-top:14px">
  <input type="hidden" name="content_action" value="notifications_test">
  <button type="submit" class="btn btn-default">Send test scheduled notification</button>
 </form>
</div>
<script>
(function(){
 const root=document.querySelector('.sccp-notify-editor');if(!root)return;
 root.querySelectorAll('[data-ne-tab]').forEach(button=>button.addEventListener('click',()=>{
  root.querySelectorAll('[data-ne-tab]').forEach(b=>b.classList.toggle('active',b===button));
  root.querySelectorAll('[data-ne-panel]').forEach(p=>p.classList.toggle('active',p.dataset.nePanel===button.dataset.neTab));
 }));
 const palettes=<?php echo json_encode($palettes,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);?>;
 const themes={
  ocean:['#13293D','#1E425B','#FAFCFF','#A8CEE5','#5AC4F5'],
  charcoal:['#181B20','#30343C','#FFFFFF','#BFC6D0','#FFD084'],
  plum:['#301C3F','#513162','#FFFFFF','#DFC6EC','#FF9BC8'],
  forest:['#123C31','#245C48','#F5FFF8','#C1E9D2','#91E7B1']
 };
 root.querySelectorAll('.ne-theme').forEach(select=>select.addEventListener('change',()=>{
  const t=themes[select.value];if(!t)return;
  const panel=select.closest('.ne-panel');
  const values={background:t[0],header:t[1],header_text:t[4],name_text:t[2],
   secondary_text:t[3],details_background:t[0],details_label:t[3],
   details_text:t[2],accent:t[4]};
  Object.keys(values).forEach(key=>{const input=panel.querySelector('[name$="[colors]['+key+']"]');if(input)input.value=values[key];});
 }));

 root.querySelectorAll('.ne-stripe-row').forEach(row=>{
  const color=row.querySelector('[data-stripe-color]'),active=row.querySelector('[data-stripe-active]'),hidden=row.querySelector('input[type=hidden]');
  const update=()=>{hidden.value=active.checked?color.value:'';};color.addEventListener('input',()=>{active.checked=true;update();});active.addEventListener('change',update);
 });
 root.querySelectorAll('[data-preset]').forEach(button=>button.addEventListener('click',()=>{
  const values=palettes[button.dataset.preset]||[];
  const rows=root.querySelectorAll('[data-stripes="'+button.dataset.kind+'"] .ne-stripe-row');
  rows.forEach((row,i)=>{const color=row.querySelector('[data-stripe-color]'),active=row.querySelector('[data-stripe-active]'),hidden=row.querySelector('input[type=hidden]');color.value=values[i]||'#FFFFFF';active.checked=!!values[i];hidden.value=values[i]||'';});
  const panel=root.querySelector('[data-ne-panel="'+button.dataset.kind+'"]');
  panel.querySelector('[name$="[stripe_position]"]').value='header';
 }));
})();
</script>
