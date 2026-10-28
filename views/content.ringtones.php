<?php
$pc = $this->phonecontent;
$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$ringtones = $pc->ringtones();
$base = $pc->baseUrl();
?>
<div class="container-fluid">
    <h3>Ringtones</h3>
    <p>Upload WAV, MP3, OGG, FLAC or M4A audio. SCCP Manager converts it with ffmpeg to the 7945G's headerless 8 kHz μ-law format, trims it to 2 seconds, and updates <code>RingList.xml</code>.</p>
    <form method="post" enctype="multipart/form-data" action="config.php?display=sccp_content#ringtones">
        <input type="hidden" name="content_action" value="ringtone">
        <div class="form-group"><label for="ring-name">Phone display name (max 25 characters)</label>
            <input id="ring-name" name="label" class="form-control" maxlength="25" required placeholder="Ringtone name"></div>
        <div class="form-group"><label for="ring-file">Audio</label>
            <input id="ring-file" name="media" type="file" accept=".wav,.mp3,.ogg,.flac,.m4a,audio/*" class="form-control" required></div>
        <button type="submit" class="btn btn-primary">Upload ringtone</button>
    </form>
    <hr>
    <h4>Ringtones (<?php echo count($ringtones); ?> / 50)</h4>
    <table class="table table-striped">
        <thead><tr><th>Name</th><th>Preview</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($ringtones as $item): ?>
                <tr>
                    <td><?php echo $esc($item['name']); ?></td>
                    <td><audio controls preload="none" style="max-width:250px;width:100%"
                            src="<?php echo $esc('/sccp-manager/ringtone-' . $item['id'] . '.' . $item['ext']); ?>"></audio></td>
                    <td><form method="post" action="config.php?display=sccp_content#ringtones"
                        onsubmit="return confirm('Delete this ringtone?')">
                        <input type="hidden" name="content_action" value="delete_ringtone">
                        <input type="hidden" name="id" value="<?php echo $esc($item['id']); ?>">
                        <button type="submit" class="btn btn-default btn-sm">Delete</button>
                    </form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$ringtones): ?><tr><td colspan="3">No custom ringtones yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="text-muted">Ringtones are available in the phone's Ring Type menu after it fetches the updated TFTP list. Browser previews play the original audio; the phone uses the converted two-second version.</p>
</div>
