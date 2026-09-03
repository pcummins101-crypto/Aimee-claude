#!/usr/bin/env python3
"""Dependency-free PHP delimiter/string/comment sanity check.

This is not a replacement for `php -l`. It catches truncated writes,
unterminated strings/comments/heredocs and mismatched delimiters in PHP code
while ignoring HTML/JavaScript outside PHP tags. It exists so source packaging
can still fail fast when a native PHP runtime is unavailable.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
EXCLUDED_DIRS = {"tests"}


def php_files() -> list[Path]:
    return sorted(
        path
        for path in ROOT.rglob("*.php")
        if not any(part in EXCLUDED_DIRS for part in path.relative_to(ROOT).parts)
    )


def check_file(path: Path) -> list[str]:
    source = path.read_text(encoding="utf-8")
    errors: list[str] = []
    stack: list[tuple[str, int, int]] = []
    matching = {")": "(", "]": "[", "}": "{"}
    state = "outside"
    quote = ""
    heredoc = ""
    line = 1
    column = 1
    index = 0

    def advance(text: str) -> None:
        nonlocal line, column
        newlines = text.count("\n")
        if newlines:
            line += newlines
            column = len(text.rsplit("\n", 1)[-1]) + 1
        else:
            column += len(text)

    while index < len(source):
        if state == "outside":
            match = re.search(r"<\?(?:php|=)", source[index:], re.IGNORECASE)
            if not match:
                break
            chunk = source[index : index + match.end()]
            advance(chunk)
            index += match.end()
            state = "code"
            continue

        if state == "heredoc":
            end = source.find("\n", index)
            end = len(source) if end < 0 else end + 1
            text = source[index:end]
            if re.match(rf"^[ \t]*{re.escape(heredoc)};?[ \t]*(?:\r?\n)?$", text):
                state = "code"
                heredoc = ""
            advance(text)
            index = end
            continue

        char = source[index]
        pair = source[index : index + 2]

        if state == "line_comment":
            advance(char)
            index += 1
            if char == "\n":
                state = "code"
            continue

        if state == "block_comment":
            if pair == "*/":
                advance(pair)
                index += 2
                state = "code"
            else:
                advance(char)
                index += 1
            continue

        if state == "string":
            if char == "\\":
                escaped = source[index : min(len(source), index + 2)]
                advance(escaped)
                index += len(escaped)
                continue
            advance(char)
            index += 1
            if char == quote:
                state = "code"
                quote = ""
            continue

        # PHP code state.
        if pair == "?>":
            advance(pair)
            index += 2
            state = "outside"
            continue
        if pair == "//" or char == "#":
            advance(pair if pair == "//" else char)
            index += 2 if pair == "//" else 1
            state = "line_comment"
            continue
        if pair == "/*":
            advance(pair)
            index += 2
            state = "block_comment"
            continue
        if char in {"'", '"', "`"}:
            quote = char
            state = "string"
            advance(char)
            index += 1
            continue
        if source.startswith("<<<", index):
            line_end = source.find("\n", index)
            line_end = len(source) if line_end < 0 else line_end + 1
            declaration = source[index:line_end]
            match = re.match(r"<<<[ \t]*['\"]?([A-Za-z_][A-Za-z0-9_]*)['\"]?[ \t]*(?:\r?\n)?$", declaration)
            if not match:
                errors.append(f"{line}:{column}: malformed heredoc declaration")
                return errors
            heredoc = match.group(1)
            state = "heredoc"
            advance(declaration)
            index = line_end
            continue
        if char in "([{":
            stack.append((char, line, column))
        elif char in ")]}":
            if not stack or stack[-1][0] != matching[char]:
                errors.append(f"{line}:{column}: unmatched {char}")
                return errors
            stack.pop()

        advance(char)
        index += 1

    if state in {"string", "block_comment", "heredoc"}:
        errors.append(f"{line}:{column}: unterminated {state}")
    if stack:
        opening, opening_line, opening_column = stack[-1]
        errors.append(f"{opening_line}:{opening_column}: unclosed {opening}")
    return errors


def main() -> int:
    files = php_files()
    failures = 0
    for path in files:
        errors = check_file(path)
        for error in errors:
            failures += 1
            print(f"FAIL {path.relative_to(ROOT)}:{error}")
    if failures:
        print(f"RESULT {len(files)} production PHP files checked; {failures} lexical failures")
        return 1
    print(f"PASS {len(files)} production PHP files have balanced PHP lexical structure")
    print("NOTE native `php -l` remains required before production deployment")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
