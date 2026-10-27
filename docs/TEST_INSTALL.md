# Install and test the integrated SCCP Manager VPN build

**This is a preview of a single FreePBX module, not a second product.**
The module package includes the GUI, Cisco XML templates/profile generator,
certutils source tools, privileged signing service integration, and tests.
No separately downloaded certutils checkout or helper-install script is needed.

## Existing PBX: one module package

First, take a FreePBX VM snapshot (preferred). The 17.1.5 preview targets an
existing functioning Nortien SCCP Manager 17.1.1 installation.
Back up existing signing keys and ocserv files if not included in your VM snapshot.

Download the preview module tarball from
[the fork's preview release](https://github.com/legop3/sccp_manager/releases/tag/vpn-preview-17.1.5).

Then upgrade the module as root on the PBX, which lets the module install
its own root-owned components in a single operation:

```bash
sudo fwconsole ma downloadinstall \
  https://github.com/legop3/sccp_manager/releases/download/vpn-preview-17.1.5/sccp_manager.tar.gz
sudo fwconsole reload
```

**Why root?** The FreePBX GUI normally lacks the privileges needed to install
a systemd service or write protected signing keys. The module's install.php
handles the privileged setup automatically when the upgrade actually runs
as root. This is a limitation of the FreePBX execution context, not a separate
helper product. If your fwconsole environment drops privileges during module
installation, the VPN page will say setup is pending; don't assume it worked.

The installer automatically installs ocserv when missing and bundles the
signing utilities. It creates an isolated SCCP Manager service, initially
unconfigured, and does not overwrite the distribution's ocserv configuration.
Configure and start it from Server Config > VPN & Signing.

On an existing SCCP Manager installation, the development install hook skips
the legacy SCCP database/realtime/driver rewrite. It leaves the chan_sccp
driver, old SCCP settings, configured phones, and existing ocserv.conf alone.
The module installs packaged certutils into a protected system location,
preserves current signing keys, and adds its own systemd service. It does not
stop an existing external VPN. If another ocserv instance is running, the VPN
settings page reports a port conflict rather than forcibly stopping it.

## In the FreePBX UI

Configure Server Config > **VPN Phones** once:
1. Enable Cisco built-in VPN provisioning.
2. Set the public VPN URI (for example `https://otter.land:4443/svc`).
3. Enter the PBX **internal LAN IP**, VPN address pool and UDP port (default 443).
4. Choose global VPN default **Off** and signed provisioning **Automatic**.
5. Click **Save settings and configure VPN server**. The module creates the
   ocserv config, self-signed server TLS certificate (first time only), and
   starts the managed service. Signing keys are preserved.
6. Create the VPN login in the same page, or use an existing configured login.

Then configure the **actual phone** in Phones Manager:
1. Create or edit your Cisco SCCP device.
2. In **VPN & Signed Provisioning**, set VPN **Enabled** and Signing **Enabled**
   (or inherit the automatic signing default).
3. Leave gateway and MTU overrides blank unless this phone needs different settings.
4. Save the phone normally. SCCP Manager generates its VPN XML, signs it and
   publishes the trust list automatically.

For the first clean phone test, use its reachable local TFTP service to
download the signed provisioning and trust files. Once configured, test on
a different internet connection and confirm SCCP registers using the VPN
pool address and has two-way audio. A factory reset of the phone is separate
from server-side trust-list changes; **do not delete or rotate your signing
certificates to reset a phone**.

The VPN username/password is only entered in the account form if you want to
change it. The working account can remain untouched.

The module now installs ocserv if missing and configures the VPN server itself.
The public router still needs to forward the gateway TCP port and selected UDP
port to this PBX (TCP 4443 and UDP 443 on the tested setup). Any host/FreePBX
firewall must allow those incoming ports; exposing the ports in the FreePBX
firewall remains separate from the module's current first-run setup.

## Checks

The source branch includes `tests/vpn-provisioning.php`, a real
certutils integration test, syntax checks, and a release package workflow.
Passing CI cannot verify every FreePBX and Cisco firmware interaction, so
start with a single phone.

## Rollback

Restore the VM snapshot if needed. If restoring only module code, restore
the old module directory and run `sudo fwconsole reload`.
Preserve the existing private signing keys and signed TFTP files:
once a phone has accepted an ITL, reverting to unsigned provisioning can
prevent that phone from accepting configuration updates.
Rollback should not erase or rotate the trust chain.

Nothing has been submitted to Nortien's upstream repo.
