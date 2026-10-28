# Cisco Phone Content (SCCP Manager 17.1.17)

Open **SCCP Connectivity → Phone Content** in FreePBX. The page has four
tabs: Address Book, Menus, Wallpapers and Ringtones.

## Address Book

- Add, edit or delete contacts in FreePBX. The module generates static Cisco
  XML at \`/var/www/html/sccp-manager/directory.xml\` (the actual path is
  derived from FreePBX's AMPWEBROOT). Each edit republishes the directory.
- An editable Services menu is also generated as \`services.xml\`, pointing to
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

## Menus (17.1.22)

- Manage the shared Directories, Services and Messages button entries under Phone Content > Menus.
- Entries can be renamed, reordered or removed. Available actions: managed Address Book,
  native Missed/Received/Placed Calls, Cisco XML URL, dial action, static text page,
  and one level of submenus (which can be created, renamed, or deleted).
- Custom phone-button menus are OFF by default, preserving installed phones.
  When disabled, firmware adds its own native call histories to the Directories
  button; edited Directory/Services XML still updates live over HTTP.
- Enabling uses internal service provisioning (phoneServices / provisioning=0)
  with type 1 for Directories, 0 for Services, 2 for Messages in signed SEP XML.
  Messages supports one service, defaulting to the native Voicemail function.
- Saving publishes new XML immediately and regenerates signed phone configs
  through the existing VPN/ITL signing pipeline. It does not reboot phones.
  Reset one test phone to verify firmware behavior before resetting other phones.
- Contact pagination uses a separate phonebook.xml to avoid recursive menus.
- This is NOT a visual voicemail application. The native voicemail function
  is preserved until a compatible voicemail browser is separately implemented.
- URLs refer to Cisco-compatible XML services reachable by the phones over VPN.

Regression checks: php tests/phone-content.php ; php tests/phone-menus.php
## Visual Voicemail (17.1.26 preview)

1. In **Phone Content → Menus**, select **Messages** and change the action from
   **Voicemail (native)** to **Visual Voicemail (auto-answer playback)**.
2. Enable **Use custom phone-button menus** and save. SCCP Manager publishes
   `voicemail.php` and regenerates/saves signed SEP configurations.
3. Reset a test 7945G to activate its Messages-button service URL.
4. The phone's Messages button displays **its own** voicemail from the default
   SCCP line (mailbox `extension@default`), with INBOX and Old messages.
   Selecting a message opens Play, Mark as Heard, and Delete (with confirmation).
5. Play originates `SCCP/extension/aa=1w` via AMI with
   `Application: VoiceMailPlayMsg` and the message's stable Asterisk `msg_id`.
   The playback call's CallerID name/number are derived from the voicemail
   caller metadata (not a fixed 'Voicemail' label). It is rejected if the phone
   is already on a channel according to `CoreShowChannels`.

**Scope and compatibility:** This implementation reads the normal FreePBX
filesystem voicemail store `/var/spool/asterisk/voicemail/default/<extension>`
and uses Asterisk 22 AMI. ODBC/IMAP voicemail backends and non-default
voicemail contexts are not supported yet. Some older voicemail recordings
without a stable `msg_id` cannot be played and are omitted. The phone must
have a numeric SCCP default line assigned in SCCP Manager.

**Access:** Each phone receives a random, independently provisioned bearer
token embedded in its signed SEP phone service URL. Anyone who obtains the
full URL can access that mailbox, so restrict access to the HTTP endpoint
and TFTP provisioning files to trusted networks/VPN clients. Do not expose
`/sccp-manager/voicemail.php` on a public web proxy. Tokens are not a
substitute for network access controls. Regenerating signed phone configs
does not reset phones automatically.

**Playback note:** The 7945G firmware's exact behavior with a custom Messages
service and SCCP auto-answer playback still requires an on-device test.

A 17.1.24 fix ensures the standalone web endpoint boots FreePBX in unauthenticated
phone-service mode and loads the known installed BMO class explicitly. The first
Phone Content page load after upgrade republishes voicemail.php. No device reset
is required for this web endpoint correction. Access control still relies on the
per-device token and trusted LAN/VPN HTTP network restrictions.

Test: `php tests/phone-voicemail.php`.
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

## 17.1.17 compatibility fixes

- The publicly served directory is under AMPWEBROOT/sccp-manager, not the parent of AMPWEBROOT.
- The phone's directory URL is http://PBX-IP/sccp-manager/phone.php?doc=directory, with a valid CiscoIPPhoneMenu and links to contacts. The PHP endpoint explicitly returns Content-Type: text/xml.
- The Phone Content UI can override stale SCCP Device URL placeholders and restore previous settings.
- For 7945G models, the missing commonProfile/backgroundImageAccess node is added to the generated SEP XML, without requiring an installed template update.
- Wallpaper PNGs are generated as opaque RGB, not RGBA.
- The UI shows on-disk media and deployed phone configuration diagnostics, but cannot guarantee TFTP/HTTP reachability over the VPN.

Check the HTTP endpoint using: curl -i 'http://192.168.0.25/sccp-manager/phone.php?doc=directory'. Expect HTTP 200, text/xml, and CiscoIPPhoneMenu.

Check TFTP delivery using: tftp 192.168.0.25 -m binary -c get Desktops/320x212x16/List.xml /tmp/7945-wallpapers.xml.

## 17.1.17 UI and directory behavior

- The Phone Content pages now use standard FreePBX/Bootstrap form controls and tables; custom status cards and separate URL/Apply buttons are removed.
- Directory XML includes only Phone Book pages, starting with Phone Book. Native phone call-history entries are not duplicated. Cisco firmware may still place its built-in call histories before an appended custom directory item; the XML cannot force a firmware-defined ordering.
- Contact edits republish the directory on save. Initial contact setup configures phone XML automatically if needed.
- Wallpaper upload publishes List.xml and PNG thumbnails, enables background access, and regenerates the signed phone configuration automatically. It does not interrupt live calls by forcing a reset.
- The Wallpapers tab checks whether tftpd-hpa's configured TFTP_DIRECTORY matches the SCCP Manager publishing root. If Selections Unavailable persists, watch real TFTP requests while opening Background Images: sudo tcpdump -ni any -s0 -A 'udp port 69'. This is the remaining reliable way to distinguish a missing request from a TFTP-server error on the actual VPN path.

- Existing installations of 17.1.16 are automatically migrated on the first Phone Content page load: the old directory.xml is republished without duplicate call histories, and any existing wallpaper library triggers one signed configuration regeneration. This does not forcibly restart live phones.

## 17.1.25 playback fixes

Voicemail menu links resolve messages by stable ID even if the recording moves between INBOX and Old. Web responses are buffered and validated as Cisco XML, then flushed before playback begins. Asterisk plays recordings through an installed AGI which waits two seconds after SCCP auto-answer so the phone can establish its audio stream. Open Phone Content once after upgrading to publish the updated web endpoint; no phone reset is needed.

## 17.1.26 playback adjustment

The earlier two-second delay in the playback AGI and 1.2-second delay before call origination were speculative. Both are removed. Playback now runs directly via Asterisk `VoiceMailPlayMsg()`, with no hardcoded pause. The request-shutdown sequence still originates only after the complete Cisco XML document has been generated and flushed. Retained fixes include stable message IDs across INBOX/Old and XML validation. If a voicemail appears to cut off at the end, verify that the audio actually truncates and examine Asterisk playback completion and channel hangup logs rather than adding a delay.

## 17.1.27 visual voicemail navigation

- Playing a message returns to the **same message detail menu**, with a Play
  Again action. It no longer jumps back to the full voicemail list after playback.
- Each XML screen explicitly restores Select and offers Exit using the Cisco
  `Init:Messages` command, which closes the Messages application instead of
  recursively walking old page URLs. Detail and confirmation screens also
  provide a dedicated Back softkey.
- The voicemail HTTP endpoint now sends a valid past `Expires` header. On
  Cisco 79xx phones this prevents a request URL from being appended to the
  handset's internal Back/Exit history, independently of Cache-Control.
- A first visit to Phone Content after upgrading republishes the voicemail
  endpoint. No signing, VPN changes, or phone reset is needed.
- Call playback and Caller ID are unchanged. No arbitrary playback delays.

