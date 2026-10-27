# Integrated Cisco SCCP VPN provisioning — development build 17.1.2

This branch adds a **VPN Phones** tab to SCCP Manager > Server Config,
and a VPN Phones navigation item when the module's menu metadata is refreshed.
**No CUCM is needed.** It manages the phone VPN XML and signed TFTP
provisioning while leaving normal SCCP device and line management in SCCP Manager.

## Confirmed reference system

- FreePBX 17 with Nortien's SCCP Manager and chan_sccp.
- Cisco 7945G on SCCP45.9-4-2SR4-3S.
- ocserv 1.3.0 with `cisco-client-compat = true`, username/password.
- VPN endpoint on TCP 4443 **and UDP 443** forwarded to ocserv.
- External-network SCCP registration and two-way audio over a VPN-issued address.
- Phone trust-list and signed configuration made with usecallmanagernz/certutils.

## What is integrated

- Select phone IDs individually; non-VPN SCCP phones retain their usual configuration.
- Specify the HTTPS VPN gateway and MTU, then generate a Cisco `vpnGroup`
  (password prompt on the phone, forced VPN for testing).
- Recalculate the ocserv TLS certificate's SHA1-based Cisco fingerprint.
- Create/reuse per-phone ITLs and generate signed SEP configuration files
  whenever SCCP Manager generates XML for selected phones.
- Preserve the existing trusted signing keys across module and phone updates.
- Verify existing ITLs contain the expected TFTP certificate before signing.
- Publish signed files atomically after signing and verification.
- Configure a shared ocserv username/password in the UI without saving its plaintext.
- Start/monitor a systemd service for an existing ocserv installation.
- Run signing under a restricted root-owned helper, not with root keys accessible to PHP.
- Tests and PHP/Python/Bash syntax checks via GitHub Actions.

## What is intentionally *not* installed or modified

The module doesn't compile ocserv, replace chan_sccp, change firewall/router
rules, replace existing ocserv.conf, or rotate signing certificates.
On a fresh PBX, install and configure ocserv first. On an already-working
setup, the module can reuse the tested server configuration.

### First test on a currently working PBX

Do not execute a full Module Admin reinstall solely to get this feature:
the existing SCCP Manager installer can rewrite site settings and TFTP content.
Make a backup, overlay the branch files, use Server Config > VPN Phones,
and test one phone. See [TEST_INSTALL.md](TEST_INSTALL.md).

To install the root-only helper from the checked-out module on the PBX:

```sh
sudo bash scripts/install-vpn-helper.sh /home/YOURUSER/certutils
```

The installer preserves any existing `/etc/cisco-signing/sast.pem` and
`/etc/cisco-signing/tftp.pem` and refuses to generate replacements if ITLs
are present and keys are missing. It copies certutils into a fixed root-owned
path, creates a constrained sudoers entry for the `asterisk` service account,
and creates/enables `sccp-ocserv.service` **without starting it**.

Stop any manually running ocserv process, then use the VPN Phones tab's
**Start managed ocserv service** button. The service uses the existing
`/etc/ocserv/ocserv.conf` and whichever ocserv binary was installed.

### Phone settings

Check Enable managed VPN provisioning, enter your VPN concentrator such as
`https://vpn.example.net:4443/svc`, select `SEP001122334455`-style device
IDs, then save. Afterward click Regenerate and sign selected phones.
**Save** alone does not regenerate phones. Test with one phone first.

The generated configuration contains no VPN password. The phone prompts
for a password after it reconnects or reboots. Users can share a VPN login
when ocserv's `max-same-clients` permits it.

To create or change the shared login, use the VPN login form. This supports
the plain backend pointing to `/etc/ocserv/ocpasswd`; other account backends
must be administered separately. Unrelated existing VPN users are preserved.

Keep TFTP reachable on the provisioning LAN for the first signed-file/ITL
bootstrap. Once the phone has cached its VPN configuration, test from
another network and confirm `sccp show sessions` shows a VPN pool address.

## Trust and signing details

- Signing keys are kept in `/etc/cisco-signing` with root-only permissions.
- The static privileged helper lives in `/usr/local/libexec/sccp-manager-vpn-helper`.
- Only a valid SEP MAC can be signed; no caller-provided file paths are accepted.
- ITLs are not overwritten when already present; the helper verifies the
  trusted TFTP certificate against the configured signing certificate.
- Disabling managed provisioning when an ITL exists raises an error on
  regenerated phone configs rather than silently claiming success.
- Keep the original private keys and ITLs backed up for restoration.

## Remaining limitations

- The module deliberately does not auto-compile/version-pin ocserv.
- Remote reboot and remote TFTP recovery are firmware/network-dependent;
  first test from an external network before deploying many phones.
- A first-run FreePBX GUI install and full upgrade migration have not yet
  been tested against a live PBX; use the documented reversible overlay.
- Module UI integration and root helper need an end-to-end test on real
  FreePBX 17 + a 7945G. Passing CI is not a substitute for that test.
- A separate release package and upstream PR have not been made.

## Developer tests

GitHub Actions tests PHP lint for changed module code, Python syntax for
the privileged helper, Bash syntax for the installer and mock-driven
VPN XML/profile signing tests. The real server test is the final gate.
