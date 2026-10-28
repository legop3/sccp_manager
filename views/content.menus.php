<?php
// Shared menu editor. Phone Content owns the global configuration; it doesn't
// add any per-device profiles or disturb the existing VPN/TFTP signing settings.
$pcMenus = $this->phonemenus->config();
$pcTypes = $this->phonemenus::types();
$pcBase = $this->phonecontent->baseUrl();
$pcEsc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
<div class="fpbx-container container-fluid">
    <div class="display no-border">
        <h3><?php echo _('Phone Menus'); ?></h3>
        <p><?php echo _('Configure the Directories, Services and Messages buttons for all SCCP phones. These menus share the existing Address Book.'); ?></p>
        <form id="pc-menu-form" method="post" action="config.php?display=sccp_content#menus">
            <input type="hidden" name="content_action" value="save_menus">
            <input type="hidden" name="menu_config" id="pc-menu-config" value="">
            <div class="well">
                <label for="pc-menu-enable">
                    <input type="checkbox" id="pc-menu-enable" <?php echo !empty($pcMenus['enabled']) ? 'checked' : ''; ?>>
                    <?php echo _('Use custom phone-button menus (internal service provisioning)'); ?>
                </label>
                <p class="help-block" style="margin-bottom:0">
                    <?php echo _('Off (default): preserve the phone firmware’s built-in call history and normal voicemail button. The managed Directory and Services XML menus still update.'); ?>
                    <?php echo _('On: generate phoneServices provisioning=0 with the entries below. Saving signs updated SEP files, but phones must be reset to pick up the new button layout. Test with one 7945G before deploying widely.'); ?>
                </p>
            </div>

            <div class="row" style="margin-bottom:14px">
                <div class="col-sm-7">
                    <label for="pc-menu-section"><?php echo _('Editing menu'); ?></label>
                    <select class="form-control" id="pc-menu-section"></select>
                </div>
                <div class="col-sm-5" style="padding-top:24px">
                    <button type="button" class="btn btn-default" id="pc-add-submenu"><i class="fa fa-plus"></i> <?php echo _('Create submenu'); ?></button>
                    <button type="button" class="btn btn-default" id="pc-delete-submenu" style="display:none"><i class="fa fa-trash"></i> <?php echo _('Delete submenu'); ?></button>
                </div>
            </div>
            <p class="help-block" id="pc-menu-description"></p>
            <div id="pc-menu-entries" aria-live="polite"></div>
            <button type="button" class="btn btn-default" id="pc-add-item"><i class="fa fa-plus"></i> <?php echo _('Add entry'); ?></button>
            <hr>
            <button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> <?php echo _('Save Menus'); ?></button>
        </form>
        <?php if ($pcBase): ?>
        <p class="help-block" style="margin-top:14px">
            <?php echo _('Test served XML:'); ?>
            <a href="<?php echo $pcEsc($pcBase . '/phone.php?doc=directory'); ?>" target="_blank" rel="noopener noreferrer"><?php echo _('Directories'); ?></a>
            &middot;
            <a href="<?php echo $pcEsc($pcBase . '/phone.php?doc=services'); ?>" target="_blank" rel="noopener noreferrer"><?php echo _('Services'); ?></a>
        </p>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    'use strict';
    // JSON_HEX_* prevents literal HTML/script delimiters from saved labels.
    var menus = <?php echo json_encode($pcMenus, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var types = <?php echo json_encode($pcTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var section = 'directories';
    var rootNames = {directories:'Directories', services:'Services', messages:'Messages'};
    var descriptions = {
        directories:'These entries appear under the Directories button when custom button menus are enabled.',
        services:'These entries appear under the Services button. In legacy mode they are still published as the Services XML page.',
        messages:'For now the Messages button supports exactly one service: native Voicemail or a Cisco XML URL.'
    };

    function submenu() {
        if (section.indexOf('sub:') !== 0) return null;
        var id = section.substring(4);
        return (menus.submenus || []).filter(function (s) { return s.id === id; })[0] || null;
    }
    function items() {
        var sub = submenu();
        return sub ? sub.items : menus[section];
    }
    function makeButton(icon, title, handler, disabled) {
        return $('<button type="button" class="btn btn-default btn-sm"></button>')
            .append($('<i>').addClass('fa fa-' + icon))
            .attr('aria-label',title).attr('title',title)
            .prop('disabled',!!disabled).on('click',handler);
    }
    function optionsFor(type) {
        if (section === 'messages') return ['voicemail','url'];
        if (section.indexOf('sub:') === 0) return Object.keys(types).filter(function (k) {
            return k !== 'voicemail' && k !== 'submenu';
        });
        return Object.keys(types).filter(function (k) { return k !== 'voicemail'; });
    }
    function rebuildSelector() {
        var selector = $('#pc-menu-section').empty();
        Object.keys(rootNames).forEach(function (key) {
            $('<option>').val(key).text(rootNames[key]).appendTo(selector);
        });
        (menus.submenus || []).forEach(function (sub) {
            $('<option>').val('sub:' + sub.id).text('Submenu: ' + sub.name).appendTo(selector);
        });
        selector.val(section);
    }
    function render() {
        rebuildSelector();
        $('#pc-delete-submenu').toggle(!!submenu());
        $('#pc-menu-description').text(submenu() ?
            'Submenus open as a new Cisco XML menu. One level of nesting is supported.' :
            descriptions[section]);
        var container = $('#pc-menu-entries').empty();
        var rows = items();
        rows.forEach(function (item, i) {
            var panel = $('<div class="panel panel-default" style="margin-bottom:9px"></div>').appendTo(container);
            var body = $('<div class="panel-body"></div>').appendTo(panel);
            var line = $('<div class="row"></div>').appendTo(body);
            var nameCol = $('<div class="col-sm-4"></div>').appendTo(line);
            $('<label>').text('Menu label').appendTo(nameCol);
            $('<input class="form-control" maxlength="48" required>').val(item.name)
                .on('input',function(){ item.name = this.value; }).appendTo(nameCol);
            var typeCol = $('<div class="col-sm-3"></div>').appendTo(line);
            $('<label>').text('Action').appendTo(typeCol);
            var select = $('<select class="form-control"></select>').appendTo(typeCol);
            optionsFor().forEach(function (key) {
                $('<option>').val(key).text(types[key]).appendTo(select);
            });
            select.val(item.type).on('change',function () {
                item.type = this.value;
                item.value = '';
                if (item.type === 'submenu' && menus.submenus.length) item.value = menus.submenus[0].id;
                render();
            });
            var valueCol = $('<div class="col-sm-4"></div>').appendTo(line);
            if (item.type === 'url' || item.type === 'dial' || item.type === 'text' || item.type === 'submenu') {
                $('<label>').text(item.type === 'url' ? 'Cisco XML HTTP(S) URL' :
                    item.type === 'dial' ? 'Number to dial' :
                    item.type === 'text' ? 'Text to display' : 'Submenu').appendTo(valueCol);
                if (item.type === 'submenu') {
                    var target = $('<select class="form-control"></select>').appendTo(valueCol);
                    menus.submenus.forEach(function (sub) {
                        $('<option>').val(sub.id).text(sub.name).appendTo(target);
                    });
                    target.val(item.value).on('change', function () { item.value = this.value; });
                    if (!menus.submenus.length) {
                        $('<p class="text-danger">Create a submenu first.</p>').appendTo(valueCol);
                    }
                } else if (item.type === 'text') {
                    $('<textarea class="form-control" rows="2" maxlength="1200"></textarea>')
                        .val(item.value).on('input',function(){ item.value = this.value; }).appendTo(valueCol);
                } else {
                    $('<input class="form-control">').attr('placeholder',
                        item.type === 'url' ? 'http://pbx/path/to/menu.xml' : '123')
                        .val(item.value).on('input',function(){ item.value = this.value; }).appendTo(valueCol);
                }
            } else {
                $('<label>').text('Action details').appendTo(valueCol);
                $('<p class="help-block" style="margin:7px 0 0"></p>').text(
                    item.type === 'phonebook' ? 'Uses the shared Address Book.' : 'Built into the phone firmware.'
                ).appendTo(valueCol);
            }
            var btnCol = $('<div class="col-sm-1" style="white-space:nowrap;padding-top:22px"></div>').appendTo(line);
            makeButton('arrow-up','Move up',function(){
                if(i>0){var t=rows[i-1];rows[i-1]=rows[i];rows[i]=t;render();}
            },i===0).appendTo(btnCol);
            makeButton('arrow-down','Move down',function(){
                if(i<rows.length-1){var t=rows[i+1];rows[i+1]=rows[i];rows[i]=t;render();}
            },i===rows.length-1).appendTo(btnCol);
            makeButton('trash','Remove',function(){rows.splice(i,1);render();}).appendTo(btnCol);
        });
        $('#pc-add-item').prop('disabled',rows.length>=25 || (section==='messages' && rows.length>=1));
        if (!rows.length) $('<p class="text-muted"></p>').text('No entries. Add one below.').appendTo(container);
    }
    $('#pc-menu-section').on('change',function(){section=this.value;render();});
    $('#pc-add-item').on('click',function () {
        var rows = items();
        if (rows.length>=25 || (section==='messages' && rows.length>=1)) return;
        rows.push(section==='messages' ?
            {name:'Voicemail',type:'voicemail',value:''} :
            {name:'New Entry',type:'phonebook',value:''});
        render();
    });
    $('#pc-add-submenu').on('click',function () {
        if (menus.submenus.length>=12) { alert('At most 12 submenus are supported.');return; }
        var name = prompt('Submenu name');
        if (!name || !name.trim()) return;
        var id = 's' + Math.random().toString(36).slice(2,12);
        menus.submenus.push({id:id,name:name.trim(),items:[]});
        section='sub:'+id;render();
    });
    $('#pc-delete-submenu').on('click',function () {
        var sub=submenu();
        if (!sub || !confirm('Delete submenu "'+sub.name+'"?')) return;
        menus.submenus=menus.submenus.filter(function(s){return s.id!==sub.id;});
        ['directories','services','messages'].forEach(function(k){
            menus[k]=menus[k].filter(function(item){return item.type!=='submenu'||item.value!==sub.id;});
        });
        section='directories';render();
    });
    $('#pc-menu-form').on('submit',function () {
        menus.enabled=$('#pc-menu-enable').prop('checked');
        $('#pc-menu-config').val(JSON.stringify(menus));
    });
    render();
})();
</script>
