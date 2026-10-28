<?php
$pc = $this->phonecontent;
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$contacts = $pc->contacts();
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
        <p class="text-muted"><?php echo count($contacts); ?> <?php echo _('contacts'); ?>. <?php echo _('Changes appear on the phone the next time the directory is opened.'); ?></p>
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
