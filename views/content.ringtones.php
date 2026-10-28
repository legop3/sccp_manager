<?php
$pc = $this->phonecontent;
$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$ringtones = $pc->ringtones();
?>
<div class="container-fluid sccp-phone-content">
    <div class="sccp-content-heading">
        <div>
            <h3>Ringtones</h3>
            <p>Upload a short audio clip and SCCP Manager handles the conversion and Cisco ringtone index automatically.</p>
        </div>
        <div class="sccp-content-count"><?php echo count($ringtones); ?> / 50</div>
    </div>
    <section class="sccp-content-panel">
        <h4>Add ringtone</h4>
        <form method="post" enctype="multipart/form-data" action="config.php?display=sccp_content#ringtones" class="sccp-content-form-row">
            <input type="hidden" name="content_action" value="ringtone">
            <label class="sccp-content-grow">Name on phone
                <input id="ring-name" name="label" class="form-control" maxlength="25" required placeholder="e.g. Chime">
            </label>
            <label class="sccp-content-grow">Audio (WAV, MP3, OGG, FLAC, M4A)
                <input id="ring-file" name="media" type="file" accept=".wav,.mp3,.ogg,.flac,.m4a,audio/*" class="form-control" required>
            </label>
            <button type="submit" class="btn btn-primary">Upload ringtone</button>
        </form>
        <p class="text-muted">The 7945G receives a two-second, 8000 Hz, 8-bit μ-law ringtone. The preview player uses the original file so longer source recordings may sound different.</p>
    </section>
    <section class="sccp-content-panel">
        <h4>Publishing status</h4>
        <?php $this->phoneContentStatusTab = 'ringtones'; include __DIR__ . '/content.status.php'; ?>
        <p class="text-muted">Ringtones are fetched from <code>RingList.xml</code> in the TFTP root, independent of the address book's HTTP URL.</p>
    </section>
    <section class="sccp-content-panel">
        <h4>Uploaded ringtones</h4>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Name</th><th>Listen</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($ringtones as $item): ?>
                    <tr>
                        <td><strong><?php echo $esc($item['name']); ?></strong></td>
                        <td><audio controls preload="none" style="max-width:300px;width:100%"
                            src="<?php echo $esc('/sccp-manager/ringtone-' . $item['id'] . '.' . $item['ext']); ?>"></audio></td>
                        <td class="text-right">
                            <form method="post" action="config.php?display=sccp_content#ringtones"
                                onsubmit="return confirm('Delete this ringtone from the phone library?')">
                                <input type="hidden" name="content_action" value="delete_ringtone">
                                <input type="hidden" name="id" value="<?php echo $esc($item['id']); ?>">
                                <button type="submit" class="btn btn-default btn-sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$ringtones): ?><tr><td colspan="3"><p class="sccp-content-empty">No custom ringtones yet.</p></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
