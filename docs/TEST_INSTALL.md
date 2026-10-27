# Reversible first test on an existing FreePBX 17 server

These instructions deliberately **avoid `fwconsole ma install/upgrade`** for
this initial test. Nortien's module installer rewrites SCCP database and
provisioning configuration during a full module installation, and we want
to preserve an already-working phone. Start with one Cisco 7945G.

## 1. Back up the existing files

On the PBX:

```bash
BACKUP=/root/sccp-manager-before-vpn
sudo mkdir -p "$BACKUP"
sudo cp -a /var/www/html/admin/modules/sccp_manager "$BACKUP/module"
sudo cp -a /etc/ocserv "$BACKUP/ocserv"
sudo cp -a /etc/cisco-signing "$BACKUP/cisco-signing"
sudo cp -a /tftpboot/SEP00EBD5CA5557.cnf.xml* "$BACKUP/"
sudo cp -a /tftpboot/ITLSEP00EBD5CA5557.tlv "$BACKUP/"
```

Use the actual phone ID if different; copying optional missing files will
produce an error but does not invalidate prior successful backups.

Do **not** remove or regenerate existing signing keys; the phone has installed
their certificates in its trust list.

## 2. Fetch the development branch and overlay the module

```bash
cd /tmp
git clone --branch feature/vpn-provisioning --single-branch \
  https://github.com/legop3/sccp_manager.git sccp_manager-vpn
sudo rsync -a --exclude=.git /tmp/sccp_manager-vpn/ \
  /var/www/html/admin/modules/sccp_manager/
sudo fwconsole chown
sudo fwconsole reload
```

Do not overwrite `/tftpboot/templates` or the ocserv server configuration.
The branch includes a new VPN Phones tab in SCCP Connectivity > Server Config
even if the new standalone menu entry does not appear without reinstalling.

## 3. Install the restricted signer and service unit

The first setup must run once as root; subsequent signing and VPN management
are available through FreePBX. Use the existing certutils checkout.

```bash
cd /var/www/html/admin/modules/sccp_manager
sudo bash scripts/install-vpn-helper.sh /home/sangoma/certutils
sudo -u asterisk sudo -n /usr/local/libexec/sccp-manager-vpn-helper status
sudo -u asterisk sudo -n /usr/local/libexec/sccp-manager-vpn-helper hash
```

If your username or certutils checkout differs, use its real path in the
installer command. The install step does not stop ocserv or replace your
working VPN settings. It creates/enables a **separate** `sccp-ocserv` unit.

If you still have ocserv in a foreground terminal, stop it using Ctrl+C
before starting the managed service. In VPN Phones, click **Start managed
ocserv service** or run `sudo systemctl start sccp-ocserv`.

## 4. Configure and test only one phone

Open FreePBX > SCCP Connectivity > Server Config > **VPN Phones**.

1. Enter your existing concentrator URL, such as
   `https://vpn.example.net:4443/svc`.
2. Add only the ID of your test phone, such as `SEP00EBD5CA5557`.
3. Check **Enable managed VPN provisioning** and save.
4. Click **Regenerate and sign selected phones**.
5. Verify the files below have fresh timestamps:
   `SEP00EBD5CA5557.cnf.xml`,
   `SEP00EBD5CA5557.cnf.xml.sgn`,
   `ITLSEP00EBD5CA5557.tlv`.
6. Leave the existing VPN username/password alone if they already work.
   Otherwise use the **VPN login** form; it does not retain the password.
7. Test the phone off-LAN. `sccp show sessions` should show its VPN pool IP.
   Make a call and verify audio in both directions.

The module uses the *existing* ocserv config. Cisco SVC must be enabled and
both TCP and UDP forwarded; the working test required TCP 4443 and UDP 443.

## 5. Rollback

To restore the old module code without touching the VPN service or trust keys:

```bash
sudo rsync -a --delete /root/sccp-manager-before-vpn/module/ \
  /var/www/html/admin/modules/sccp_manager/
sudo fwconsole chown
sudo fwconsole reload
```

**Warning:** if the test phone has installed an ITL, its signed provisioning
must continue. Rolling back the module does not remove its ITL. Do not
regenerate its configuration in unsigned-only mode; use the old manual
`certutils` signing workflow or restore your known-good signed files.

Do not delete the root helper, signing keys or ocserv systemd service during
the first rollback; leaving them in place does not affect normal calls.

## Limitations

This is a module-level integration test, not a test of the full upstream
installer or a fresh FreePBX deployment. The ocserv binary and its
`/etc/ocserv/ocserv.conf` must already be functional. GitHub Actions syntax
checks and mock signing tests passing does not prove every FreePBX UI
interaction works. Test with one phone before making the feature general.
