#!/usr/bin/env bash
# Internal SCCP Manager VPN deployment hook.
# Invoked by the root module installer. NOT a separately installed product.
# Re-running is safe: reuses the existing ocserv settings and Cisco trust keys.
set -euo pipefail

if [[ "$EUID" -ne 0 ]]; then
  echo "SCCP Manager VPN setup needs root permissions" >&2
  exit 1
fi

MODULE_DIR="$(cd -- "$(dirname -- "$0")/.." && pwd)"
VENDOR="$MODULE_DIR/vendor/certutils"
BIN_DIR=/usr/local/libexec/sccp-manager-vpn
HELPER=/usr/local/libexec/sccp-manager-vpn-helper
KEYS=/etc/cisco-signing
UNIT=/etc/systemd/system/sccp-ocserv.service
SUDOERS=/etc/sudoers.d/sccp-manager-vpn

for f in sgnfile tlvfile mkcert; do
  [[ -f "$VENDOR/$f" ]] || { echo "Bundled certutils missing: $f" >&2; exit 1; }
done
command -v visudo >/dev/null || { echo "sudo/visudo is required" >&2; exit 1; }
command -v python3 >/dev/null || { echo "Python 3 is required" >&2; exit 1; }
getent passwd asterisk >/dev/null || { echo "Missing asterisk service account" >&2; exit 1; }

# Install the missing system dependencies as part of the module install.
# The package-manager installation is idempotent and needs no extra script.
OCSERV=""
for bin in /usr/local/sbin/ocserv /usr/sbin/ocserv; do
  if [[ -x "$bin" ]]; then OCSERV="$bin"; break; fi
done
if [[ -z "$OCSERV" ]] || ! python3 -c "import cryptography" >/dev/null 2>&1; then
  if command -v apt-get >/dev/null 2>&1; then
    needed=(openssl)
    [[ -n "$OCSERV" ]] || needed+=(ocserv)
    if ! python3 -c "import cryptography" >/dev/null 2>&1; then needed+=(python3-cryptography); fi
    DEBIAN_FRONTEND=noninteractive apt-get install -y "${needed[@]}"
  else
    echo "SCCP VPN automatic dependency installation currently supports Debian/apt" >&2
    exit 1
  fi
fi
OCSERV=""
for bin in /usr/local/sbin/ocserv /usr/sbin/ocserv; do
  if [[ -x "$bin" ]]; then OCSERV="$bin"; break; fi
done
[[ -n "$OCSERV" ]] || { echo "ocserv did not install" >&2; exit 1; }

install -d -m 0700 -o root -g root "$KEYS"
if [[ ! -f "$KEYS/sast.pem" || ! -f "$KEYS/tftp.pem" ]]; then
  if compgen -G '/tftpboot/ITL*.tlv' >/dev/null; then
    echo "Existing Cisco ITL with missing signing keys; restore the old keys" >&2
    exit 1
  fi
fi
if [[ ! -f "$KEYS/sast.pem" ]]; then
  bash "$VENDOR/mkcert" --common Cisco-SAST \
    --organization Cisco --unit Provisioning --years 20 "$KEYS/sast.pem"
fi
if [[ ! -f "$KEYS/tftp.pem" ]]; then
  bash "$VENDOR/mkcert" --common Cisco-TFTP \
    --organization Cisco --unit Provisioning --years 20 "$KEYS/tftp.pem"
fi
chown root:root "$KEYS/sast.pem" "$KEYS/tftp.pem"
chmod 0600 "$KEYS/sast.pem" "$KEYS/tftp.pem"

install -d -m 0755 -o root -g root "$BIN_DIR"
for f in sgnfile tlvfile; do
  install -m 0755 -o root -g root "$VENDOR/$f" "$BIN_DIR/$f"
done
install -m 0755 -o root -g root "$MODULE_DIR/scripts/sccp-manager-vpn-helper" "$HELPER"
install -m 0755 -o root -g root "$MODULE_DIR/scripts/sccp-manager-ocserv-setup" /usr/local/libexec/sccp-manager-ocserv-setup

# Only this immutable system copy may be invoked as root by FreePBX.
temp_rule="$(mktemp /etc/sudoers.d/.sccp-vpn-XXXXXX)"
trap 'rm -f "$temp_rule"' EXIT
printf 'asterisk ALL=(root) NOPASSWD: %s\n' "$HELPER" > "$temp_rule"
chown root:root "$temp_rule"
chmod 0440 "$temp_rule"
visudo -cf "$temp_rule" >/dev/null
mv -f "$temp_rule" "$SUDOERS"
chmod 0440 "$SUDOERS"
trap - EXIT

# SCCP Manager owns its own ocserv configuration, not the distribution's
# /etc/ocserv/ocserv.conf. This avoids accidentally overriding other VPNs.
# Service starts only when configured in the FreePBX interface.
if [[ ! -e "$UNIT" ]]; then
  cat > "$UNIT" <<EOF
[Unit]
Description=SCCP Manager Cisco phone VPN (ocserv)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=$OCSERV -f -c /etc/ocserv/sccp-manager.conf
Restart=on-failure
RestartSec=3
NoNewPrivileges=false

[Install]
WantedBy=multi-user.target
EOF
  chown root:root "$UNIT"
  chmod 0644 "$UNIT"
fi
systemctl daemon-reload

echo "SCCP Manager VPN dependencies, isolated service and signing tools installed."
echo "Open FreePBX > SCCP Manager > VPN & Signing to configure and start VPN."
