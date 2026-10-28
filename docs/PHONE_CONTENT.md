# Cisco Phone Content (SCCP Manager 17.1.16)

Open **SCCP Connectivity → Phone Content** in FreePBX. The page has three
tabs: Address Book, Wallpapers and Ringtones.

## Address Book

- Add, edit or delete contacts in FreePBX. The module generates static Cisco
  XML at \`/var/www/html/sccp-manager/directory.xml\` (the actual path is
  derived from FreePBX's AMPWEBROOT). Each edit republishes the directory.
- A static Services menu is also generated as \`services.xml\`, pointing to
  the address book. No separately installed PHP web service is needed.
- Up to 30 entries per directory document; longer books are automatically
  split into pages behind a CiscoIPPhoneMenu. Maximum 300 entries.
- The default HTTP base is the VPN PBX IP, falling back to the system's detected
  IPv4 address. Override it in Phone Content if necessary. Phones must be able
  to access that HTTP address, including when connected over the Cisco VPN.
- The module fills empty \`dev_directoryURL\` and \`dev_servicesURL\` defaults
  visible under **Server Config → SCCP Device URL**. Any unrelated custom
  URLs are left untouched. Choose **Apply URLs to phone configurations** to
  generate updated SEP XML and signatures, then reset phones **once** from
  Phones Manager to retrieve the new URL. New contact edits need no reset.
- The XML endpoints are public **static files** under the configured web root.
  Restrict web access on your network as appropriate if contacts are sensitive.

## Wallpapers

The 7945/7965 SCCP firmware looks for:

- \`/tftpboot/Desktops/320x212x16/List.xml\`
- \`/tftpboot/Desktops/320x212x16/PC-<id>.png\` (320x212)
- \`/tftpboot/Desktops/320x212x16/TN-PC-<id>.png\` (80x53)

Upload PNG/JPEG/WebP through the UI; PHP GD converts to Cisco-compatible
PNG sizes and also generates a browser preview. The first upload sets
\`backgroundImageAccess=on\` in SCCP server defaults; regenerate the phone
configuration and reset the phone once to enable the menu. Existing manually
managed \`List.xml\` is NOT overwritten; move/migrate it first. Maximum 50.

## Ringtones

The UI accepts WAV, MP3, OGG, FLAC and M4A. ffmpeg converts audio into
8000 Hz, 8-bit, mono, headerless u-law limited to 16080 samples (2.01 seconds),
with a length divisible by 240. It publishes \`PC-<id>.raw\` in TFTP root and
updates the case-sensitive \`RingList.xml\`. The phone offers those under Ring
Type settings. Source audio is kept as a browser preview in the static web
folder. Existing manually managed \`RingList.xml\` is NOT overwritten.

## Installation and permissions

A root \`fwconsole ma downloadinstall\` creates the static directory
\`sccp-manager\` under the web root, owned by \`asterisk\`. It attempts to
install \`php-gd\` and \`ffmpeg\` using APT if absent, and prints actionable
instructions if package setup fails. The TFTP root must also be writable by
the web user (normally \`asterisk\`); follow FreePBX's usual \`fwconsole chown\`
practice if needed.

Phone Content does not replace VPN credentials, ITL, or signing certificates.
Regenerating phones uses the existing signed provisioning pipeline.

## 17.1.16 compatibility fixes

- The publicly served directory is under AMPWEBROOT/sccp-manager, not the parent of AMPWEBROOT.
- The phone's directory URL is http://PBX-IP/sccp-manager/phone.php?doc=directory, with a valid CiscoIPPhoneMenu and links to contacts. The PHP endpoint explicitly returns Content-Type: text/xml.
- The Phone Content UI can override stale SCCP Device URL placeholders and restore previous settings.
- For 7945G models, the missing commonProfile/backgroundImageAccess node is added to the generated SEP XML, without requiring an installed template update.
- Wallpaper PNGs are generated as opaque RGB, not RGBA.
- The UI shows on-disk media and deployed phone configuration diagnostics, but cannot guarantee TFTP/HTTP reachability over the VPN.

Check the HTTP endpoint using: curl -i 'http://192.168.0.25/sccp-manager/phone.php?doc=directory'. Expect HTTP 200, text/xml, and CiscoIPPhoneMenu.

Check TFTP delivery using: tftp 192.168.0.25 -m binary -c get Desktops/320x212x16/List.xml /tmp/7945-wallpapers.xml.
