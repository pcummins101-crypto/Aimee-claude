#!/usr/bin/env python3
"""Run all executable audit regressions and report suite-level results."""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path


TESTS = Path(__file__).resolve().parent
commands = [
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
    [
        "node",
        str(TESTS / "run-php-wasm.mjs"),
        "tests/romantic-expression-regression.php",
        "tests/companion-voice-regression.php",
        "tests/cross-channel-prompt-regression.php",
        "tests/synthetic-identity-regression.php",
        "tests/user-image-event-regression.php",
        "tests/profile-attribution-regression.php",
        "tests/profile-attribution-wiring-regression.php",
        "tests/profile-opening-repair-regression.php",
        "tests/ni-relationship-repair-1.8.9-regression.php",
        "tests/durable-rupture-lifecycle-1.8.9-regression.php",
        "tests/consciousness-voice-regression.php",
        "tests/intimacy-media-policy-regression.php",
        "tests/media-cadence-regression.php",
        "tests/media-cadence-integration-regression.php",
        "tests/media-materialization-async-regression.php",
        "tests/media-materialization-erasure-regression.php",
        "tests/service-grace-regression.php",
        "tests/georgia-colleague-regression.php",
        "tests/security-privacy-regression.php",
        "tests/registration-runtime-1.8.8-regression.php",
        "tests/public-media-catalogue-runtime-regression.php",
        "tests/gallery-album-entitlement-handoff-1.8.5-regression.php",
        "tests/billing-migration-hardening-regression.php",
        "tests/gocardless-creditor-binding-regression.php",
        "tests/php-syntax-regression.php",
    ],
    [
        "env",
        "AIMEE_PHP_VERSION=7.4",
        "node",
        str(TESTS / "run-php-wasm.mjs"),
        "tests/romantic-expression-regression.php",
        "tests/companion-voice-regression.php",
        "tests/cross-channel-prompt-regression.php",
        "tests/synthetic-identity-regression.php",
        "tests/user-image-event-regression.php",
        "tests/profile-attribution-regression.php",
        "tests/profile-attribution-wiring-regression.php",
        "tests/profile-opening-repair-regression.php",
        "tests/ni-relationship-repair-1.8.9-regression.php",
        "tests/durable-rupture-lifecycle-1.8.9-regression.php",
        "tests/consciousness-voice-regression.php",
        "tests/intimacy-media-policy-regression.php",
        "tests/media-cadence-regression.php",
        "tests/media-cadence-integration-regression.php",
        "tests/media-materialization-async-regression.php",
        "tests/media-materialization-erasure-regression.php",
        "tests/service-grace-regression.php",
        "tests/georgia-colleague-regression.php",
        "tests/security-privacy-regression.php",
        "tests/registration-runtime-1.8.8-regression.php",
        "tests/public-media-catalogue-runtime-regression.php",
        "tests/gallery-album-entitlement-handoff-1.8.5-regression.php",
        "tests/billing-migration-hardening-regression.php",
        "tests/gocardless-creditor-binding-regression.php",
        "tests/php-syntax-regression.php",
    ],
    [
        "node",
        str(TESTS / "run-php-wasm.mjs"),
        "tests/photo-deletion-memory-gap-regression.php",
        "tests/photo-delivery-truth-regression.php",
        "tests/photo-request-regression.php",
        "tests/public-statement-voice-regression.php",
        "tests/suggestive-photo-autonomy-regression.php",
    ],
]

suite_passes = 0
suite_failures = 0
assertion_passes = 0
assertion_failures = 0

for command in commands:
    print("\n$ " + " ".join(command), flush=True)
    result = subprocess.run(
        command,
        cwd=TESTS.parent,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )
    print(result.stdout, end="")
    if result.returncode != 0:
        print(
            "COMMAND FAILED "
            f"(exit {result.returncode}): {' '.join(command)}",
            flush=True,
        )
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
        suite_passes += 1
    else:
        suite_failures += 1

print(
    f"\nAUDIT SUITE RESULT: {suite_passes} commands passed, "
    f"{suite_failures} failed; {assertion_passes} assertions passed, "
    f"{assertion_failures} failed"
)
sys.exit(1 if suite_failures or assertion_failures else 0)
