#!/usr/bin/env bash
# One-time root setup for SCCP Manager's optional VPN helper and service.
# Does not alter ocserv.conf or replace existing Cisco signing certificates.
set -euo pipefail

if [[ "$EUID" -ne 0 ]]; then
  echo "Run with sudo." >&2
  exit 1
fi
SCRIPT_DIR="$(cd -- "$(dirname -- "$0")" && pwd)"
INSTALL_DIR=/usr/local/libexec/sccp-manager-vpn
HELPER=/usr/local/libexec/sccp-manager-vpn-helper
CERT_DIR=/etc/cisco-signing
UNIT=/etc/systemd/system/sccp-ocserv.service

if [[ "$#" -gt 1 ]]; then
  echo "Usage: sudo bash $0 [path-to-certutils-checkout]" >&2
  exit 2
fi
if [[ "$#" -eq 1 ]]; then
  CERTUTILS_DIR="$(realpath "$1")"
else
  SOURCE_USER="$(id -un)"
  if [[ -n "$(printenv SUDO_USER || true)" ]]; then SOURCE_USER="$(printenv SUDO_USER)"; fi
  SOURCE_HOME="$(getent passwd "$SOURCE_USER" | cut -d: -f6)"
  CERTUTILS_DIR="$SOURCE_HOME/certutils"
fi
for name in sgnfile tlvfile mkcert; do
  [[ -f "$CERTUTILS_DIR/$name" ]] || {
    echo "Missing $CERTUTILS_DIR/$name; specify the existing certutils directory." >&2
    exit 1
  }
done
[[ -f /etc/ocserv/ocserv.conf ]] || {
  echo "No ocserv.conf; set up and test ocserv first." >&2
  exit 1
}
OCSERV_BIN=""
for choice in /usr/local/sbin/ocserv /usr/sbin/ocserv; do
  if [[ -x "$choice" ]]; then OCSERV_BIN="$choice"; break; fi
done
[[ -n "$OCSERV_BIN" ]] || { echo "Cannot find ocserv" >&2; exit 1; }
command -v python3 >/dev/null
command -v visudo >/dev/null
getent passwd asterisk >/dev/null || { echo "Asterisk service account missing" >&2; exit 1; }

install -d -m 0700 -o root -g root "$CERT_DIR"
if [[ ! -f "$CERT_DIR/sast.pem" || ! -f "$CERT_DIR/tftp.pem" ]]; then
  if compgen -G '/tftpboot/ITL*.tlv' >/dev/null; then
    echo "Existing phone trust lists but signing keys missing; restore original PEMs." >&2
    exit 1
  fi
fi
if [[ ! -f "$CERT_DIR/sast.pem" ]]; then
  bash "$CERTUTILS_DIR/mkcert" --common "Cisco-SAST" \
    --organization "Cisco" --unit "Provisioning" --years 20 \
    "$CERT_DIR/sast.pem"
fi
if [[ ! -f "$CERT_DIR/tftp.pem" ]]; then
  bash "$CERTUTILS_DIR/mkcert" --common "Cisco-TFTP" \
    --organization "Cisco" --unit "Provisioning" --years 20 \
    "$CERT_DIR/tftp.pem"
fi
chown root:root "$CERT_DIR/sast.pem" "$CERT_DIR/tftp.pem"
chmod 0600 "$CERT_DIR/sast.pem" "$CERT_DIR/tftp.pem"
install -d -m 0755 -o root -g root "$INSTALL_DIR"
for tool in sgnfile tlvfile; do
  install -m 0755 -o root -g root "$CERTUTILS_DIR/$tool" "$INSTALL_DIR/$tool"
done
install -m 0755 -o root -g root "$SCRIPT_DIR/sccp-manager-vpn-helper" "$HELPER"
"$HELPER" status

# Install only a fixed privileged executable, not a module-writable script.
SUDOERS=/etc/sudoers.d/sccp-manager-vpn
printf 'asterisk ALL=(root) NOPASSWD: %s\n' "$HELPER" > "$SUDOERS.tmp"
chown root:root "$SUDOERS.tmp"
chmod 0440 "$SUDOERS.tmp"
visudo -cf "$SUDOERS.tmp"
mv -f "$SUDOERS.tmp" "$SUDOERS"
chmod 0440 "$SUDOERS"

# Separate service name avoids rewriting package-managed ocserv.service.
if [[ ! -e "$UNIT" ]]; then
  cat > "$UNIT" <<EOF
[Unit]
Description=SCCP Manager Cisco phone VPN (ocserv)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=$OCSERV_BIN -f -c /etc/ocserv/ocserv.conf
Restart=on-failure
RestartSec=3s

[Install]
WantedBy=multi-user.target
EOF
  chown root:root "$UNIT"
  chmod 0644 "$UNIT"
fi
systemctl daemon-reload
systemctl enable sccp-ocserv.service

echo "Helper and service unit installed, existing VPN config untouched."
echo "Stop a manually running ocserv process before starting the managed service."
echo "Run: sudo systemctl start sccp-ocserv"
echo "Verify: sudo -u asterisk sudo -n $HELPER hash"
