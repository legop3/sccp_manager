<?php
$pc = $this->phonecontent;
$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$wallpapers = $pc->wallpapers();
?>
<div class="container-fluid sccp-phone-content">
    <div class="sccp-content-heading">
        <div>
            <h3>Wallpapers</h3>
            <p>Images for Cisco 7945G and 7965G color displays. Upload an image; the required full-size and thumbnail PNGs and Cisco list are generated automatically.</p>
        </div>
        <div class="sccp-content-count"><?php echo count($wallpapers); ?> / 50</div>
    </div>

    <section class="sccp-content-panel">
        <h4>Add wallpaper</h4>
        <form method="post" enctype="multipart/form-data" action="config.php?display=sccp_content#wallpapers" class="sccp-content-upload">
            <input type="hidden" name="content_action" value="wallpaper">
            <div class="sccp-content-upload-preview">
                <img id="sccp-wallpaper-preview" alt="Selected wallpaper preview" style="display:none">
                <div id="sccp-wallpaper-placeholder">
                    <i class="fa fa-image fa-2x"></i>
                    <p>Select an image to preview it</p>
                </div>
            </div>
            <div class="sccp-content-upload-fields">
                <label>Name (optional)
                    <input id="wallpaper-name" name="label" class="form-control" maxlength="60" placeholder="e.g. Sunset">
                </label>
                <label>Image file — PNG, JPEG or WebP, up to 8 MB
                    <input id="wallpaper-file" name="media" type="file" accept="image/png,image/jpeg,image/webp" class="form-control" required
                        onchange="(function(i){var f=i.files[0],img=document.getElementById('sccp-wallpaper-preview'),p=document.getElementById('sccp-wallpaper-placeholder');if(!f){img.style.display='none';p.style.display='';return;}var u=URL.createObjectURL(f);img.onload=function(){URL.revokeObjectURL(u);};img.src=u;img.style.display='';p.style.display='none';})(this)">
                </label>
                <p class="text-muted">Output: 320 × 212 background + 80 × 53 thumbnail, saved to the phone's TFTP directory.</p>
                <button type="submit" class="btn btn-primary">Upload wallpaper</button>
            </div>
        </form>
    </section>

    <section class="sccp-content-panel">
        <div class="sccp-content-heading">
            <div>
                <h4>Publishing and phone status</h4>
                <p class="text-muted">Publishing files alone isn't enough if the phone configuration doesn't allow choosing backgrounds.</p>
            </div>
            <form method="post" action="config.php?display=sccp_content#wallpapers">
                <input type="hidden" name="content_action" value="regenerate">
                <button type="submit" class="btn btn-primary">Apply wallpaper setting to phones</button>
            </form>
        </div>
        <?php $this->phoneContentStatusTab = 'wallpapers'; include __DIR__ . '/content.status.php'; ?>
        <p class="help-block">After applying, reset the phone from Phones Manager once. Open Settings → User Preferences → Background Images. If it still says "Selections Unavailable", test the actual TFTP request; see the troubleshooting command below.</p>
        <details class="sccp-content-details">
            <summary>TFTP troubleshooting</summary>
            <p>On the PBX, request the same index the phone requests, using your configured TFTP address in place of the example IP:</p>
            <pre>tftp 192.168.0.25 -m binary -c get Desktops/320x212x16/List.xml /tmp/sccp-wallpaper-test.xml</pre>
            <p>Use <code>sudo apt install tftp</code> if the client command isn't installed. If this fetch fails, the phone can't retrieve the wallpaper list either. If it succeeds from the PBX but not from the phone, inspect UDP 69/firewall routing over the VPN.</p>
        </details>
    </section>

    <section class="sccp-content-panel">
        <h4>Uploaded wallpapers</h4>
        <?php if ($wallpapers): ?>
            <div class="sccp-content-gallery">
            <?php foreach ($wallpapers as $item): ?>
                <div class="sccp-content-image-card">
                    <div class="sccp-content-image-frame">
                        <img alt="<?php echo $esc($item['name']); ?>" loading="lazy"
                            src="<?php echo $esc('/sccp-manager/wallpaper-' . $item['id'] . '.png'); ?>">
                    </div>
                    <div class="sccp-content-image-meta">
                        <strong><?php echo $esc($item['name']); ?></strong>
                        <form method="post" action="config.php?display=sccp_content#wallpapers"
                            onsubmit="return confirm('Delete this wallpaper from all phones?')">
                            <input type="hidden" name="content_action" value="delete_wallpaper">
                            <input type="hidden" name="id" value="<?php echo $esc($item['id']); ?>">
                            <button type="submit" class="btn btn-default btn-sm">Remove</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?><p class="sccp-content-empty">No wallpapers yet. Upload an image above to create the Cisco TFTP file list.</p><?php endif; ?>
    </section>
</div>
