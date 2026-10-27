#!/usr/bin/env python3
"""Exercise first-run SCCP VPN configuration using the real ocserv parser.

Does not need root or start services: creates a temp certificate/config then
passes it to 'ocserv --test-config'. Integration with systemd and FreePBX is
verified on the PBX itself after the package is installed.
"""
import importlib.machinery
import ipaddress
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import types

ROOT = Path(__file__).resolve().parents[1]
loader = importlib.machinery.SourceFileLoader(
    "sccp_ocserv_setup", str(ROOT / "scripts/sccp-manager-ocserv-setup"))
engine = types.ModuleType(loader.name)
loader.exec_module(engine)

options = {
    "gateway": "https://vpn.example.test:4443/svc",
    "pbx_ip": "192.168.0.25",
    "vpn_subnet": "10.254.77.0/24",
    "udp_port": 443,
    "max_same_clients": 0,
}
settings = engine.validate(options)
assert settings["hostname"] == "vpn.example.test"
assert settings["tcp"] == 4443
assert settings["subnet"] == ipaddress.IPv4Network("10.254.77.0/24")
output = engine.render(settings)
assert "cisco-client-compat = true" in output
assert "route = 192.168.0.25/255.255.255.255" in output
assert "max-same-clients = 0" in output
assert "udp-port = 443" in output

for changed, message in [
    ({"gateway": "http://vpn.example.test:4443/svc"}, "scheme"),
    ({"vpn_subnet": "192.168.0.0/24"}, "overlap"),
    ({"pbx_ip": "1.2.3.4"}, "public PBX"),
    ({"udp_port": 0}, "udp"),
    ({"max_same_clients": -1}, "sessions"),
    ({"gateway": "https://vpn.example.test:4443/not-svc"}, "service path"),
    ({"gateway": "https://vpn.example.test:4443/svc\nroute = 0.0.0.0/0"}, "config injection"),
]:
    candidate = dict(options, **changed)
    try:
        engine.validate(candidate)
    except (ValueError, TypeError):
        pass
    else:
        raise AssertionError("Accepted invalid settings: " + message)

with tempfile.TemporaryDirectory(prefix="sccp-ocserv-test-") as directory:
    folder = Path(directory)
    engine.ROOT = folder
    engine.CERT = folder / "server-cert.pem"
    engine.KEY = folder / "server-key.pem"
    assert engine.create_certificate(settings["hostname"]) is True
    assert engine.create_certificate(settings["hostname"]) is False
    before = engine.CERT.read_bytes()
    assert engine.create_certificate(settings["hostname"]) is False
    assert engine.CERT.read_bytes() == before
    assert os.stat(engine.KEY).st_mode & 0o777 == 0o600

    config = output.replace("/etc/ocserv/server-cert.pem", str(engine.CERT))
    config = config.replace("/etc/ocserv/server-key.pem", str(engine.KEY))
    config = config.replace("/etc/ocserv/ocpasswd", str(folder / "ocpasswd"))
    (folder / "ocpasswd").write_text("")
    configuration = folder / "ocserv.conf"
    configuration.write_text(config)
    binary = engine.binary()
    result = subprocess.run([binary, "-t", "-c", str(configuration)],
                            capture_output=True, text=True)
    if result.returncode != 0:
        raise AssertionError(
            "ocserv --test-config rejected generated configuration:\n"
            + result.stdout + "\n" + result.stderr)

print("First-run server bootstrap passed: validation, TLS key persistence, real ocserv config parser")
