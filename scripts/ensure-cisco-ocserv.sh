#!/usr/bin/env bash
# Install a Cisco SVC-capable ocserv for SCCP Manager on Debian 12.
# Does not touch accounts, certificates, config files or running services.
set -euo pipefail
[[ "${EUID}" -eq 0 ]] || { echo "Root required to install ocserv" >&2; exit 1; }

MINIMUM=1.2.0
SOURCE_VERSION=1.3.0
SOURCE_URL="https://www.infradead.org/ocserv/download/ocserv-${SOURCE_VERSION}.tar.xz"
SOURCE_SHA256="5c562c3ea1032065ef43d6075e5722f94ce6a7a780d6a2b4c6d54c1ce3921965"

version_of() {
  local binary="$1" version
  [[ -x "$binary" ]] || return 1
  version="$("$binary" --version 2>&1 | grep -m1 -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)"
  [[ -n "$version" ]] || return 1
  printf '%s\n' "$version"
}

select_supported_binary() {
  local binary version
  for binary in /usr/local/sbin/ocserv /usr/sbin/ocserv; do
    version="$(version_of "$binary" || true)"
    if [[ -n "$version" ]] && dpkg --compare-versions "$version" ge "$MINIMUM"; then
      printf '%s\n' "$binary"
      return 0
    fi
  done
  return 1
}

if OCSERV="$(select_supported_binary)"; then
  echo "SCCP Manager: using $OCSERV (version $(version_of "$OCSERV"))" >&2
  printf '%s\n' "$OCSERV"
  exit 0
fi

command -v apt-get >/dev/null || {
  echo "No compatible ocserv found; automatic source build requires Debian/apt" >&2
  exit 1
}

echo "SCCP Manager: installed ocserv is too old for Cisco phones; building ${SOURCE_VERSION}..." >&2
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y --no-install-recommends \
  ca-certificates curl xz-utils build-essential pkg-config \
  libgnutls28-dev libev-dev libreadline-dev libnl-route-3-dev \
  libseccomp-dev libpam0g-dev liblz4-dev libcrypt-dev \
  libprotobuf-c-dev protobuf-c-compiler

workdir="$(mktemp -d /tmp/sccp-ocserv-build.XXXXXXXX)"
trap 'rm -rf -- "$workdir"' EXIT
archive="$workdir/ocserv-${SOURCE_VERSION}.tar.xz"
curl --fail --location --show-error --silent --retry 2 \
  --connect-timeout 15 --max-time 90 "$SOURCE_URL" --output "$archive"
echo "${SOURCE_SHA256}  ${archive}" | sha256sum --check --status || {
  echo "ocserv source archive failed SHA-256 verification" >&2
  exit 1
}
tar -xJf "$archive" -C "$workdir"

(
  cd "$workdir/ocserv-${SOURCE_VERSION}"
  ./configure --prefix=/usr/local --sysconfdir=/usr/local/etc
  make -j2
  make install
)
if command -v ldconfig >/dev/null; then ldconfig; fi

OCSERV="$(select_supported_binary)" || {
  echo "Built ocserv does not meet Cisco SVC minimum version ${MINIMUM}" >&2
  exit 1
}
echo "SCCP Manager: installed $OCSERV (version $(version_of "$OCSERV"))" >&2
printf '%s\n' "$OCSERV"
