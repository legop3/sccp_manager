# Cisco SCCP built-in VPN provisioning (development)

This is an experimental feature branch, not yet a production-ready ocserv
installer. Keep copies of your working signed configuration and private keys.

## Verified reference deployment

A Cisco CP-7945G with SCCP45.9-4-2SR4-3S, Nortien's SCCP Manager,
ocserv 1.3.0 in Cisco compatibility mode and UseCallManager.nz certutils
successfully registered and made two-way-audio calls from an external network
using the phone's own VPN client.

The tested router forwarded TCP 4443 and UDP 443 to the VPN server. Both
ports mattered: before forwarding UDP 443 the Cisco authenticated and received
an assigned address but immediately disconnected.

## Implemented on this branch

- A VPN Phones page under SCCP Connectivity stores settings in FreePBX BMO.
- Settings select explicit phone IDs, the HTTPS VPN concentrator URI, ocserv
  server certificate, signing key paths and signing executables.
- Selected phones receive a generated VPN group in their regular SCCP XML
  with the Cisco certificate hash calculated from the server PEM certificate.
- Unselected phones do not inherit a model template's VPN group once
  managed VPN provisioning is enabled.
- The existing XML generation path invokes tlvfile for missing per-phone
  trust lists and sgnfile to refresh each signed configuration after saving.
- Phones with an existing ITL continue being signed if removed from the
  allowlist while VPN provisioning remains globally enabled.
- With the feature disabled or unconfigured, normal provisioning is untouched.

## Prerequisites

These are NOT yet installed by the module:

1. A working ocserv instance, Cisco-compatible, reachable from the phone.
2. Cisco SCCP VPN-capable firmware and a configured TFTP service.
3. certutils from https://github.com/usecallmanagernz/certutils
4. Existing SAST and TFTP signing certificate/key PEMs and the VPN server
   public certificate PEM.
5. Execute permission for certutils and access to required PEM files by the
   FreePBX process. Root-only signing keys will currently cause a clear error.
   Do not make private signing keys world-readable or store them in TFTP.
   A privileged signer helper is planned.

Do not rotate or regenerate certificates behind a phone's existing ITL
without a planned trust migration.

## Using VPN Phones

Open SCCP Connectivity > VPN Phones in FreePBX and provide:

| Setting | Example |
|---|---|
| Enabled | Checked |
| Phone IDs | SEP001122334455, one per line |
| VPN gateway URI | https://vpn.example.net:4443/svc |
| MTU | 1290 |
| VPN server certificate | /etc/ocserv/server-cert.pem |
| TFTP certificate/key PEM | /etc/cisco-signing/tftp.pem |
| SAST certificate/key PEM | /etc/cisco-signing/sast.pem |
| sgnfile executable | /opt/certutils/sgnfile |
| tlvfile executable | /opt/certutils/tlvfile |

Then save/regenerate the phone through SCCP Manager's Phones Manager. The
module generates the regular XML, its signed copy and (if missing) a
per-phone ITL. No VPN username or password is stored by this feature.

Initial trust-list provisioning happens while the phone can access its
TFTP server, before moving it to a friend's network. The signed XML
and ITL must be readable by TFTP. Test remote SCCP registration with
the VPN-assigned address, not the phone's LAN address.

If no FreePBX KV settings have been saved, administrators can use an optional
JSON fallback at /etc/sccp-manager/vpn-provisioning.json:

~~~json
{
  "enabled": true,
  "devices": ["SEP001122334455"],
  "gateway": "https://vpn.example.net:4443/svc",
  "mtu": 1290,
  "server_certificate": "/etc/ocserv/server-cert.pem",
  "tftp_certificate": "/etc/cisco-signing/tftp.pem",
  "sast_certificate": "/etc/cisco-signing/sast.pem",
  "sgnfile": "/opt/certutils/sgnfile",
  "tlvfile": "/opt/certutils/tlvfile"
}
~~~

## Not implemented yet

- No ocserv installation, service startup or VPN account management via UI.
- No isolated privileged signer or automatic certutils installation.
- No check that an existing ITL matches the selected signing key.
- No automated recovery or trust migration after key rotation.
- No atomic signed-file publishing yet.
- No proof yet that the module package installs cleanly on a fresh FreePBX
  system or automatically recovers TFTP for a rebooted remote phone.

## Testing

Run PHP tests/vpn-provisioning.php for mock signing and profile validation.
The feature branch has an automated PHP 8.2 syntax and regression workflow.
Real PBX/phone acceptance testing is still required.

This is a development branch in the fork. Nothing has been submitted upstream.
