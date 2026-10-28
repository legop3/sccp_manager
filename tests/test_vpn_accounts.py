#!/usr/bin/env python3
"""Smoke-test SCCP Manager's managed ocserv account listing and removal."""
import importlib.machinery
from pathlib import Path
import tempfile
import types

ROOT = Path(__file__).resolve().parents[1]
loader = importlib.machinery.SourceFileLoader("sccp_vpn_helper_accounts",
                                             str(ROOT / "scripts/sccp-manager-vpn-helper"))
module = types.ModuleType(loader.name)
loader.exec_module(module)

with tempfile.TemporaryDirectory() as tmp:
    module.ACCOUNT_PATH = Path(tmp) / "ocpasswd"
    assert module.read_accounts() == []
    module.ACCOUNT_PATH.write_text(
        "# managed\nphone1::$6$SALT$HASH\nphone2:operators:!$6$SALT$HASH\n"
    )
    accounts = module.read_accounts()
    assert [a["username"] for a in accounts] == ["phone1", "phone2"]
    assert accounts[0]["enabled"] is True and accounts[0]["password_set"] is True
    assert accounts[1]["enabled"] is False and accounts[1]["group"] == "operators"
    assert "$6$" not in repr(accounts), "Must never expose VPN password hashes"
    module.delete_account("phone1")
    assert [a["username"] for a in module.read_accounts()] == ["phone2"]
    try:
        module.delete_account("phone1")
        raise AssertionError("Deleting missing account must fail")
    except ValueError:
        pass
    for bad in ("../../root", "phone\nx", ""):
        try:
            module.delete_account(bad)
            raise AssertionError("Unsafe username was accepted")
        except ValueError:
            pass
print("VPN account helper tests passed.")
