<?php
$pc_status = $this->phonecontent->status();
$esc_status = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$pc_selected = (string) ($this->phoneContentStatusTab ?? 'general');
?>
<div class="sccp-content-status">
    <?php if ($pc_selected === 'general'): ?>
        <div class="sccp-content-checks">
            <div class="sccp-content-check <?php echo $pc_status['directory_exists'] && $pc_status['directory_readable'] ? 'ok' : 'bad'; ?>">
                <strong><?php echo $pc_status['directory_exists'] && $pc_status['directory_readable'] ? '✓' : '!'; ?> Directory XML</strong>
                <span><?php echo $pc_status['directory_exists'] ? 'Published on disk' : 'Missing from web root'; ?></span>
                <code><?php echo $esc_status($pc_status['directory_file']); ?></code>
            </div>
            <div class="sccp-content-check <?php echo $pc_status['url_matches'] ? 'ok' : 'bad'; ?>">
                <strong><?php echo $pc_status['url_matches'] ? '✓' : '!'; ?> Provisioned URL</strong>
                <span><?php echo $pc_status['url_matches'] ? 'SCCP Device URL matches managed address' : 'SCCP Device URL points elsewhere'; ?></span>
                <code><?php echo $esc_status($pc_status['configured_url'] ?: '(not configured)'); ?></code>
            </div>
        </div>
        <?php if ($pc_status['expected_url']): ?>
        <p><strong>Test the directory:</strong>
            <a href="<?php echo $esc_status($pc_status['expected_url']); ?>" target="_blank" rel="noopener noreferrer">Open the exact phone URL</a>.
            The browser must display <code>CiscoIPPhoneDirectory</code> or <code>CiscoIPPhoneMenu</code>,
            not a FreePBX login page or 404.
        </p>
        <?php endif; ?>
    <?php elseif ($pc_selected === 'wallpapers'): ?>
        <div class="sccp-content-checks">
            <div class="sccp-content-check <?php echo $pc_status['wallpaper_index_exists'] && $pc_status['wallpaper_index_readable'] ? 'ok' : 'bad'; ?>">
                <strong><?php echo $pc_status['wallpaper_index_exists'] ? '✓' : '!'; ?> Cisco List.xml</strong>
                <span><?php echo $pc_status['wallpaper_index_exists'] ? 'File present' : 'File missing'; ?></span>
                <code><?php echo $esc_status($pc_status['wallpaper_index']); ?></code>
            </div>
            <div class="sccp-content-check <?php echo $pc_status['wallpaper_files_ok'] ? 'ok' : 'bad'; ?>">
                <strong><?php echo $pc_status['wallpaper_files_ok'] ? '✓' : '!'; ?> Image files</strong>
                <span><?php echo (int) $pc_status['wallpaper_count']; ?> saved; <?php echo $pc_status['wallpaper_files_ok'] ? 'PNG and thumbnail files readable' : 'No images, or image files missing'; ?></span>
            </div>
            <div class="sccp-content-check <?php echo $pc_status['wallpaper_access_enabled'] ? 'ok' : 'bad'; ?>">
                <strong><?php echo $pc_status['wallpaper_access_enabled'] ? '✓' : '!'; ?> Wallpaper setting</strong>
                <span><?php echo $pc_status['wallpaper_access_enabled'] ? 'Enabled in site settings' : 'Disabled in site settings'; ?></span>
            </div>
        </div>
        <p class="text-muted">These checks verify files on the PBX, not a TFTP transfer from the phone. If the files are present but the phone says "Selections Unavailable", inspect the deployed phone configuration below and test TFTP delivery.</p>
    <?php else: ?>
        <div class="sccp-content-checks">
            <div class="sccp-content-check <?php echo $pc_status['ring_index_exists'] ? 'ok' : 'bad'; ?>">
                <strong><?php echo $pc_status['ring_index_exists'] ? '✓' : '!'; ?> RingList.xml</strong>
                <span><?php echo $pc_status['ring_index_exists'] ? 'Published' : 'Not published'; ?></span>
                <code><?php echo $esc_status($pc_status['ring_index']); ?></code>
            </div>
            <div class="sccp-content-check <?php echo $pc_status['ring_count'] > 0 ? 'ok' : 'bad'; ?>">
                <strong><?php echo $pc_status['ring_count'] ? '✓' : '!'; ?> Custom sounds</strong>
                <span><?php echo (int) $pc_status['ring_count']; ?> available</span>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($pc_selected !== 'ringtones'): ?>
        <?php if ($pc_status['phones']): ?>
            <details class="sccp-content-details">
                <summary>Inspect deployed phone configuration (<?php echo count($pc_status['phones']); ?> phones)</summary>
                <div class="table-responsive">
                    <table class="table table-condensed">
                        <thead><tr><th>Phone</th><th>Directory URL in SEP XML</th><th>Wallpaper enabled</th><th>Signed</th></tr></thead>
                        <tbody>
                            <?php foreach ($pc_status['phones'] as $phone): ?>
                            <tr>
                                <td><code><?php echo $esc_status($phone['id']); ?></code></td>
                                <td><code><?php echo $esc_status($phone['directory_url'] ?: '(empty)'); ?></code></td>
                                <td><?php echo $esc_status($phone['wallpaper_enabled'] ?: '(not present)'); ?></td>
                                <td><?php echo $phone['signature_exists'] ? 'Yes' : 'No'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted">These values are read from the published <code>SEP*.cnf.xml</code> files in <?php echo $esc_status($pc_status['tftp_root']); ?>, not from the database. "Signed" means an .sgn file exists; it is not a cryptographic signature check.</p>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</div>
