# Integrated Cisco SCCP VPN and signed provisioning

This feature is part of the SCCP Manager FreePBX module. It does not require
a second companion module or a separate tool checkout.

## Server settings

Find **SCCP Connectivity > Server Config > VPN & Signing**. Configure:

- Whether the Cisco built-in VPN provisioning is available.
- VPN hostname (for example `vpn.example.net`). HTTPS `/svc` is generated automatically.
- Global default for new phones: VPN on/off (default off).
- Signed configuration default: automatic, always, or disabled.
- Automatic VPN connection when away from local TFTP (default on).
- Default tunnel MTU (1290 in the tested 7945G deployment).
- View the existing ocserv status and manage its shared username/password.

**Automatic signing** signs phones with built-in VPN enabled and phones that
already use an installed Cisco ITL. You may separately enable signing for a
phone without enabling VPN. Conversely, VPN requires signed provisioning;
an invalid VPN-without-signing combination is rejected.

## Per-phone settings

In **SCCP Connectivity > Phones Manager**, open a Cisco SCCP device.
Within **VPN & Signed Provisioning**, select:

- Built-in VPN: inherit server default, enabled or disabled.
- Signed configurations: inherit, enabled or disabled.
- Automatic VPN connection: inherit, enabled or disabled.
- Optional VPN gateway override for this one phone.
- Optional VPN MTU override for this one phone.

**Save** works as for any other SCCP device. SCCP Manager stores these choices
with the module's FreePBX settings, generates the phone XML, adds/removes its
managed VPN group, and signs and publishes the configuration automatically
when required. It doesn't need a separate MAC allowlist or a manual signing
button after each phone change.

A global regeneration action remains available after server-level changes.

## Ownership and trust

Certificate-signing utilities are included in the module package and run via
the root-owned module helper. Signing PEMs live outside the TFTP directory.
An already-issued ITL continues using its original signing certificate:
key changes aren't treated as harmless automatic rotations.

Resetting a phone is a separate operation from resetting its provisioning
trust. Do not delete trusted certificates or issue new keys simply to start
the phone setup over again.

There is one VPN & Signing tab under Server Config; advanced network settings are collapsed and the PBX IPv4 address is detected unless overridden. The module installs ocserv automatically when it is missing, then configures
its own isolated server on the first VPN settings Save. A TLS certificate is
generated automatically if one does not exist; existing certificates and
signing keys are never rotated silently. The reference deployment used
TCP 4443 and UDP 443, ocserv Cisco SVC compatibility, and the phone's
built-in Cisco VPN client. SCCP Manager saves TCP and UDP custom services in
FreePBX Firewall and assigns the phone VPN pool to its internal zone when
that module is installed. Saving VPN settings NEVER starts or restarts FreePBX
Firewall: an earlier version unexpectedly restarted a deliberately stopped
firewall and locked an administrator out. Firewall policy changes are applied
separately by the administrator when it is safe to do so. This does not
configure other host firewalls. The router must still forward the chosen
TCP/UDP ports to FreePBX.

## Development and testing

The development build is 17.1.12. Start with one 7945G and use the
[test installation guide](TEST_INSTALL.md). The automated CI covers PHP
syntax, VPN XML generation, per-phone defaults/overrides, signing and ITL
handling, and real certutils signing. It has not yet been validated against
all supported Cisco firmware and phone models.

No pull request has been opened upstream.
