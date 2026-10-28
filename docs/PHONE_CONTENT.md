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


## 17.1.30 SCCP voicemail caller display

Earlier playback used AMI application-mode Originate with `CallerID`. That sets Asterisk's channel CallerID, but does not fill chan_sccp's separate `CallingParty` fields when the channel has no source/requestor. The 7945G consequently displays `From Unknown Number`.

This revision uses AMI synchronous extension Originate with `PreDialGoSub` (supported by Asterisk 22.6). The pre-dial context sets `CHANNEL(CallingParty)` on the SCCP channel from the sanitized voicemail caller name and number, before chan_sccp sends the phone's call-info screen. The extension then calls `VoiceMailPlayMsg` directly on the SCCP channel. Playback is not delayed and still auto-answers muted.

The root module installer writes `/etc/asterisk/sccp-manager-voicemail.conf` and includes it once in `/etc/asterisk/extensions_custom.conf`. Run `fwconsole reload` after installation to make the contexts active. The contexts are not included in `from-internal` and do not expose the mailbox to callers.

To verify deployment:

```bash
sudo asterisk -rx 'dialplan show s@sccp-manager-voicemail-predial'
sudo asterisk -rx 'dialplan show s@sccp-manager-voicemail-play'
```

The end-to-end Cisco display behavior requires a real phone test; CI verifies the generated AMI and dialplan syntax paths, not the live SCCP display.

## 17.1.31 — Traditional voicemail from list softkey

The main visual voicemail list now has four softkey positions: **Select** (1), **VM Menu** (2), empty (3), and **Exit** (4). The recordings list stays unchanged. The shortcut does not appear on individual message details.

The VM Menu softkey requests an authenticated phone service response with a CiscoIPPhoneExecute containing `Init:Messages` to close the XML interface and `Dial:*9900<extension>` to start a **normal call from the handset**. The Cisco platform permits two Execute URIs but does not guarantee execution order; both operations are independent. The phone's normal call UI takes focus; it should not need to return to the XML app on hangup.

The dedicated `_*9900.` pattern in `from-internal-custom` identifies the mailbox explicitly from the dialed suffix and runs `Answer()` then `VoiceMailMain(<mailbox>@default)`. This bypasses the broken `*97` path and does **not** skip the voicemail PIN. The installer refreshes the managed include file; run `fwconsole reload` after upgrading. The special code may conflict with locally defined extensions and should be checked with `asterisk -rx 'dialplan show *9900150@from-internal'` for a phone with extension 150.

Open Phone Content once after upgrading so the public voicemail.php is republished. There is no signed SEP configuration change or phone reset required. Cisco 7945G call UI and audio behavior still require on-device testing.

## 17.1.32 — Favorites and All Extensions

The existing Directories -> Phone Book and Services -> Address Book entries now open
a two-level index:

1. **Favorites** — existing manually added address-book contacts and FreePBX
   extensions marked Favorite. The same list is shared by all managed phones.
2. **All Extensions** — the FreePBX Connectivity -> Extensions roster, including
   SCCP devices. Members marked Hidden are excluded from both sections.

FreePBX Core `getAllUsers()` is the single source of automatically discovered
extensions. No separate chan_sccp table is queried, so a FreePBX extension
cannot appear twice because it happens to use SCCP. Extension visibility can
be set for each extension in Phone Content -> Address Book:
All Extensions (default), Favorite + All Extensions, or Hidden.

New extension and name changes are picked up whenever the Phone Content page
is opened or the directory is explicitly published. The phones fetch the
generated XML at the same existing URLs. **No phone reboot, new signed SEP
configuration or VPN changes are required.** Cisco pages contain at most 30
entries with a separate range index for larger directories. Existing manual
contacts and existing configurable display text are preserved.


## 17.1.33 — Unified Address Book management

The FreePBX **Phone Content → Address Book** page is now one directory
manager rather than separate manual-contact and FreePBX extension tables.
It provides a single name/number search, All/Favorites/Hidden filters,
a source label per entry, and one Save Directory action. Custom contacts
can be added or edited in an inline panel and deleted from their table row.
FreePBX-managed extension names are read-only here and continue to be
managed in Connectivity → Extensions.

Existing manually created entries remain Favorites without migrating or
resetting their data. Both manual and imported entries support Hidden:
hidden custom contacts are retained in FreePBX's module settings rather
than deleted, and can later be restored to Favorites. Imported extensions
can be All Extensions (default), Favorites, or Hidden. Both sets of
visibility choices are validated and saved together; failed publishing
reverts both settings.

The phone-facing **Favorites → All Extensions** XML menus, their URLs,
paging, and voicemail are unchanged. Updating a directory entry
republishes XML for the next phone request, without resetting phones or
touching VPN or signed SEP provisioning.


## 17.1.34 — Exact Phone Book label editor

The Address Book's former generic "Phone display text" accordion has been
replaced by **Phone Book screen labels**, organized around the **actual Cisco
navigation path**:

- **Phone Book chooser**: `phonebook_title` at the top, `phonebook_prompt`
  under its two choices. No longer reuses the Directories button prompt.
- **Favorites**: `favorites_entry` is its choice *inside Phone Book*,
  `contacts_title` and `contacts_prompt` are on the contact list itself.
  The default list title is now "Favorites" rather than the leftover "Phone
  Book" title.
- **All Extensions**: `extensions_entry` is its choice inside Phone Book,
  `extensions_title` and `extensions_prompt` appear on its contact list.

A live preview lets the administrator switch among all three phone screens
while editing those eight fields. The independent root Directories and
Services button titles/prompts have moved to **Phone Content → Menus** under
"Directories and Services button screen text". The names of *items within*
those root menus are still managed by the menu editor above.

The previous labels are preserved in the module's saved configuration. For
existing installations, a customized old `menu_entry` is used as the Phone
Book screen title until explicitly changed; the old root menu prompt is
retained if it was customized. Scoped save forms update only their own
fields, so changing Favorites does not rewrite Directories/Services and vice
versa. The phone fetches fresh XML at existing URLs without a reboot.


## 17.1.35 — Directories button opens Favorites / All Extensions directly

The physical Cisco **Directories** key now has one canonical destination:
`/sccp-manager/phone.php?doc=directory`, which returns a two-option
`CiscoIPPhoneMenu`: **Favorites** and **All Extensions**. Selecting an option
opens its paginated contact list. There is no intermediate Phone Book menu.

The **Menus** tab no longer offers a separate editable Directories-button
menu. It still controls Services and Messages. The root Directories title,
prompt, and both option names are edited in **Address Book → Directories screen
labels** alongside the contact-list titles and prompts. The previous extra
Phone Book label fields and route have been removed rather than aliased.

**Phone provisioning happens automatically:** After upgrading, opening
**Phone Content** once signs the current SEP configurations through the module's
existing provisioning process, if custom button menus are enabled. **Save
Menus** already does the same for later changes. There is no extra publishing
button or manual action. The phone loads its new Directories-button URL on its
next restart; no forced phone reboot or VPN credential changes are performed.
Editing the directory content or labels thereafter updates the served XML
without signing phone configurations.


## 17.1.36 — Automatic provisioning (no extra button)

The redundant "Publish signed phone configurations" control and backend action
were removed. The module uses its existing normal save path to regenerate and
sign the SEP files. Existing deployments are updated once, automatically, on
the first Phone Content page visit after upgrading. Nothing resets remote phones
without operator action; the corrected button URL is loaded at the next phone
restart. The 7945G VPN configuration is left alone.

## 17.1.37 — Cisco XML Error [4] from long directory prompts

On a Cisco 7945G, a well-formed `CiscoIPPhoneDirectory` response can still fail
with **XML Error [4]: Parse Error** if the `<Title>` or `<Prompt>` exceeds
the phone's 32-character schema limit. The previous editor permitted 80-byte
prompts. A real 10-extension directory had all 10 valid records but failed
because the custom prompt was longer than 32 characters.

Generated directory Title and Prompt values are now capped at **32 UTF-8
bytes**, on both config reading and the XML output boundary. Saved longer
prompts are automatically shortened to a valid string without changing the
extensions or clearing saved settings. Future label edits enforce the limit
in both the UI and server validation. The regression fixture uses the actual
10-extension directory and exact failure-producing prompt. No signed SEP
update, VPN change, or device reset is required; visiting Phone Content
republishes the public directory XML.

## 17.1.38 — Directories opens Favorites immediately

There is **no Favorites/All Extensions selection screen**. The physical
Directories button now provisions `/sccp-manager/phone.php?doc=contacts-1`,
which returns the first Favorites `CiscoIPPhoneDirectory` directly.

Four softkeys on each directory list preserve the phone's native controls:
**Dial** (SoftKey:Dial), **EditDial** (SoftKey:EditDial), **All/Favs** (an HTTP
link to the other directory list), and **Exit** (SoftKey:Exit).
With more than 30 entries, the **Pages** softkey temporarily replaces
EditDial and opens the page selector. Pressing Directories never opens a
page selector. The optional page selector appears only when explicitly
requested.

The Address Book text editor now edits only the two actual directory
screens: Favorites and All Extensions titles/prompts. Unused chooser
title/prompt and option-label fields were removed. Existing extension
visibility and manual contacts are unchanged. The module still applies
the 32-byte Cisco Title/Prompt guards introduced in 17.1.37.

The updated Directories URL is signed automatically through existing
SCCP Manager provisioning on the next Phone Content visit after upgrading,
or when Save Menus is used; there is no separate manual publishing control.
Remote phones load signed SEP changes when they normally reprovision.
No VPN setup or separate directory backend is involved.
