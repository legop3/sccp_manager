# Native SCCP softkey layouts (development 17.1.62)

Phone Content → Home Screen & Softkeys edits a managed chan_sccp `softkeyset` named `sccpmanager-home`.
This is **not** a SIP firmware `softKeyFile`. Saving persists the layout in the FreePBX module's config.
Apply Config regenerates `/etc/asterisk/sccp.conf` with a managed `[sccpmanager-home]` section when enabled.

## Activate on an individual phone
1. Open Phone Content → Home Screen & Softkeys, enable the layout and Save.
2. Click FreePBX **Apply Config**.
3. In SCCP Phones Manager, edit the desired Cisco device, set **Soft Keyset** to `sccpmanager-home`, and Save/Apply.
4. If the set is not listed yet, check `/etc/asterisk/sccp.conf` for `[sccpmanager-home]` and reload chan_sccp through the usual FreePBX Apply Config process. On some phones a reconnect or reset is required.

The other SCCP phones retain their existing softkey set until explicitly assigned.
Disabled configuration does not delete or alter the selected softkeyset of a device; switch the device back to its default first when disabling.

Each state accepts ordered, comma-separated canonical chan_sccp key identifiers. Validation prevents unknown keys, duplicate buttons and excessively long lists. The editor cannot give a native button an arbitrary label or map it directly to a Cisco XML application. The firmware retains ownership of the builtin idle prompt ("Your Current Options" / "You Have VoiceMail"). Existing Phone Content notifications are separate and left intact.

## Verify
- `php -l sccpManClasses/homeScreenConfig.class.php`
- `php -l views/content.home.php`
- `php -l sccpManTraits/helperFunctions.php`
- `php -l sccpManClasses/phonecontent.class.php`
- `sudo grep -A15 '^\\[sccpmanager-home\\]' /etc/asterisk/sccp.conf`

Do not remove critical call-state keys (for example `answer` and `endcall`) while testing on a phone that must remain usable.
