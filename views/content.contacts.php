<?php
$pc = $this->phonecontent;
$base = $pc->baseUrl();
$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$contacts = $pc->contacts();
?>
<div class="container-fluid">
    <h3>Address Book</h3>
    <p>Contacts are published as Cisco IP Phone XML. Changes appear when the phone opens the directory; no phone restart is needed for contact edits.</p>
    <form method="post" action="config.php?display=sccp_content#general" class="form-inline" style="margin-bottom:16px">
        <input type="hidden" name="content_action" value="address">
        <label for="pbx_address">PBX IP reachable by phones:</label>
        <input class="form-control" id="pbx_address" name="pbx_address" placeholder="192.168.0.25"
            value="<?php echo $esc(parse_url($base, PHP_URL_HOST) ?: ''); ?>" required>
        <button class="btn btn-default" type="submit">Save address</button>
    </form>
    <?php if (!$base): ?>
        <div class="alert alert-warning">Enter the PBX IP address above to enable automatic directory URLs.</div>
    <?php else: ?>
        <p>Directory: <code><?php echo $esc($base . '/directory.xml'); ?></code></p>
        <p>Services: <code><?php echo $esc($base . '/services.xml'); ?></code></p>
        <p>These are filled into <strong>Server Config → SCCP Device URL</strong> when the existing fields are empty. Custom URLs are preserved.</p>
        <form method="post" action="config.php?display=sccp_content#general" style="display:inline-block;margin-bottom:16px">
            <input type="hidden" name="content_action" value="urls">
            <button type="submit" class="btn btn-default">Publish directory and fill empty URLs</button>
        </form>
        <form method="post" action="config.php?display=sccp_content#general" style="display:inline-block;margin-bottom:16px">
            <input type="hidden" name="content_action" value="regenerate">
            <button type="submit" class="btn btn-primary">Apply URLs to phone configurations</button>
        </form>
        <p class="text-muted">Applying URLs regenerates SEP configuration files (including signatures where enabled), but does not restart connected phones. Reset a phone through Phones Manager once to load newly provisioned URLs.</p>
    <?php endif; ?>
    <h4>Add contact</h4>
    <form method="post" action="config.php?display=sccp_content#general" class="form-inline" style="margin-bottom:18px">
        <input type="hidden" name="content_action" value="contact">
        <input name="contact_name" class="form-control" placeholder="Contact name" maxlength="100" required>
        <input name="contact_number" class="form-control" placeholder="Extension or number" maxlength="40" required>
        <button class="btn btn-primary" type="submit">Add contact</button>
    </form>
    <h4>Saved contacts (<?php echo count($contacts); ?>)</h4>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead><tr><th>Name</th><th>Phone number</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($contacts as $item): ?>
                <tr>
                    <td colspan="2">
                        <form method="post" class="form-inline" id="edit-contact-<?php echo $esc($item['id']); ?>" action="config.php?display=sccp_content#general">
                            <input type="hidden" name="content_action" value="contact">
                            <input type="hidden" name="id" value="<?php echo $esc($item['id']); ?>">
                            <input class="form-control" name="contact_name" maxlength="100" required value="<?php echo $esc($item['name']); ?>">
                            <input class="form-control" name="contact_number" maxlength="40" required value="<?php echo $esc($item['number']); ?>">
                            <button type="submit" class="btn btn-default">Save</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" action="config.php?display=sccp_content#general"
                            onsubmit="return confirm('Delete this contact?')">
                            <input type="hidden" name="content_action" value="delete_contact">
                            <input type="hidden" name="id" value="<?php echo $esc($item['id']); ?>">
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$contacts): ?><tr><td colspan="3">No contacts yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
