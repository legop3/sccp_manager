<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
$keymultiselect = array('AllRight' =>'>>', 'Right' => '>', 'AllLeft' => '<<', 'Left' => '<');

//   ------------------------------------- Key Set Value ---------------------------------------------------------
$keysetarray =  $this->extconfigs->getExtConfig('keyset');

/*$keysetarray1 = array('onhook'    => array('redial','newcall','cfwdall','dnd','pickup','gpickup','private'),
                    'connected'  => array('hold','endcall','park','vidmode','select','cfwdall','cfwdbusy','idivert'),
                    'onhold'     => array('resume','newcall','endcall','transfer','conflist','select','dirtrfr','idivert','meetme'),
                    'ringin'     => array('answer','endcall','transvm','idivert'),
                    'offhook'    => array('redial','endcall','private','cfwdall','cfwdbusy','pickup','gpickup','meetme','barge'),
                    'conntrans'  => array('hold','endcall','transfer','conf','park','select','dirtrfr','vidmode','meetme','cfwdall','cfwdbusy'),
                    'digitsfoll' => array('back','endcall','dial'),
                    'connconf'   => array('conflist','newcall','endcall','hold','vidmode'),
                    'ringout'    => array('empty','endcall','transfer','cfwdall','idivert'),
                    'offhookfeat'=> array('redial','endcall'),
                    'onhint'     => array('redial','newcall','pickup','gpickup','barge'),
                    'onstealable'=> array('redial','newcall','cfwdall','pickup','gpickup','dnd','intrcpt'),
                    'holdconf'   => array('resume','newcall','endcall','join'),
                    'uriaction'  => array('default'));
*/
//   ------------------------------------- Key Set Display information  ---------------------------------------------------------
$keynamearray = array('onhook'    => array('sname' => 'ONHOOK', 'name' =>'Display Onhook', 'help' =>'help.'),
                    'connected'  => array('sname' => 'CONNECTED', 'name' =>'Display Connected', 'help' =>'help.'),
                    'onhold'     => array('sname' => 'ONHOLD', 'name' =>'Display onhold', 'help' =>'help.'),
                    'ringin'     => array('sname' => 'RINGIN', 'name' =>'Display ringin', 'help' =>'help.'),
                    'offhook'    => array('sname' => 'OFFHOOK', 'name' =>'Display offhook', 'help' =>'help.'),
                    'conntrans'  => array('sname' => 'CONNTRANS', 'name' =>'Display conntrans', 'help' =>'help.'),
                    'digitsfoll' => array('sname' => 'DIGITSFOLL', 'name' =>'Display digitsfoll', 'help' =>'help.'),
                    'connconf'   => array('sname' => 'CONNCONF', 'name' =>'Display connconf', 'help' =>'help.'),
                    'ringout'    => array('sname' => 'RINGOUT', 'name' =>'Display ringout', 'help' =>'help.'),
                    'offhookfeat'=> array('sname' => 'OFFHOOKFEAT', 'name' =>'Display offhookfeat', 'help' =>'help.'),
                    'onhint'     => array('sname' => 'ONHINT', 'name' =>'Display onhint', 'help' =>'help.'),
                    'onstealable'=> array('sname' => 'onstealable', 'name' =>'Display onstealable', 'help' =>'help.'),
                    'holdconf'   => array('sname' => 'HOLDCONF', 'name' =>'Display holdconf', 'help' =>'help.'),
                    'uriaction'  => array('sname' => '', 'name' =>'Display uriaction', 'help' =>'help.')
    );

?>
<form autocomplete="off" name="frm_keyset" id="frm_keyset" class="fpbx-submit" action="" method="post">
    <input type="hidden" name="category" value="keysetform">
    <input type="hidden" name="Submit" value="Submit">
    <div class="fpbx-container container-fluid">
        <div class="row">
            <div class="col-sm-12">
                <div class="display no-border">
                    <div id="toolbar-all">
                        <button type="button" class="btn btn-primary btn-lg" data-toggle="modal" onclick="load_oncliсk(this,'*new*')" data-target=".edit_new_keyset"><i class="fa fa-bolt"></i> <?php echo _("Add Keyset"); ?></button>
                    </div>
                    <table data-escape="true" data-cookie="true" data-cookie-id-table="sccp_keyset-all" data-url="ajax.php?module=sccp_manager&command=getSoftKey&type=active" data-cache="false" data-show-refresh="true" data-toolbar="#toolbar-all" data-maintain-selected="true" data-show-columns="true" data-show-toggle="true" data-toggle="table" data-pagination="true" data-search="true" class="table table-striped ext-list" id="softkey-all" data-unique-id="softkeys">
                        <thead>
                            <tr>
                                <th data-sortable="true" data-field="softkeys"><?php echo _('KeySetName')?></th>
                                <?php
                                $i = 0;
                                foreach ($keynamearray as $key => $value) {
                                    // one header per key set field; a cap of 12 here left the last two
                                    // columns the grid receives without a heading
                                    echo '<th data-sortable="false" data-field="'.$key.'">'._($value['sname']).'</th>';
                                }
                                ?>
                                <th data-field="actions" data-formatter="DispayActionsKeyFormatter"><?php echo _('Actions')?></th>
                            </tr>
                        </thead>
                        <tbody>

                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Begin Form Input New / Edit  -->
<div class="modal fade edit_new_keyset" tabindex="-1" role="dialog" aria-labelledby="myLargeModalLabel2">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="gridSystemModalLabel">Add New KeySet</h4>
            </div>
            <div class="modal-body">
                <style type="text/css">
                    input:invalid {border: red solid 3px;}
                </style>
                <div class="element-container">
                    <div class="row">
                        <div class="form-group">
                            <div class="col-md-3">
                                <label class="control-label" for="new_keysetname"><?php echo _('Name Keyset'); ?></label>
                                <i class="fa fa-question-circle fpbx-help-icon" data-for="new_devmodel"></i>
                            </div>
                            <div class="col-md-9">
                                <input type="text" maxlength="15" pattern="[a-zA-Z0-9]{4,15}" class="form-control" id="new_keySetname" name="new_keySetname" value="SoftKeyset">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <span id="new_devmodel-help" class="help-block fpbx-help-block">Max length 15 letters or numbers without spaces</span>
                        </div>
                    </div>
                </div>
                <ul class="nav nav-tabs" role="tablist">

<?php
                $i = 0;
foreach ($keysetarray as $key => $value) {
    if ($i == 0) {
        echo '<li role="presentation" data-name="'.$key.'" class="active">';
    } else {
        echo '<li role="presentation" data-name="'.$key.'" class="change-tab">';
    }
    echo '<a href="#'.$key.'" aria-controls="'.$key.'" role="tab" data-toggle="tab">'._($key);
    echo '</a></li>';
    $i ++;
}
?>
        </ul>
                <div class="tab-content display">
<?php
                $i = 0;
foreach ($keysetarray as $key => $value) {
    if ($i == 0) {
        echo '<div role="tabpanel" id="'.$key.'" class="tab-pane active">';
    } else {
        echo '<div role="tabpanel" id="'.$key.'" class="tab-pane">';
    }
    echo '<div class="element-container"><div class="row"><div class="form-group"><div class="col-md-3"><label class="control-label" for="'.$key.'">'._($keynamearray[$key]['name']).'</label>';
    echo '<i class="fa fa-question-circle fpbx-help-icon" data-for="'.$key.'"></i></div>';

        echo '<div class="col-md-4"><select multiple class="form-control sccpmultiselect" name="av_'.$key.'" id="source_'.$key.'">';
    $row_dada= explode(',', $value);
    foreach ($row_dada as $data) {
          echo '<option value="'.$data.'">'.$data.'</option>';
    }
    echo '</select></div><div class="col-md-1">';
    foreach ($keymultiselect as $btkey => $btval) {
        echo '<input type="button" class="btnMultiselect" data-id="'.$key.'" data-key="'.$btkey.'" value="'.$btval.'">';
    }
    echo '</div><div class="col-md-4"><select multiple class="form-control" name="sel_'.$key.'" id="destination_'.$key.'">';
    echo '</select></div></div></div><div class="row"><div class="col-md-12">';
    echo '<span id="'.$key.'-help" class="help-block fpbx-help-block">'._($keynamearray[$key]['help']).'</span>';
    echo '</div></div></div></div>';
    $i ++;
}
?>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary sccp_update" data-id="keyset_add" data-mode="new" id="keyset_add" data-dismiss="modal">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
    function DispayActionsKeyFormatter(value, row, index) {
        var exp_model = '';
        if (row['softkeys'] !== 'default') {
            exp_model += '<a href="#edit_softkeys"   onclick="load_oncliсk(this, &quot;'+sccpEscapeHtml(sccpEscapeJs(row['softkeys']))+'&quot;)" data-toggle="modal" data-target=".edit_new_keyset"><i class="fa fa-pencil"></i></a>&nbsp;';
            exp_model += '</a> &nbsp;<a class="btn-item-delete" data-for="softkeys" data-id="' + sccpEscapeHtml(row['softkeys']) + '"><i class="fa fa-trash"></i></a>';
        }
        return  exp_model;
    }
</script>

<!-- SCCP modern keyset editor -->
<style>
.sccp-key-builder{border:1px solid #ccd3dc;border-radius:8px;margin:12px 0;padding:14px;background:var(--surface,#fafbfc)}
.sccp-key-builder .skb-preview{display:flex;gap:4px;padding:12px;background:#273d53;border-radius:6px;margin-bottom:12px}
.sccp-key-builder .skb-preview span{color:#21364d;background:#e7edf4;min-width:0;flex:1;text-align:center;border-radius:3px;padding:9px 2px;font-size:12px;overflow:hidden;text-overflow:ellipsis}
.sccp-key-builder .skb-items{list-style:none;padding:0;margin:10px 0;display:flex;flex-direction:column;gap:5px}
.sccp-key-builder .skb-items li{display:flex;gap:5px;align-items:center;background:transparent;border:1px solid #cbd3dc;padding:6px;border-radius:4px}
.sccp-key-builder .skb-items li.dragging{opacity:.4}
.sccp-key-builder .skb-items li .skb-label{flex:1;min-width:0;overflow-wrap:anywhere}
.sccp-key-builder .skb-items button{padding:2px 7px}
.sccp-key-builder .skb-add{display:flex;gap:6px;flex-wrap:wrap}
.sccp-key-builder .skb-add select{flex:1;min-width:150px}
.sccp-key-builder .skb-note{color:#576779;font-size:12px;margin:7px 0}
.sccp-key-builder .skb-handle{cursor:grab;color:#687b8b;padding:0 5px}
.edit_new_keyset .skb-original{display:none!important}
.edit_new_keyset .tab-pane .col-md-3:first-child{padding-bottom:6px}
@media(max-width:600px){.sccp-key-builder{padding:8px}.sccp-key-builder .skb-preview span{font-size:10px;padding:8px 1px}}
</style>
<script>
(function(){
"use strict";
var names={
 redial:"Redial",newcall:"New Call",cfwdall:"Forward All",cfwdbusy:"Forward Busy",
 cfwdnoanswer:"Forward No Answer",dnd:"Do Not Disturb",pickup:"Pickup",gpickup:"Group Pickup",
 private:"Privacy",hold:"Hold",endcall:"End Call",transfer:"Transfer",park:"Park",
 select:"Select",idivert:"Divert",resume:"Resume",answer:"Answer",transvm:"Transfer to Voicemail",
 conf:"Conference",conflist:"Conference List",join:"Join",dirtrfr:"Direct Transfer",
 back:"Backspace",dial:"Dial",empty:"Empty",barge:"Barge",cbarge:"Conference Barge",
 monitor:"Monitor",vidmode:"Video Mode",intrcpt:"Intercept",meetme:"MeetMe"
};
var notes={dnd:"DND requires the DND feature to be enabled on the device.",
 conf:"Conference behavior depends on driver and configured conferencing.",
 meetme:"Only available if present in your installed driver's supported key list.",
 empty:"Blank softkey position."};
function node(tag,cls,label){var x=document.createElement(tag);if(cls)x.className=cls;if(label!==undefined)x.textContent=label;return x;}
function build(tab){
 var src=tab.querySelector('select[id^="source_"]'),dst=tab.querySelector('select[id^="destination_"]');
 if(!src||!dst)return;
 var existing=tab.querySelector('.sccp-key-builder');if(existing)return;
 var available=Array.from(src.options).concat(Array.from(dst.options)).map(o=>o.value).filter(Boolean);
 var master=Array.from(new Set(available));
 // Preserve driver-defined choices. Never offer arbitrary guessed SCCP commands.
 var group=node('section','sccp-key-builder');
 var title=node('strong','', 'Button layout');group.appendChild(title);
 var help=node('p','skb-note','Reorder with the arrows or drag a button. The first four positions are shown below; the phone may hide unavailable features.');group.appendChild(help);
 var preview=node('div','skb-preview');group.appendChild(preview);
 var list=node('ol','skb-items');group.appendChild(list);
 var addRow=node('div','skb-add'),pick=node('select','form-control'),add=node('button','btn btn-default','Add button');
 add.type='button';addRow.append(pick,add);group.appendChild(addRow);
 var detail=node('p','skb-note');group.appendChild(detail);
 src.closest('.row').after(group);
 src.closest('.row').classList.add('skb-original');
 function selected(){return Array.from(dst.options).map(o=>o.value);}
 function sync(keys){
  var lookup={};Array.from(src.options).concat(Array.from(dst.options)).forEach(o=>{lookup[o.value]=o.cloneNode(true);});
  src.replaceChildren();dst.replaceChildren();
  keys.forEach(k=>{if(lookup[k])dst.appendChild(lookup[k]);});
  master.forEach(k=>{if(!keys.includes(k)&&lookup[k])src.appendChild(lookup[k]);});
  draw();
 }
 function draw(){
  var keys=selected();preview.replaceChildren();list.replaceChildren();pick.replaceChildren();
  var visible=keys.slice(0,4);
  visible.forEach(k=>preview.appendChild(node('span','',names[k]||k)));
  if(keys.length>4)preview.appendChild(node('span','','More'));
  if(!keys.length)preview.appendChild(node('span','','(No buttons)'));
  keys.forEach((k,i)=>{
   var li=node('li');li.draggable=true;li.dataset.index=String(i);
   li.appendChild(node('span','skb-handle','☰'));
   var label=node('span','skb-label',(names[k]||k)+' ('+k+')');li.appendChild(label);
   function control(txt,tip,fn,disabled){var b=node('button','btn btn-default btn-xs',txt);b.type='button';b.title=tip;b.disabled=!!disabled;b.addEventListener('click',fn);li.appendChild(b);}
   control('↑','Move up',()=>{var n=keys.slice();[n[i-1],n[i]]=[n[i],n[i-1]];sync(n);},i===0);
   control('↓','Move down',()=>{var n=keys.slice();[n[i+1],n[i]]=[n[i],n[i+1]];sync(n);},i===keys.length-1);
   control('×','Remove',()=>sync(keys.filter((_,j)=>j!==i)));
   li.addEventListener('dragstart',e=>{e.dataTransfer.setData('text/plain',String(i));li.classList.add('dragging');});
   li.addEventListener('dragend',()=>li.classList.remove('dragging'));
   li.addEventListener('dragover',e=>e.preventDefault());
   li.addEventListener('drop',e=>{e.preventDefault();var from=Number(e.dataTransfer.getData('text/plain'));if(!Number.isInteger(from)||from<0||from>=keys.length)return;var n=keys.slice();n.splice(i,0,n.splice(from,1)[0]);sync(n);});
   list.appendChild(li);
  });
  var placeholder=node('option','', 'Choose an available button');placeholder.value='';pick.appendChild(placeholder);
  master.forEach(k=>{if(!keys.includes(k)){var o=node('option','', (names[k]||k)+' ('+k+')');o.value=k;pick.appendChild(o);}});
  var descriptions=keys.filter(k=>notes[k]).map(k=>k+': '+notes[k]);
  detail.textContent=descriptions.join(' ');
 }
 add.addEventListener('click',()=>{if(pick.value&&!selected().includes(pick.value))sync(selected().concat(pick.value));});
 // Existing load_oncliсk() populates the original select elements. Re-read them
 // after the Bootstrap modal opens, so editing existing sets is faithfully shown.
 tab.sccpRefreshKeyBuilder=draw;
 draw();
}
function init(){
 document.querySelectorAll('.edit_new_keyset .tab-pane').forEach(build);
 var modal=document.querySelector('.edit_new_keyset');
 if(modal&&window.jQuery){
   jQuery(modal).on('shown.bs.modal',()=>modal.querySelectorAll('.tab-pane').forEach(t=>t.sccpRefreshKeyBuilder&&t.sccpRefreshKeyBuilder()));
 }
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
</script>
