#!/usr/bin/env python3
"""Integration test for the real root-helper signing flow using temporary files."""
import importlib.machinery
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import types

ROOT = Path(__file__).resolve().parents[1]
CERTUTILS = Path(sys.argv[1]).resolve()
loader = importlib.machinery.SourceFileLoader("vpn_helper", str(ROOT / "scripts/sccp-manager-vpn-helper"))
module = types.ModuleType(loader.name)
loader.exec_module(module)

with tempfile.TemporaryDirectory(prefix="sccp-vpn-integration-") as tmp:
    root = Path(tmp)
    module.TFTP = root / "tftp"
    module.KEY_DIR = root / "keys"
    module.BIN_DIR = root / "bins"
    module.VPN_CERT = root / "server.pem"
    for folder in (module.TFTP, module.KEY_DIR, module.BIN_DIR):
        folder.mkdir(mode=0o700)

    for tool in ("sgnfile", "tlvfile"):
        path = module.BIN_DIR / tool
        shutil.copyfile(CERTUTILS / tool, path)
        path.chmod(0o755)

    def make_cert(path, name):
        subprocess.run(["bash", str(CERTUTILS / "mkcert"),
                        "--common", name, "--organization", "Test",
                        "--unit", "Provisioning", "--years", "1", str(path)],
                       check=True, capture_output=True, text=True)

    make_cert(module.VPN_CERT, "VPN Server")
    make_cert(module.KEY_DIR / "sast.pem", "Cisco-SAST")
    make_cert(module.KEY_DIR / "tftp.pem", "Cisco-TFTP")
    device = "SEP00EBD5CA5557"
    xml = module.TFTP / (device + ".cnf.xml")
    xml.write_text("<device><versionStamp>123</versionStamp></device>")

    module.sign(device)
    signed = module.TFTP / (device + ".cnf.xml.sgn")
    trust = module.TFTP / ("ITL" + device + ".tlv")
    assert signed.is_file() and signed.stat().st_size > 0
    assert trust.is_file() and trust.stat().st_size > 0
    old_trust = trust.read_bytes()
    module.sign(device)
    assert trust.read_bytes() == old_trust, "A preexisting ITL was changed"

    # Real FreePBX failure: generator writes settings/SEP...xml, while the
    # phone fetches root/SEP...xml. The signer must publish the NEW XML,
    # not silently sign the obsolete root copy.
    settings = module.TFTP / "settings"
    settings.mkdir()
    new_xml = "<device><versionStamp>new-from-settings</versionStamp></device>"
    (settings / xml.name).write_text(new_xml)
    xml.write_text("<device><versionStamp>stale-root</versionStamp></device>")
    old_signed = signed.read_bytes()
    module.sign(device, "settings")
    assert xml.read_text() == new_xml, "The TFTP root still has stale XML"
    assert signed.read_bytes() != old_signed, "Signed file was not regenerated"
    assert trust.read_bytes() == old_trust, "Publishing new XML changed existing ITL"
    for bad_source in ("../etc", "/tmp", "settings/nested", ".."):
        try:
            module.sign(device, bad_source)
            raise AssertionError("Unsafe source directory was accepted")
        except ValueError:
            pass

    make_cert(module.KEY_DIR / "tftp.pem", "Different-TFTP")
    try:
        module.sign(device)
    except RuntimeError as error:
        assert "Existing ITL" in str(error), str(error)
    else:
        raise AssertionError("Unexpectedly signed against changed trust key")

    try:
        module.sign("../../etc/passwd")
    except ValueError:
        pass
    else:
        raise AssertionError("Unsafe device ID was accepted")
    print("Integration test passed: real ITL/signatures, reuse, trust mismatch, safe device IDs")
