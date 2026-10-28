<?php
$pc = $this->phonecontent;
$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$wallpapers = $pc->wallpapers();
$base = $pc->baseUrl();
?>
<div class="container-fluid">
    <h3>Wallpapers</h3>
    <p>Upload a PNG, JPEG or WebP image. SCCP Manager generates a 320 × 212 PNG and 80 × 53 preview for Cisco 7945/7965 phones and publishes <code>Desktops/320x212x16/List.xml</code> over TFTP.</p>
    <p class="text-muted">The first upload enables background image access in the site defaults. Regenerate the phone configuration once to apply that setting. Wallpaper choices appear under the phone's Background Images menu.</p>
    <form method="post" enctype="multipart/form-data" action="config.php?display=sccp_content#wallpapers">
        <input type="hidden" name="content_action" value="wallpaper">
        <div class="form-group"><label for="wallpaper-name">Name</label>
            <input id="wallpaper-name" name="label" class="form-control" maxlength="60" placeholder="Optional display name"></div>
        <div class="form-group"><label for="wallpaper-file">Image</label>
            <input id="wallpaper-file" name="media" type="file" accept="image/png,image/jpeg,image/webp" class="form-control" required></div>
        <button type="submit" class="btn btn-primary">Upload wallpaper</button>
    </form>
    <hr>
    <h4>Wallpapers (<?php echo count($wallpapers); ?> / 50)</h4>
    <div class="row">
        <?php foreach ($wallpapers as $item): ?>
            <div class="col-xs-6 col-md-3" style="margin-bottom:18px">
                <div class="thumbnail">
                    <img alt="" style="width:100%;image-rendering:auto"
                        src="<?php echo $esc('/sccp-manager/wallpaper-' . $item['id'] . '.png'); ?>">
                    <div class="caption">
                        <strong><?php echo $esc($item['name']); ?></strong>
                        <form method="post" action="config.php?display=sccp_content#wallpapers" style="margin-top:8px"
                            onsubmit="return confirm('Delete this wallpaper?')">
                            <input type="hidden" name="content_action" value="delete_wallpaper">
                            <input type="hidden" name="id" value="<?php echo $esc($item['id']); ?>">
                            <button type="submit" class="btn btn-default btn-sm">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (!$wallpapers): ?><p>No wallpapers uploaded yet.</p><?php endif; ?>
</div>
