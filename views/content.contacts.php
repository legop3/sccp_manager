<?php
$pc = $this->phonecontent;
$esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$base = $pc->baseUrl();
$extensions = $pc->extensions();
$extensionChoices = $pc->extensionChoices();
$contacts = $pc->contacts();
$contactVisibility = $pc->contactVisibility();

// A single management table: FreePBX and manually added contacts belong to
// the same shared phone book. The old data is reused, not migrated or reset.
$entries = array();
foreach ($extensions as $ext) {
    $number = (string)$ext['number'];
    $entries[] = array(
        'type'=>'extension', 'id'=>$number, 'name'=>$ext['name'],
        'number'=>$number, 'visibility'=>$extensionChoices[$number] ?? 'directory'
    );
}
foreach ($contacts as $contact) {
    $id = (string)($contact['id'] ?? '');
    $entries[] = array(
        'type'=>'custom', 'id'=>$id, 'name'=>$contact['name'],
        'number'=>$contact['number'], 'visibility'=>$contactVisibility[$id] ?? 'favorite'
    );
}
usort($entries, function ($a, $b) {
    $compare = strcasecmp($a['name'], $b['name']);
    return $compare ?: strnatcmp($a['number'], $b['number']);
});
$counts = array('all'=>count($entries), 'favorite'=>0, 'hidden'=>0);
foreach ($entries as $entry) {
    if ($entry['visibility'] === 'favorite') $counts['favorite']++;
    if ($entry['visibility'] === 'hidden') $counts['hidden']++;
}
?>
<div class="fpbx-container container-fluid" id="pc-directory-manager">
    <div class="display no-border">
        <div class="row" style="margin-bottom:12px">
            <div class="col-sm-8">
                <h3 style="margin-top:0;margin-bottom:5px"><?php echo _('Address Book'); ?></h3>
                <p class="text-muted" style="margin-bottom:0">
                    <?php echo _('One shared directory for all Cisco phones. FreePBX extensions appear automatically; custom contacts can be added here.'); ?>
                </p>
            </div>
            <div class="col-sm-4 text-right" style="padding-top:6px">
                <button class="btn btn-default" id="pc-add-contact" type="button">
                    <i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add contact'); ?>
                </button>
            </div>
        </div>

        <div class="well" id="pc-contact-editor" style="display:none;margin-bottom:18px">
            <form id="pc-contact-editor-form" method="post" action="config.php?display=sccp_content#general">
                <input type="hidden" name="content_action" value="contact">
                <input type="hidden" name="id" id="pc-editor-id" value="">
                <h4 id="pc-editor-title" style="margin-top:0"><?php echo _('Add contact'); ?></h4>
                <div class="row">
                    <div class="col-sm-5">
                        <div class="form-group">
                            <label for="pc-editor-name"><?php echo _('Name'); ?></label>
                            <input id="pc-editor-name" class="form-control" name="contact_name" maxlength="100" required>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label for="pc-editor-number"><?php echo _('Number to dial'); ?></label>
                            <input id="pc-editor-number" class="form-control" name="contact_number" maxlength="40" required>
                        </div>
                    </div>
                    <div class="col-sm-3" style="padding-top:25px">
                        <button type="submit" class="btn btn-primary"><?php echo _('Save contact'); ?></button>
                        <button id="pc-editor-cancel" type="button" class="btn btn-default"><?php echo _('Cancel'); ?></button>
                    </div>
                </div>
            </form>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <div class="row" style="margin:0">
                    <div class="col-md-5" style="padding:0 8px 0 0">
                        <label class="sr-only" for="pc-directory-search"><?php echo _('Find a contact or extension'); ?></label>
                        <input type="search" class="form-control" id="pc-directory-search"
                            placeholder="<?php echo _('Search name or number'); ?>"
                            aria-label="<?php echo _('Search name or number'); ?>">
                    </div>
                    <div class="col-md-7" style="padding:4px 0 0">
                        <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo _('Filter directory contacts'); ?>">
                            <button type="button" class="btn btn-default active pc-directory-filter" data-filter="all" aria-pressed="true"><?php echo _('All'); ?> <span id="pc-count-all"><?php echo (int)$counts['all']; ?></span></button>
                            <button type="button" class="btn btn-default pc-directory-filter" data-filter="favorite" aria-pressed="false"><?php echo _('Favorites'); ?> <span id="pc-count-favorite"><?php echo (int)$counts['favorite']; ?></span></button>
                            <button type="button" class="btn btn-default pc-directory-filter" data-filter="hidden" aria-pressed="false"><?php echo _('Hidden'); ?> <span id="pc-count-hidden"><?php echo (int)$counts['hidden']; ?></span></button>
                        </div>
                    </div>
                </div>
            </div>
            <form id="pc-directory-choices-form" method="post" action="config.php?display=sccp_content#general">
                <input type="hidden" name="content_action" value="directory_choices">
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="pc-directory-table" style="margin-bottom:0">
                        <thead>
                            <tr>
                                <th><?php echo _('Contact'); ?></th>
                                <th><?php echo _('Number'); ?></th>
                                <th><?php echo _('Source'); ?></th>
                                <th style="min-width:175px"><?php echo _('Show in'); ?></th>
                                <th class="text-right"><?php echo _('Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry):
                                $custom = $entry['type'] === 'custom';
                                $selectName = $custom ? 'contact_choice[' . $entry['id'] . ']' :
                                    'extension_choice[' . $entry['number'] . ']';
                            ?>
                            <tr class="pc-directory-entry"
                                data-search="<?php echo $esc(strtolower($entry['name'] . ' ' . $entry['number'])); ?>"
                                data-visibility="<?php echo $esc($entry['visibility']); ?>"
                                data-source="<?php echo $esc($entry['type']); ?>">
                                <td><strong><?php echo $esc($entry['name']); ?></strong></td>
                                <td><?php echo $esc($entry['number']); ?></td>
                                <td class="text-muted">
                                    <?php echo $custom ? _('Custom') : _('FreePBX'); ?>
                                </td>
                                <td>
                                    <select class="form-control input-sm pc-visibility"
                                        name="<?php echo $esc($selectName); ?>"
                                        aria-label="<?php echo $esc('Directory visibility for ' . $entry['name']); ?>">
                                        <?php if (!$custom): ?>
                                        <option value="directory" <?php echo $entry['visibility'] === 'directory' ? 'selected' : ''; ?>><?php echo _('All Extensions'); ?></option>
                                        <?php endif; ?>
                                        <option value="favorite" <?php echo $entry['visibility'] === 'favorite' ? 'selected' : ''; ?>><?php echo _('Favorites'); ?></option>
                                        <option value="hidden" <?php echo $entry['visibility'] === 'hidden' ? 'selected' : ''; ?>><?php echo _('Hidden'); ?></option>
                                    </select>
                                </td>
                                <td class="text-right" style="white-space:nowrap">
                                    <?php if ($custom): ?>
                                    <button type="button" class="btn btn-default btn-sm pc-edit-contact"
                                        data-id="<?php echo $esc($entry['id']); ?>"
                                        data-name="<?php echo $esc($entry['name']); ?>"
                                        data-number="<?php echo $esc($entry['number']); ?>"
                                        title="<?php echo _('Edit contact'); ?>" aria-label="<?php echo _('Edit contact'); ?>">
                                        <i class="fa fa-pencil" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="btn btn-default btn-sm pc-delete-contact"
                                        data-id="<?php echo $esc($entry['id']); ?>"
                                        data-name="<?php echo $esc($entry['name']); ?>"
                                        title="<?php echo _('Delete contact'); ?>" aria-label="<?php echo _('Delete contact'); ?>">
                                        <i class="fa fa-trash" aria-hidden="true"></i>
                                    </button>
                                    <?php else: ?>
                                    <span class="text-muted" title="<?php echo _('Rename this extension in FreePBX Connectivity → Extensions'); ?>">
                                        <i class="fa fa-lock" aria-hidden="true"></i>
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$entries): ?>
                            <tr id="pc-directory-empty"><td colspan="5" class="text-center text-muted">
                                <?php echo _('No extensions or custom contacts found.'); ?>
                            </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="panel-footer" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">
                    <span id="pc-directory-visible-count" class="text-muted">
                        <?php echo count($entries); ?> <?php echo _('contacts'); ?>
                    </span>
                    <button type="submit" id="pc-directory-save" class="btn btn-primary" disabled>
                        <i class="fa fa-save" aria-hidden="true"></i> <?php echo _('Save directory'); ?>
                    </button>
                </div>
            </form>
        </div>

        <form id="pc-contact-delete-form" method="post" action="config.php?display=sccp_content#general">
            <input type="hidden" name="content_action" value="delete_contact">
            <input type="hidden" name="id" id="pc-delete-id" value="">
        </form>
        <p class="help-block">
            <?php echo _('Favorites appear first on every phone. All Extensions includes non-hidden FreePBX extensions. Hidden contacts are excluded from the phone directory. No phone reset is required.'); ?>
        </p>
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
(function () {
    'use strict';
    var root = document.getElementById('pc-directory-manager');
    if (!root) return;
    var search = root.querySelector('#pc-directory-search');
    var rows = Array.prototype.slice.call(root.querySelectorAll('.pc-directory-entry'));
    var filters = Array.prototype.slice.call(root.querySelectorAll('.pc-directory-filter'));
    var savedButton = root.querySelector('#pc-directory-save');
    var count = root.querySelector('#pc-directory-visible-count');
    var active = 'all';

    function updateDirectory() {
        var q = search.value.trim().toLowerCase(), visible = 0;
        var totals = {all: rows.length, favorite: 0, hidden: 0};
        rows.forEach(function (row) {
            var state = row.getAttribute('data-visibility');
            if (state === 'favorite') totals.favorite++;
            if (state === 'hidden') totals.hidden++;
            var show = (active === 'all' || active === state) &&
                row.getAttribute('data-search').indexOf(q) !== -1;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        ['all', 'favorite', 'hidden'].forEach(function (key) {
            root.querySelector('#pc-count-' + key).textContent = totals[key];
        });
        count.textContent = visible + ' of ' + rows.length + ' contacts';
    }

    search.addEventListener('input', updateDirectory);
    filters.forEach(function (button) {
        button.addEventListener('click', function () {
            active = button.getAttribute('data-filter');
            filters.forEach(function (filter) {
                var selected = filter === button;
                filter.classList.toggle('active', selected);
                filter.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            updateDirectory();
        });
    });
    root.querySelectorAll('.pc-visibility').forEach(function (select) {
        select.addEventListener('change', function () {
            select.closest('tr').setAttribute('data-visibility', select.value);
            savedButton.disabled = false;
            updateDirectory();
        });
    });

    var editor = root.querySelector('#pc-contact-editor');
    var editorTitle = root.querySelector('#pc-editor-title');
    var editorId = root.querySelector('#pc-editor-id');
    var editorName = root.querySelector('#pc-editor-name');
    var editorNumber = root.querySelector('#pc-editor-number');
    function openEditor(id, name, number) {
        editorId.value = id || '';
        editorName.value = name || '';
        editorNumber.value = number || '';
        editorTitle.textContent = id ? 'Edit contact' : 'Add contact';
        editor.style.display = '';
        editorName.focus();
    }
    root.querySelector('#pc-add-contact').addEventListener('click', function () {
        openEditor('', '', '');
    });
    root.querySelector('#pc-editor-cancel').addEventListener('click', function () {
        editor.style.display = 'none';
    });
    root.querySelectorAll('.pc-edit-contact').forEach(function (button) {
        button.addEventListener('click', function () {
            openEditor(button.getAttribute('data-id'),
                button.getAttribute('data-name'), button.getAttribute('data-number'));
        });
    });
    root.querySelectorAll('.pc-delete-contact').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!confirm('Delete "' + button.getAttribute('data-name') + '" from the shared directory?')) return;
            root.querySelector('#pc-delete-id').value = button.getAttribute('data-id');
            root.querySelector('#pc-contact-delete-form').submit();
        });
    });
    updateDirectory();
})();
</script>
