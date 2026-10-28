<?php
$pc = $this->phonecontent;
$base = $pc->baseUrl();
$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$contacts = $pc->contacts();
$health = $pc->status();
?>
<div class="container-fluid sccp-phone-content">
    <div class="sccp-content-heading">
        <div>
            <h3>Address Book</h3>
            <p>Manage contacts once for every Cisco phone. Names and phone numbers are published as a browsable, dialable Cisco directory.</p>
        </div>
        <div class="sccp-content-count"><?php echo count($contacts); ?> contacts</div>
    </div>

    <section class="sccp-content-panel">
        <h4>Phone connection</h4>
        <p class="text-muted">The phone needs a reachable HTTP address for this FreePBX server. Use the PBX address routed over the phone's VPN, not the public web admin URL.</p>
        <form method="post" action="config.php?display=sccp_content#general" class="sccp-content-form-row">
            <input type="hidden" name="content_action" value="address">
            <label class="sccp-content-grow" for="pbx_address">PBX address for phones
                <input class="form-control" id="pbx_address" name="pbx_address" placeholder="192.168.0.25"
                    value="<?php echo $esc(parse_url($base, PHP_URL_HOST) ?: ''); ?>" required>
            </label>
            <button class="btn btn-default" type="submit">Save address</button>
        </form>
        <?php if ($base): ?>
        <div class="sccp-content-url">
            <span>Built-in directory URL</span>
            <code><?php echo $esc($base . '/phone.php?doc=directory'); ?></code>
            <a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="<?php echo $esc($base . '/phone.php?doc=directory'); ?>">Test URL</a>
        </div>
        <div class="sccp-content-actions">
            <form method="post" action="config.php?display=sccp_content#general">
                <input type="hidden" name="content_action" value="urls">
                <button type="submit" class="btn btn-default">Use built-in directory &amp; services</button>
            </form>
            <form method="post" action="config.php?display=sccp_content#general">
                <input type="hidden" name="content_action" value="regenerate">
                <button type="submit" class="btn btn-primary">Apply to phone configurations</button>
            </form>
            <form method="post" action="config.php?display=sccp_content#general"
                onsubmit="return confirm('Restore URLs saved before enabling the built-in phone content?')">
                <input type="hidden" name="content_action" value="restore_urls">
                <button type="submit" class="btn btn-link">Restore previous URLs</button>
            </form>
        </div>
        <p class="help-block">"Use built-in" explicitly replaces the two SCCP Device URL fields and remembers their previous values. "Apply" regenerates signed phone files without resetting phones; reset a phone from Phones Manager to load new URLs. Adding contacts later requires no restart.</p>
        <?php else: ?>
        <p class="alert alert-warning">Set a PBX IP address to publish the directory.</p>
        <?php endif; ?>
    </section>

    <section class="sccp-content-panel">
        <h4>Provisioning checks</h4>
        <?php $this->phoneContentStatusTab = 'general'; include __DIR__ . '/content.status.php'; ?>
    </section>

    <section class="sccp-content-panel">
        <h4>Add contact</h4>
        <form method="post" action="config.php?display=sccp_content#general" class="sccp-content-form-row">
            <input type="hidden" name="content_action" value="contact">
            <label class="sccp-content-grow">Name
                <input name="contact_name" class="form-control" placeholder="Example: Front Desk" maxlength="100" required>
            </label>
            <label class="sccp-content-grow">Phone number or extension
                <input name="contact_number" class="form-control" placeholder="150" maxlength="40" required>
            </label>
            <button class="btn btn-primary" type="submit">Add contact</button>
        </form>
    </section>

    <section class="sccp-content-panel">
        <div class="sccp-content-heading">
            <h4>Contacts</h4>
            <input class="form-control sccp-content-search" type="search" id="sccp-contact-search" placeholder="Search contacts…"
                oninput="document.querySelectorAll('#sccp-contact-rows tr[data-contact]').forEach(function(r){r.hidden=!r.dataset.contact.includes(document.getElementById('sccp-contact-search').value.toLowerCase())})">
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Contact</th><th>Actions</th></tr></thead>
                <tbody id="sccp-contact-rows">
                    <?php foreach ($contacts as $item): ?>
                    <tr data-contact="<?php echo $esc(strtolower($item['name'] . ' ' . $item['number'])); ?>">
                        <td>
                            <form method="post" class="sccp-content-form-row" id="edit-contact-<?php echo $esc($item['id']); ?>" action="config.php?display=sccp_content#general">
                                <input type="hidden" name="content_action" value="contact">
                                <input type="hidden" name="id" value="<?php echo $esc($item['id']); ?>">
                                <input class="form-control sccp-content-grow" aria-label="Contact name" name="contact_name" maxlength="100" required value="<?php echo $esc($item['name']); ?>">
                                <input class="form-control sccp-content-grow" aria-label="Phone number" name="contact_number" maxlength="40" required value="<?php echo $esc($item['number']); ?>">
                                <button type="submit" class="btn btn-default">Save</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" action="config.php?display=sccp_content#general"
                                onsubmit="return confirm('Delete this contact?')">
                                <input type="hidden" name="content_action" value="delete_contact">
                                <input type="hidden" name="id" value="<?php echo $esc($item['id']); ?>">
                                <button type="submit" class="btn btn-default">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$contacts): ?><tr><td colspan="2"><p class="sccp-content-empty">No contacts yet. Add one above.</p></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
