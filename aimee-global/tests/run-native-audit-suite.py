#!/usr/bin/env python3
"""Run the complete Aimee audit suite with the host PHP and Node runtimes.

This is a reproducible single-runtime companion to run-audit-suite.py. The
canonical runner remains authoritative for PHP 8.3/PHP 7.4 PHP-WASM replay.
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

TESTS = Path(__file__).resolve().parent
PLUGIN = TESTS.parent

commands: list[list[str]] = [
    [sys.executable, str(TESTS / "intimacy-policy-simulation.py")],
    [sys.executable, str(TESTS / "static-integration-regression.py")],
    [sys.executable, str(TESTS / "security-privacy-static-regression.py")],
    [sys.executable, str(TESTS / "schema-hardening-regression.py")],
    [sys.executable, str(TESTS / "gocardless-1.8.3-regression.py")],
    [sys.executable, str(TESTS / "gocardless-only-checkout-1.8.4-regression.py")],
    [sys.executable, str(TESTS / "goodwill-access-ui-1.8.10-regression.py")],
    [sys.executable, str(TESTS / "release-hardening-regression.py")],
    [sys.executable, str(TESTS / "public-media-catalogue-mode-1.8.5-regression.py")],
    [sys.executable, str(TESTS / "gallery-album-handoff-1.8.5-regression.py")],
    ["node", str(TESTS / "chat-notice-regression.mjs")],
    ["node", str(TESTS / "security-privacy-ui-regression.mjs")],
    ["node", str(TESTS / "privacy-choice-dismissal-1.8.5-regression.mjs")],
    ["node", str(TESTS / "gallery-album-handoff-ui-1.8.5-regression.mjs")],
]
commands.extend(
    [["php", str(path)] for path in sorted(TESTS.glob("*.php"))]
)

command_passes = 0
command_failures = 0
assertion_passes = 0
assertion_failures = 0

for command in commands:
    print("\n$ " + " ".join(command), flush=True)
    result = subprocess.run(
        command,
        cwd=PLUGIN,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
        env=None,
    )
    print(result.stdout, end="")

    assertion_passes += len(re.findall(r"(?m)^PASS ", result.stdout))
    assertion_passes -= len(
        re.findall(r"(?m)^PASS \d+ policy assertions;", result.stdout)
    )
    assertion_passes += sum(
        int(match.group(1))
        for match in re.finditer(r"(?m)^PASS:\s+(\d+)\b", result.stdout)
    )
    assertion_failures += len(re.findall(r"(?m)^FAIL ", result.stdout))

    if result.returncode == 0:
        command_passes += 1
    else:
        command_failures += 1

print(
    f"\nNATIVE AUDIT RESULT: {command_passes} commands passed, "
    f"{command_failures} failed; {assertion_passes} assertions passed, "
    f"{assertion_failures} failed"
)
sys.exit(1 if command_failures or assertion_failures else 0)
