#!/usr/bin/python3
"""Optional cross-repository compatibility checks in the private test namespace."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile

source = os.environ.get("SLS_DESKTOP_APP_SOURCE", "")
if not source:
    if os.environ.get("SLS_DESKTOP_APP_REQUIRED") == "1":
        raise SystemExit("Set SLS_DESKTOP_APP_SOURCE to the reviewed desktop checkout.")
    print(
        "Desktop cross-repository qualification not run: no reviewed app checkout was supplied."
    )
    raise SystemExit(0)
if os.environ.get("SLS_TEST_NAMESPACE") != "entered":
    raise SystemExit(
        "Use tools/run_isolated_tests.sh for desktop compatibility checks."
    )
root = Path(source).resolve(strict=True)
if (
    not (root / "src/sls_mass_notify.py").is_file()
    or not (root / "tests/test_sls_live_polling.py").is_file()
):
    raise SystemExit("The desktop qualification source tree is incomplete.")
with tempfile.TemporaryDirectory(prefix="sls-desktop-app-qualification-") as temporary:
    paths = [str(root / "src")] + [
        value
        for value in os.environ.get("SLS_DESKTOP_PYTHON_PATH", "").split(os.pathsep)
        if value
    ]
    environment = dict(
        os.environ,
        PYTHONDONTWRITEBYTECODE="1",
        PYTHONPATH=os.pathsep.join(paths),
        APPDATA=temporary,
        LOCALAPPDATA=temporary,
    )
    suites = [
        "tests.test_sls_live_poll_cursor",
        "tests.test_sls_live_polling",
        "tests.test_sls_delivery_store",
        "tests.test_sls_backend_contract",
        "tests.test_sls_transport_deadlines",
        "tests.test_sls_panic",
        "tests.test_sls_media_auth",
    ]
    result = subprocess.run(
        [sys.executable, "-m", "unittest", *suites],
        cwd=root,
        env=environment,
        check=False,
        timeout=210,
    )
    raise SystemExit(result.returncode)
