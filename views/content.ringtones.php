<?php
$pc = $this->phonecontent;
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$ringtones = $pc->ringtones();
?>
<div class="fpbx-container container-fluid">
    <div class="display no-border">
        <h3><?php echo _('Ringtones'); ?></h3>
        <form method="post" enctype="multipart/form-data" action="config.php?display=sccp_content#ringtones" class="form-inline" style="margin-bottom:20px">
            <input type="hidden" name="content_action" value="ringtone">
            <div class="form-group">
                <label class="sr-only" for="ring-name"><?php echo _('Ringtone name'); ?></label>
                <input id="ring-name" name="label" class="form-control" maxlength="25" required placeholder="<?php echo _('Ringtone name'); ?>">
            </div>
            <div class="form-group">
                <label class="sr-only" for="ring-file"><?php echo _('Audio'); ?></label>
                <input id="ring-file" name="media" class="form-control" type="file" accept=".wav,.mp3,.ogg,.flac,.m4a,audio/*" required>
            </div>
            <button class="btn btn-primary" type="submit"><i class="fa fa-upload"></i> <?php echo _('Upload'); ?></button>
        </form>
        <?php if ($ringtones): ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead><tr><th><?php echo _('Ringtone'); ?></th><th><?php echo _('Preview'); ?></th><th class="text-right"><?php echo _('Actions'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($ringtones as $ring): ?>
                            <tr>
                                <td><?php echo $esc($ring['name']); ?></td>
                                <td>
                                    <audio controls preload="none" style="width:100%;max-width:290px"
                                        src="<?php echo $esc('/sccp-manager/ringtone-' . $ring['id'] . '.' . $ring['ext']); ?>"></audio>
                                </td>
                                <td class="text-right">
                                    <form method="post" action="config.php?display=sccp_content#ringtones" onsubmit="return confirm('Remove ringtone?')">
                                        <input type="hidden" name="content_action" value="delete_ringtone">
                                        <input type="hidden" name="id" value="<?php echo $esc($ring['id']); ?>">
                                        <button type="submit" class="btn btn-default btn-sm" title="<?php echo _('Remove'); ?>"><i class="fa fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted"><?php echo _('No custom ringtones yet. Upload a WAV, MP3, OGG, FLAC or M4A file; it is converted to Cisco format automatically.'); ?></p>
        <?php endif; ?>
    </div>
</div>
