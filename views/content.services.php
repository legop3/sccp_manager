<?php
require_once __DIR__.'/../sccpManClasses/serviceAppConfig.php';
$config=new \FreePBX\modules\Sccp_manager\serviceAppConfig($this);
$definitions=$config->definitions();
$manifests=(new \FreePBX\modules\Sccp_manager\serviceRegistry($this))->manifests();
$flags=$this->phonemenus->serviceFlags();
$escape=static function($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');};
?>
<div class="container-fluid">
<h3>phone services</h3>
<p>enable services and configure them here. the services button shows enabled apps; messages and directories keep their dedicated phone menus.</p>
<form method="post" action="config.php?display=sccp_content#services">
<input type="hidden" name="content_action" value="services_save">
<div class="panel-group" id="service-app-settings">
<?php foreach($manifests as $id=>$manifest):
    $fields=$definitions[$id];
    $values=$config->get($id);
?>
<details class="panel panel-default" style="margin-bottom:10px" <?php echo !empty($fields)?'':'open'; ?>>
<summary class="panel-heading" style="cursor:pointer;list-style-position:inside">
<strong><?php echo $escape($manifest['name']); ?></strong>
<span class="text-muted"> — <?php echo $escape($manifest['description']??'phone service'); ?></span>
<span class="pull-right text-muted"><?php echo !empty($flags[$id])?'enabled':'disabled'; ?></span>
</summary>
<div class="panel-body">
<div class="checkbox"><label>
<input type="checkbox" name="services[<?php echo $escape($id); ?>]" value="1" <?php echo !empty($flags[$id])?'checked':''; ?> />
<strong>enabled in phone services menu</strong>
</label></div>
<?php if($fields): ?>
<div class="row">
<?php foreach($fields as $key=>$field):
    $type=$field['type'];$name='app_settings['.$id.']['.$key.']';
    $value=$values[$key];
?>
<div class="col-sm-6 col-md-4">
<div class="form-group">
<?php if($type==='toggle'): ?>
<label class="checkbox"><input type="checkbox" name="<?php echo $escape($name); ?>" value="1" <?php echo $value?'checked':''; ?> /> <?php echo $escape($field['label']); ?></label>
<?php else: ?>
<label for="<?php echo $escape('svc_'.$id.'_'.$key); ?>"><?php echo $escape($field['label']); ?></label>
<?php if($type==='select'): ?>
<select class="form-control" id="<?php echo $escape('svc_'.$id.'_'.$key); ?>" name="<?php echo $escape($name); ?>">
<?php foreach($field['options'] as $choice=>$description): ?>
<option value="<?php echo $escape($choice); ?>" <?php echo (string)$value===(string)$choice?'selected':''; ?>><?php echo $escape($description); ?></option>
<?php endforeach; ?></select>
<?php else: ?>
<input class="form-control" type="<?php echo in_array($type,['number','integer'],true)?'number':'text'; ?>"
id="<?php echo $escape('svc_'.$id.'_'.$key); ?>" name="<?php echo $escape($name); ?>"
value="<?php echo $escape($value); ?>"
<?php if(isset($field['min'])): ?>min="<?php echo $escape($field['min']); ?>"<?php endif; ?>
<?php if(isset($field['max'])): ?>max="<?php echo $escape($field['max']); ?>"<?php endif; ?>
<?php if(isset($field['maxLength'])): ?>maxlength="<?php echo (int)$field['maxLength']; ?>"<?php endif; ?>
<?php if(isset($field['minLength'])): ?>minlength="<?php echo (int)$field['minLength']; ?>"<?php endif; ?>
<?php if(in_array($type,['number','integer'],true)): ?>step="<?php echo $escape($field['step']??($type==='integer'?1:'any')); ?>"<?php endif; ?>
required />
<?php endif; ?>
<?php endif; ?>
<?php if(!empty($field['description'])): ?><p class="help-block"><?php echo $escape($field['description']); ?></p><?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<p class="text-muted">this service has no additional settings.</p>
<?php endif; ?>
</div>
</details>
<?php endforeach; ?>
</div>
<button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> save services</button>
</form>
<p class="help-block" style="margin-top:12px">application settings apply on the next action. services menu changes appear on the next press. signed phone settings are refreshed on save.</p>
</div>

<?php
require_once __DIR__.'/../sccpManClasses/serviceMediaLibrary.php';
$mediaItems=\FreePBX\modules\Sccp_manager\serviceMediaLibrary::rows();
?>
<hr/>
<h3>media library</h3>
<p>upload audio or video for the phones. ffmpeg reads the original file; playback is streamed live.</p>
<form id="sccp-media-upload" enctype="multipart/form-data">
<div class="row"><div class="col-sm-8">
<input class="form-control" type="file" name="media" required />
<p class="help-block">upload size is limited by available server storage, not a module file-size cap.</p>
</div><div class="col-sm-4">
<button class="btn btn-primary" type="submit"><i class="fa fa-upload"></i> upload media</button>
</div></div>
<div id="sccp-media-upload-progress" role="status" aria-live="polite" style="margin-top:8px"></div>
</form>
<script>
(function(){
    var form=document.getElementById('sccp-media-upload');
    if(!form)return;
    form.addEventListener('submit',async function(ev){
        ev.preventDefault();
        var file=form.querySelector('input[type="file"]').files[0];
        if(!file)return;
        var status=document.getElementById('sccp-media-upload-progress');
        var button=form.querySelector('button[type="submit"]');
        button.disabled=true;
        var uploadId=Array.from(crypto.getRandomValues(new Uint8Array(12)),
            function(x){return x.toString(16).padStart(2,'0');}).join('');
        var size=262144;
        var chunks=Math.ceil(file.size/size);
        if(!chunks){status.textContent='empty media file';button.disabled=false;return;}
        try {
            async function send(index,finished){
                var data=new FormData();
                data.append('upload_id',uploadId);
                data.append('index',String(index));
                data.append('filename',file.name);
                data.append('finish',finished?'1':'0');
                if(!finished)data.append('chunk',file.slice(index*size,Math.min((index+1)*size,file.size)),'part');
                var response=await fetch('ajax.php?module=sccp_manager&command=media_chunk',{
                    method:'POST',body:data,credentials:'same-origin'
                });
                if(!response.ok)throw new Error('upload request failed (http '+response.status+')');
                var result=await response.json();
                if(!result.status)throw new Error(result.message||'upload failed');
                return result.result;
            }
            for(var i=0;i<chunks;i++){
                await send(i,false);
                status.textContent='uploading '+Math.round((i+1)*100/chunks)+'%';
            }
            status.textContent='checking media format...';
            await send(chunks,true);
            status.textContent='upload complete';
            window.location.reload();
        } catch(e) {
            status.textContent='upload failed: '+e.message;
        } finally {
            button.disabled=false;
        }
    });
})();
</script>
<?php if($mediaItems): ?>
<table class="table table-striped">
<thead><tr><th>file</th><th>type</th><th>length</th><th></th></tr></thead><tbody>
<?php foreach($mediaItems as $item): ?>
<tr>
<td><?php echo $escape($item['name']); ?></td>
<td><?php echo !empty($item['video'])?'video':'audio'; ?></td>
<td><?php echo gmdate('H:i:s',(int)$item['duration']); ?></td>
<td>
<form method="post" action="config.php?display=sccp_content#services" onsubmit="return confirm('delete this media file?')">
<input type="hidden" name="content_action" value="media_delete"/>
<input type="hidden" name="id" value="<?php echo $escape($item['id']); ?>"/>
<button type="submit" class="btn btn-default btn-sm">delete</button>
</form>
</td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
