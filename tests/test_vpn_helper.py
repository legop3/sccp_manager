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

    # Real 7945G on VPN requests Desktops/.../List.xml.sgn, and then
    # signed PNG resources. Confirm the helper signs binary and XML files
    # with the SAME signer without changing any phone's existing ITL.
    desktop = module.TFTP / "Desktops" / "320x212x16"
    desktop.mkdir(parents=True)
    image = desktop / "PC-0123456789abcdef.png"
    thumb = desktop / "TN-PC-0123456789abcdef.png"
    catalog = desktop / "List.xml"
    image.write_bytes(b"\x89PNG\r\n\x1a\n" + bytes(range(64)))
    thumb.write_bytes(b"\x89PNG\r\n\x1a\n" + bytes(range(32)))
    catalog.write_text('<CiscoIPPhoneImageList><ImageItem '
                       'Image="TFTP:Desktops/320x212x16/TN-PC-0123456789abcdef.png" '
                       'URL="TFTP:Desktops/320x212x16/PC-0123456789abcdef.png"/>'
                       '</CiscoIPPhoneImageList>')
    module.sign_content("wallpapers")
    for source in (catalog, image, thumb):
        signed_content = Path(str(source) + ".sgn")
        assert signed_content.is_file() and signed_content.stat().st_size > 0
        parsed = subprocess.run(
            [str(module.BIN_DIR / "sgnfile"), "--parse", str(signed_content),
             "--tftp", str(module.KEY_DIR / "tftp.pem")],
            check=True, capture_output=True, text=True)
        assert "Valid signature" in parsed.stdout
    assert trust.read_bytes() == old_trust, "Signing content changed the phone ITL"

    ringlist = module.TFTP / "RingList.xml"
    ringtone = module.TFTP / "PC-abcdef0123456789.raw"
    ringlist.write_text("<CiscoIPPhoneRingList><Ring><DisplayName>Chime</DisplayName>"
                        "<FileName>PC-abcdef0123456789.raw</FileName></Ring></CiscoIPPhoneRingList>")
    ringtone.write_bytes(bytes(range(240)))
    module.sign_content("ringtones")
    assert (module.TFTP / "RingList.xml.sgn").is_file()
    assert (module.TFTP / "PC-abcdef0123456789.raw.sgn").is_file()
    assert trust.read_bytes() == old_trust, "Signing ring content changed phone ITL"

    for bad_kind in ("../../etc", "root", "wallpapers/../etc"):
        try:
            module.sign_content(bad_kind)
            raise AssertionError("Unsafe content type accepted")
        except ValueError:
            pass
    rogue = desktop / "PC-aaaaaaaaaaaaaaaa.png"
    rogue.symlink_to("/etc/passwd")
    try:
        module.sign_content("wallpapers")
        raise AssertionError("Symlinked media file accepted")
    except RuntimeError as error:
        assert "Unsafe Cisco media file" in str(error), str(error)
    rogue.unlink()

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
