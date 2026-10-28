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

# Ensure the bundled Python signer can operate, then obtain an ocserv build
# with the Cisco /svc endpoint. Debian 12's stock 1.1.6 is NOT compatible.
if ! python3 -c "import cryptography" >/dev/null 2>&1; then
  command -v apt-get >/dev/null || { echo "Python cryptography dependency missing" >&2; exit 1; }
  DEBIAN_FRONTEND=noninteractive apt-get install -y python3-cryptography openssl
fi
OCSERV_WAS_MISSING=0
if [[ ! -x /usr/local/sbin/ocserv && ! -x /usr/sbin/ocserv ]]; then
  OCSERV_WAS_MISSING=1
fi
OCSERV="$(bash "$MODULE_DIR/scripts/ensure-cisco-ocserv.sh")"
[[ -x "$OCSERV" ]] || { echo "No Cisco-compatible ocserv executable available" >&2; exit 1; }

# Do not touch preexisting VPNs. Only disable a generic ocserv.service if
# this installer was the first to install the server executable.
if [[ "$OCSERV_WAS_MISSING" -eq 1 ]]; then
  systemctl disable --now ocserv.service 2>/dev/null || true
fi

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
# An older SCCP Manager service may still point to /usr/sbin/ocserv (Debian
# 12's 1.1.6), even when the new binary is present under /usr/local/sbin.
# Only change our own unit, and restart ONLY the managed VPN if it was running.
unit_updated=0
vpn_was_running=0
if systemctl is-active --quiet sccp-ocserv.service; then vpn_was_running=1; fi
if grep -Fqx 'Description=SCCP Manager Cisco phone VPN (ocserv)' "$UNIT" ||
   grep -Fqx 'Description=SCCP Manager Cisco built-in phone VPN' "$UNIT"; then
  :
elif grep -Eq '^Description=SCCP Manager Cisco .*VPN' "$UNIT"; then
  :
else
  echo "Existing sccp-ocserv unit is not owned by SCCP Manager; leaving it unchanged" >&2
  exit 1
fi
if ! grep -Fqx "ExecStart=$OCSERV -f -c /etc/ocserv/sccp-manager.conf" "$UNIT"; then
  grep -q '^ExecStart=' "$UNIT" || { echo "Managed VPN unit has no ExecStart" >&2; exit 1; }
  tmpunit="$(mktemp /etc/systemd/system/.sccp-ocserv.XXXXXXXX)"
  sed "s|^ExecStart=.*|ExecStart=$OCSERV -f -c /etc/ocserv/sccp-manager.conf|" "$UNIT" > "$tmpunit"
  install -o root -g root -m 0644 "$tmpunit" "$UNIT"
  rm -f "$tmpunit"
  unit_updated=1
fi
systemctl daemon-reload
if [[ "$unit_updated" -eq 1 && "$vpn_was_running" -eq 1 ]]; then
  "$OCSERV" -t -c /etc/ocserv/sccp-manager.conf
  systemctl restart sccp-ocserv.service
fi

echo "SCCP Manager VPN dependencies, Cisco-compatible ocserv, service and signing tools installed."
echo "Open FreePBX > SCCP Manager > VPN & Signing to configure and start VPN."
