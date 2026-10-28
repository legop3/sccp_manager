<?php
$pc = $this->phonecontent;
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$wallpapers = $pc->wallpapers();
$status = $pc->status();
?>
<div class="fpbx-container container-fluid">
    <div class="display no-border">
        <h3><?php echo _('Wallpapers'); ?></h3>
        <form method="post" enctype="multipart/form-data" action="config.php?display=sccp_content#wallpapers" class="form-inline" style="margin-bottom:20px">
            <input type="hidden" name="content_action" value="wallpaper">
            <div class="form-group">
                <label class="sr-only" for="wallpaper-label"><?php echo _('Wallpaper name'); ?></label>
                <input id="wallpaper-label" class="form-control" name="label" maxlength="60" placeholder="<?php echo _('Wallpaper name (optional)'); ?>">
            </div>
            <div class="form-group">
                <label class="sr-only" for="wallpaper-file"><?php echo _('Image'); ?></label>
                <input id="wallpaper-file" class="form-control" name="media" type="file" accept="image/png,image/jpeg,image/webp" required>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> <?php echo _('Upload'); ?></button>
        </form>
        <?php if (!$status['tftp_root_matches']): ?>
            <div class="alert alert-warning">
                <?php echo _('The TFTP server and SCCP Manager are using different root directories.'); ?>
                <code><?php echo $esc($status['tftp_service_root']); ?></code>
                <?php echo _('versus'); ?> <code><?php echo $esc($status['tftp_root']); ?></code>.
                <?php echo _('The phone cannot find the generated wallpapers until the roots match.'); ?>
            </div>
        <?php elseif ($wallpapers && (!$status['wallpaper_index_readable'] || !$status['wallpaper_files_ok'])): ?>
            <div class="alert alert-warning"><?php echo _('Some wallpaper files are missing or unreadable in the TFTP directory.'); ?></div>
        <?php endif; ?>
        <?php if (!$wallpapers): ?>
            <p class="text-muted"><?php echo _('No wallpapers uploaded. The 7945G uses 320 × 212 PNG images; they are created automatically.'); ?></p>
        <?php else: ?>
            <div class="row">
                <?php foreach ($wallpapers as $wallpaper): ?>
                    <div class="col-xs-6 col-sm-4 col-md-3">
                        <div class="thumbnail">
                            <img style="width:100%;height:auto" loading="lazy"
                                 src="<?php echo $esc('/sccp-manager/wallpaper-' . $wallpaper['id'] . '.png'); ?>"
                                 alt="<?php echo $esc($wallpaper['name']); ?>">
                            <div class="caption">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
                                    <span><?php echo $esc($wallpaper['name']); ?></span>
                                    <form method="post" action="config.php?display=sccp_content#wallpapers"
                                          onsubmit="return confirm('Remove wallpaper?')">
                                        <input type="hidden" name="content_action" value="delete_wallpaper">
                                        <input type="hidden" name="id" value="<?php echo $esc($wallpaper['id']); ?>">
                                        <button type="submit" class="btn btn-default btn-sm" title="<?php echo _('Remove'); ?>">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="help-block"><?php echo _('Wallpapers are published to TFTP automatically. New phone settings are signed on upload; the phone may need a reset to load a changed background-image setting.'); ?></p>
        <?php endif; ?>

        <details style="margin-top:24px">
            <summary><?php echo _('Wallpaper troubleshooting'); ?></summary>
            <div class="well" style="margin-top:12px">
                <p><?php echo _('The 7945G fetches this file from its configured TFTP server:'); ?>
                    <code>Desktops/320x212x16/List.xml</code>
                </p>
                <p><?php echo _('Generated file on PBX:'); ?> <code><?php echo $esc($status['wallpaper_index']); ?></code>
                    (<?php echo $status['wallpaper_index_readable'] ? _('readable') : _('missing or unreadable'); ?>)
                </p>
                <p><?php echo _('TFTP server root:'); ?> <code><?php echo $esc($status['tftp_service_root'] ?: _('not detected')); ?></code></p>
                <p><?php echo _('If the phone still says Selections Unavailable, use this command while opening Background Images to see whether the phone actually requests the file:'); ?></p>
                <pre>sudo tcpdump -ni any -s0 -A 'udp port 69'</pre>
                <p class="help-block"><?php echo _('If no List.xml request appears, check the phone’s TFTP address and VPN/firewall. If a request appears but fails, inspect the TFTP root and file permissions.'); ?></p>
            </div>
        </details>
    </div>
</div>
