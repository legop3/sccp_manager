<?php
$pc = $this->phonecontent;
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$contacts = $pc->contacts();
$extensions = $pc->extensions();
$extensionChoices = $pc->extensionChoices();
$base = $pc->baseUrl();
?>
<div class="fpbx-container container-fluid">
    <div class="display no-border">
        <h3><?php echo _('Address Book'); ?></h3>
        <div class="row">
            <div class="col-md-9">
                <form method="post" action="config.php?display=sccp_content#general" class="form-inline" style="margin-bottom:16px">
                    <input type="hidden" name="content_action" value="contact">
                    <div class="form-group">
                        <label class="sr-only" for="new-contact-name"><?php echo _('Name'); ?></label>
                        <input class="form-control" id="new-contact-name" name="contact_name" maxlength="100" required placeholder="<?php echo _('Contact name'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="sr-only" for="new-contact-number"><?php echo _('Number'); ?></label>
                        <input class="form-control" id="new-contact-number" name="contact_number" maxlength="40" required placeholder="<?php echo _('Extension or number'); ?>">
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="fa fa-plus"></i> <?php echo _('Add'); ?></button>
                </form>
            </div>
            <div class="col-md-3">
                <input type="search" class="form-control" id="sccp-contact-filter" placeholder="<?php echo _('Search contacts'); ?>" aria-label="<?php echo _('Search contacts'); ?>">
            </div>
        </div>
        <div class="table-responsive">
            <table id="sccp-contact-table" class="table table-striped table-hover">
                <thead>
                    <tr><th><?php echo _('Name'); ?></th><th><?php echo _('Number'); ?></th><th class="text-right"><?php echo _('Actions'); ?></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $contact): ?>
                    <tr class="sccp-contact-entry" data-search="<?php echo $esc(strtolower($contact['name'] . ' ' . $contact['number'])); ?>">
                        <td><?php echo $esc($contact['name']); ?></td>
                        <td><?php echo $esc($contact['number']); ?></td>
                        <td class="text-right">
                            <button type="button" class="btn btn-default btn-sm sccp-contact-edit" title="<?php echo _('Edit'); ?>" aria-label="<?php echo _('Edit contact'); ?>"><i class="fa fa-pencil"></i></button>
                            <form method="post" action="config.php?display=sccp_content#general" style="display:inline" onsubmit="return confirm('Delete contact?')">
                                <input type="hidden" name="content_action" value="delete_contact">
                                <input type="hidden" name="id" value="<?php echo $esc($contact['id']); ?>">
                                <button class="btn btn-default btn-sm" type="submit" title="<?php echo _('Delete'); ?>" aria-label="<?php echo _('Delete contact'); ?>"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <tr class="sccp-contact-edit-row" style="display:none">
                        <td colspan="3">
                            <form method="post" action="config.php?display=sccp_content#general" class="form-inline">
                                <input type="hidden" name="content_action" value="contact">
                                <input type="hidden" name="id" value="<?php echo $esc($contact['id']); ?>">
                                <input class="form-control" name="contact_name" maxlength="100" value="<?php echo $esc($contact['name']); ?>" required aria-label="<?php echo _('Contact name'); ?>">
                                <input class="form-control" name="contact_number" maxlength="40" value="<?php echo $esc($contact['number']); ?>" required aria-label="<?php echo _('Contact number'); ?>">
                                <button type="submit" class="btn btn-primary"><?php echo _('Save'); ?></button>
                                <button type="button" class="btn btn-default sccp-contact-cancel"><?php echo _('Cancel'); ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$contacts): ?>
                    <tr><td colspan="3" class="text-center text-muted"><?php echo _('No contacts yet.'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted"><?php echo count($contacts); ?> <?php echo _('manually managed contacts. These remain shared Favorites. Changes appear the next time a phone opens the directory.'); ?></p>

        <h4><?php echo _('FreePBX Extensions'); ?></h4>
        <p class="help-block"><?php echo _('Loaded from Connectivity → Extensions, including SCCP phones. Favorites appear first for everybody. Directory extensions appear only under All Extensions. Hidden extensions appear in neither list. These settings are shared across phones.'); ?></p>
        <form method="post" action="config.php?display=sccp_content#general">
            <input type="hidden" name="content_action" value="extension_choices">
            <div class="row" style="margin-bottom:12px">
                <div class="col-sm-6">
                    <input type="search" class="form-control" id="sccp-extension-filter" placeholder="<?php echo _('Filter extensions'); ?>" aria-label="<?php echo _('Filter extensions'); ?>">
                </div>
                <div class="col-sm-6 text-right">
                    <button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> <?php echo _('Save Extension Directory'); ?></button>
                </div>
            </div>
            <div class="table-responsive" style="max-height:450px; overflow:auto">
                <table class="table table-striped table-hover" id="sccp-extension-table">
                    <thead>
                        <tr><th><?php echo _('Name'); ?></th><th><?php echo _('Extension'); ?></th><th><?php echo _('Display in directory'); ?></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($extensions as $ext): $extNumber = (string) $ext['number']; $extChoice = $extensionChoices[$extNumber] ?? 'directory'; ?>
                        <tr class="sccp-extension-entry" data-search="<?php echo $esc(strtolower($ext['name'] . ' ' . $extNumber)); ?>">
                            <td><?php echo $esc($ext['name']); ?></td>
                            <td><?php echo $esc($extNumber); ?></td>
                            <td>
                                <select class="form-control input-sm" name="extension_choice[<?php echo $esc($extNumber); ?>]" aria-label="<?php echo $esc('Directory setting for ' . $extNumber); ?>">
                                    <option value="directory" <?php echo $extChoice === 'directory' ? 'selected' : ''; ?>><?php echo _('All Extensions'); ?></option>
                                    <option value="favorite" <?php echo $extChoice === 'favorite' ? 'selected' : ''; ?>><?php echo _('Favorite + All Extensions'); ?></option>
                                    <option value="hidden" <?php echo $extChoice === 'hidden' ? 'selected' : ''; ?>><?php echo _('Hidden'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$extensions): ?>
                        <tr><td colspan="3" class="text-center text-muted"><?php echo _('No extensions found in FreePBX.'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> <?php echo _('Save Extension Directory'); ?></button>
            <p class="help-block"><?php echo _('No phone reboot is necessary. These choices affect every Cisco phone using the shared Phone Book.'); ?></p>
        </form>
        <details style="margin-top:18px">
            <summary><?php echo _('Phone display text'); ?></summary>
            <form method="post" action="config.php?display=sccp_content#general" style="margin-top:14px">
                <input type="hidden" name="content_action" value="directory_text">
                <?php
                $labels = $pc->directoryLabels();
                $groups = array(
                    _('Directories menu') => array(
                        'menu_title' => _('Screen title'),
                        'menu_prompt' => _('Bottom prompt'),
                        'menu_entry' => _('Phone Book entry')
                    ),
                    _('Contact list') => array(
                        'contacts_title' => _('Screen title'),
                        'contacts_prompt' => _('Bottom prompt'),
                        'favorites_entry' => _('Favorites entry'),
                        'extensions_entry' => _('All Extensions entry'),
                        'extensions_title' => _('All Extensions title'),
                        'extensions_prompt' => _('All Extensions prompt')
                    ),
                    _('Services menu') => array(
                        'services_title' => _('Screen title'),
                        'services_prompt' => _('Bottom prompt'),
                        'services_entry' => _('Address Book entry')
                    )
                );
                ?>
                <?php foreach ($groups as $groupName => $fields): ?>
                    <h4><?php echo $esc($groupName); ?></h4>
                    <div class="row">
                        <?php foreach ($fields as $key => $fieldName): ?>
                        <div class="col-sm-6 col-md-4">
                            <div class="form-group">
                                <label for="sccp-text-<?php echo $esc($key); ?>"><?php echo $esc($fieldName); ?></label>
                                <input type="text" class="form-control" id="sccp-text-<?php echo $esc($key); ?>"
                                    name="directory_text[<?php echo $esc($key); ?>]"
                                    value="<?php echo $esc($labels[$key]); ?>"
                                    maxlength="<?php echo strpos($key, 'prompt') !== false ? 80 : 60; ?>" required>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary"><?php echo _('Save'); ?></button>
                <p class="help-block"><?php echo _('Changes appear the next time the phone opens the menu. No phone reset or configuration regeneration is required.'); ?></p>
            </form>
        </details>
        <details style="margin-top:24px">
            <summary><?php echo _('Directory connection settings'); ?></summary>
            <div class="well" style="margin-top:12px">
                <p class="help-block"><?php echo _('The module hosts the directory automatically. Normally no configuration is needed here.'); ?></p>
                <form method="post" action="config.php?display=sccp_content#general" class="form-inline">
                    <input type="hidden" name="content_action" value="address">
                    <div class="form-group">
                        <label for="phonecontent-address"><?php echo _('PBX IP reachable by phones'); ?></label>
                        <input class="form-control" id="phonecontent-address" name="pbx_address"
                            value="<?php echo $esc(parse_url($base, PHP_URL_HOST) ?: ''); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-default"><?php echo _('Save'); ?></button>
                </form>
                <?php if ($base): ?>
                    <p class="help-block"><?php echo _('Directory URL:'); ?>
                        <a target="_blank" rel="noopener noreferrer" href="<?php echo $esc($base . '/phone.php?doc=directory'); ?>"><?php echo $esc($base . '/phone.php?doc=directory'); ?></a>
                    </p>
                <?php endif; ?>
            </div>
        </details>
    </div>
</div>
<script>
(function(){
    var filter=document.getElementById('sccp-contact-filter');
    if(filter){filter.addEventListener('input',function(){
        var query=this.value.trim().toLowerCase();
        document.querySelectorAll('#sccp-contact-table .sccp-contact-entry').forEach(function(row){
            var show=row.getAttribute('data-search').indexOf(query)!==-1;
            row.style.display=show?'':'none';
            if(!show && row.nextElementSibling)row.nextElementSibling.style.display='none';
        });
    });}
    var extFilter=document.getElementById('sccp-extension-filter');
    if(extFilter){extFilter.addEventListener('input',function(){
        var query=this.value.trim().toLowerCase();
        document.querySelectorAll('#sccp-extension-table .sccp-extension-entry').forEach(function(row){
            row.style.display=row.getAttribute('data-search').indexOf(query)!==-1?'':'none';
        });
    });}
    document.querySelectorAll('#sccp-contact-table .sccp-contact-edit').forEach(function(button){
        button.addEventListener('click',function(){
            var row=button.closest('tr').nextElementSibling;
            row.style.display=row.style.display==='none'?'':'none';
        });
    });
    document.querySelectorAll('#sccp-contact-table .sccp-contact-cancel').forEach(function(button){
        button.addEventListener('click',function(){button.closest('tr').style.display='none';});
    });
})();
</script>
