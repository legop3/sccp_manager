# Install and test the integrated SCCP Manager VPN build

**This is a preview of a single FreePBX module, not a second product.**
The module package includes the GUI, Cisco XML templates/profile generator,
certutils source tools, privileged signing service integration, and tests.
No separately downloaded certutils checkout or helper-install script is needed.

## Existing PBX: one module package

First, take a FreePBX VM snapshot (preferred). The 17.1.4 preview targets an
existing functioning Nortien SCCP Manager 17.1.1 installation.
Back up existing signing keys and ocserv files if not included in your VM snapshot.

Download the preview module tarball from
[the fork's preview release](https://github.com/legop3/sccp_manager/releases/tag/vpn-preview-17.1.4).

Then upgrade the module as root on the PBX, which lets the module install
its own root-owned components in a single operation:

```bash
sudo fwconsole ma downloadinstall \
  https://github.com/legop3/sccp_manager/releases/download/vpn-preview-17.1.4/sccp_manager.tar.gz
sudo fwconsole reload
```

**Why root?** The FreePBX GUI normally lacks the privileges needed to install
a systemd service or write protected signing keys. The module's install.php
handles the privileged setup automatically when the upgrade actually runs
as root. This is a limitation of the FreePBX execution context, not a separate
helper product. If your fwconsole environment drops privileges during module
installation, the VPN page will say setup is pending; don't assume it worked.

On an existing SCCP Manager installation, the development install hook skips
the legacy SCCP database/realtime/driver rewrite. It leaves the chan_sccp
driver, old SCCP settings, configured phones, and existing ocserv.conf alone.
It installs packaged certutils into a protected internal system location,
preserves current Cisco signing keys and publishes a systemd unit for
the already-working ocserv server. If ocserv is running manually at upgrade
time, the installer will not interrupt it; the unit is enabled for next boot.

## In the FreePBX UI

Configure Server Config > **VPN Phones** once:
1. Enable Cisco built-in VPN provisioning.
2. Set the public VPN URI (for example `https://otter.land:4443/svc`).
3. Choose global VPN default **Off** and signed provisioning **Automatic**.
4. Save server defaults. Keep existing Cisco signing keys.

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

The VPN server continues using the existing ocserv config; a missing or
unconfigured ocserv server is still reported as a requirement, not silently
substituted with untested version/config. A separately forwarded TCP 4443
and UDP 443 were necessary on the reference setup.

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
