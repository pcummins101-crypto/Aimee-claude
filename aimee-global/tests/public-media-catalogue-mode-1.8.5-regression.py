#!/usr/bin/env python3
"""Static 1.8.6 regression for the operator-approved public catalogue mode.

The live Aimee installation intentionally keeps its established catalogue in
``wp-content/aimee-private-media``.  This suite protects that narrowly scoped
deployment choice without weakening the default private-storage contract or
the user-, membership-, adult-assurance- and consent-level delivery gates.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
BOOTSTRAP = (ROOT / "aimee-global.php").read_text(encoding="utf-8")
ENGINE = (ROOT / "includes/engine.php").read_text(encoding="utf-8")
ADMIN = (ROOT / "includes/admin.php").read_text(encoding="utf-8")

passes = 0
failures = 0


def check(condition: bool, label: str) -> None:
    global passes, failures
    if condition:
        passes += 1
        print(f"PASS {label}")
    else:
        failures += 1
        print(f"FAIL {label}")


def function_body(source: str, name: str) -> str:
    """Return a named PHP function body using balanced braces and strings."""

    match = re.search(rf"(?m)^function\s+{re.escape(name)}\s*\(", source)
    if not match:
        return ""
    brace = source.find("{", match.end())
    if brace < 0:
        return ""
    depth = 0
    quote = ""
    comment = ""
    escaped = False
    offset = brace
    while offset < len(source):
        char = source[offset]
        following = source[offset + 1] if offset + 1 < len(source) else ""
        if comment == "line":
            if char in "\r\n":
                comment = ""
            offset += 1
            continue
        if comment == "block":
            if char == "*" and following == "/":
                comment = ""
                offset += 2
            else:
                offset += 1
            continue
        if quote:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = ""
            offset += 1
            continue
        if char == "/" and following == "/":
            comment = "line"
            offset += 2
            continue
        if char == "/" and following == "*":
            comment = "block"
            offset += 2
            continue
        if char == "#":
            comment = "line"
            offset += 1
            continue
        if char in "'\"":
            quote = char
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source[brace + 1 : offset]
        offset += 1
    return ""


mode_enabled = function_body(ENGINE, "aimee_public_media_catalogue_mode_enabled")
public_dir = function_body(ENGINE, "aimee_public_media_catalogue_dir")
private_dir = function_body(ENGINE, "aimee_private_media_dir")
catalog_path = function_body(ENGINE, "aimee_private_media_catalog_path")
legacy_item = function_body(ENGINE, "aimee_private_media_public_legacy_item")
normalizer = function_body(ENGINE, "aimee_normalize_private_media_item")
catalogue = function_body(ENGINE, "aimee_private_media_catalog")
public_status = function_body(ENGINE, "aimee_private_media_public_catalogue_status")
public_asset_path = function_body(ENGINE, "aimee_private_media_public_asset_path")
validation_interval = function_body(ENGINE, "aimee_private_media_public_validation_interval")
validation_fresh = function_body(ENGINE, "aimee_private_media_public_validation_is_fresh")
catalog_ready = function_body(ENGINE, "aimee_private_media_catalog_configuration_ready")
seed = function_body(ENGINE, "aimee_seed_private_media_library")
library_health = function_body(ENGINE, "aimee_private_media_library_is_private")
static_path = function_body(ENGINE, "aimee_private_media_static_path")
media_url = function_body(ENGINE, "aimee_private_media_url")
controller_url = function_body(ENGINE, "aimee_private_media_controller_url")
media_payload = function_body(ENGINE, "aimee_private_media_payload")
repair = function_body(ENGINE, "aimee_repair_private_media_asset")
admin_page = function_body(ADMIN, "aimee_global_admin_page")


check(
    "Version: 1.8.11" in BOOTSTRAP
    and "define('AIMEE_GLOBAL_VERSION', '1.8.11')" in BOOTSTRAP
    and "define('AIMEE_GLOBAL_SCHEMA_VERSION', '2026.08.20.3')" in BOOTSTRAP,
    "current release is 1.8.11 without an unnecessary schema bump",
)
check(
    "defined('AIMEE_PUBLIC_MEDIA_CATALOGUE_MODE')" in mode_enabled
    and "is_string(AIMEE_PUBLIC_MEDIA_CATALOGUE_MODE)" in mode_enabled
    and "hash_equals('operator_approved', AIMEE_PUBLIC_MEDIA_CATALOGUE_MODE)"
    in mode_enabled,
    "public catalogue requires the exact operator-approved sentinel",
)
check(
    "WP_CONTENT_DIR" in public_dir
    and "'aimee-private-media'" in public_dir
    and "realpath" in public_dir
    and "is_link($candidate)" in public_dir
    and "basename($resolved) !== 'aimee-private-media'" in public_dir,
    "public mode is fixed to one real non-symlink wp-content child",
)
check(
    "AIMEE_PRIVATE_MEDIA_DIR" not in public_dir
    and "AIMEE_PRIVATE_MEDIA_CATALOG" not in public_dir
    and "http" not in public_dir.lower(),
    "public mode accepts no arbitrary directory, manifest path or URL",
)
check(
    "aimee_public_media_catalogue_mode_enabled()" not in private_dir
    and "aimee_public_media_catalogue_dir()" not in private_dir
    and "AIMEE_PRIVATE_MEDIA_DIR" in private_dir
    and "aimee_private_storage_prepare_dir('private-catalogue'" in private_dir,
    "the baseline private resolver remains protected-only in every mode",
)
check(
    "aimee_public_media_catalogue_mode_enabled()" in catalog_path
    and "'catalog.json'" in catalog_path
    and "is_link($candidate)" in catalog_path
    and "basename($resolved) === 'catalog.json'" in catalog_path
    and catalog_path.find("aimee_public_media_catalogue_dir()")
    < catalog_path.find("aimee_private_media_dir()"),
    "operator mode resolves only the real root-level catalog.json before private storage",
)
check(
    "if (!array_key_exists('sha256', $item)) $item['sha256'] = '';" in legacy_item
    and "if (!array_key_exists('direct_request_allowed', $item))" in legacy_item
    and "$item['proactive_allowed'] = $rating === 'safe'" in legacy_item
    and ": false;" in legacy_item
    and "$item['membership_required'] = $rating !== 'safe';" in legacy_item,
    "legacy manifests receive conservative in-memory delivery defaults",
)
check(
    "$is_explicit" in legacy_item
    and "? 'verified'" in legacy_item
    and "['chat', 'voice', 'voice_note', 'continuity']" in legacy_item,
    "legacy explicit records remain assurance-gated and channel-bounded",
)
check(
    "$required_field === 'sha256'" in normalizer
    and "aimee_public_media_catalogue_mode_enabled()" in normalizer
    and "$sha256 === ''" in normalizer
    and "preg_match('/^[a-f0-9]{64}$/', $sha256)" in normalizer,
    "missing hashes are public-mode-only while supplied hashes remain exact",
)
check(
    "'image/jpeg' => ['jpg', 'jpeg']" in normalizer
    and "'image/png' => ['png']" in normalizer
    and "pathinfo($filename, PATHINFO_EXTENSION)" in normalizer
    and "in_array($extension, $allowed_extensions[$mime], true)" in normalizer,
    "manifest filename extensions must agree with the declared MIME",
)
check(
    "$raw_catalog = aimee_public_media_catalogue_mode_enabled() ? [] : $fallback;"
    in catalogue
    and catalogue.count("aimee_private_media_public_legacy_item") >= 2
    and "array_replace($fallback[$external_key], $external_item)" in catalogue,
    "the public manifest is authoritative while declared built-ins may be enriched",
)
check(
    "aimee_public_media_catalogue_mode_enabled()" in public_status
    and "json_decode" in public_status
    and "aimee_private_media_catalog()" in public_status
    and "aimee_private_media_public_asset_path" in public_status
    and "public_catalogue_assets_degraded" in public_status,
    "public catalogue readiness parses, normalizes and byte-validates the manifest with a degraded state",
)
check(
    "is_link($candidate)" in public_asset_path
    and "basename($filename) !== $filename" in public_asset_path
    and "aimee_profile_media_path_is_within" in public_asset_path
    and "aimee_private_media_file_matches_item($resolved, $item, false)"
    in public_asset_path
    and "hashes_declared" in public_status
    and "files_ready" in public_status
    and "operational" in public_status
    and "degraded" in public_status
    and "healthy" in public_status,
    "public readiness reports containment, link safety, hash coverage and degraded operation",
)
check(
    "static $request_cache" in public_status
    and "$refresh" in public_status
    and "$cache_key" in public_status
    and "isset($request_cache[$cache_key])" in public_status,
    "ordinary repeated status reads are request-memoized by manifest path and digest",
)
check(
    "15 * MINUTE_IN_SECONDS" in validation_interval
    and "last_validated_at" in validation_fresh
    and "time() + 5 * MINUTE_IN_SECONDS" in validation_fresh
    and "catalog_path" in validation_fresh
    and "catalog_sha256" in validation_fresh
    and "hash_file('sha256', $catalog_path)" in validation_fresh,
    "cross-request reuse is bounded by time and the exact live manifest path and digest",
)
check(
    "aimee_private_media_public_catalogue_status" in catalog_ready
    and "['operational']" in catalog_ready
    and "['healthy']" not in catalog_ready,
    "catalogue readiness keeps valid records usable when unrelated records are unavailable",
)
public_seed_end = seed.find("if (!aimee_private_media_catalog_configuration_ready())")
public_seed = seed[:public_seed_end] if public_seed_end >= 0 else ""
check(
    "aimee_public_media_catalogue_mode_enabled()" in public_seed
    and "aimee_private_media_public_catalogue_status" in public_seed
    and "empty($status['operational'])" in public_seed
    and "public_catalogue_assets_degraded" in public_seed
    and "operator_approved_public_catalogue" in public_seed
    and "aimee_private_media_migrate_item" not in public_seed
    and "aimee_private_media_delete_public_source_family" not in public_seed,
    "public-mode activation records health without moving or deleting media",
)
check(
    seed.find("aimee_private_media_public_validation_is_fresh")
    < seed.find("aimee_private_media_public_catalogue_status(true)")
    < seed.find("update_option")
    and "last_validated_at" in public_seed
    and "catalog_path" in public_seed
    and "catalog_sha256" in public_seed,
    "fresh startup avoids rescanning and rewriting while stale startup forces validation",
)
check(
    "aimee_public_media_catalogue_mode_enabled()" in library_health
    and "aimee_private_media_public_catalogue_status" in library_health,
    "ongoing catalogue health uses the public validator in operator mode",
)
check(
    "aimee_public_media_catalogue_mode_enabled()" in static_path
    and "aimee_private_media_public_validation_is_fresh" not in static_path
    and "aimee_private_media_public_asset_path" in static_path
    and "isset($catalog[$key])" in static_path,
    "static delivery validates the selected live bytes independently of whole-catalogue health",
)
check(
    "aimee_public_media_catalogue_mode_enabled()" in repair
    and "aimee_private_media_public_validation_is_fresh" not in repair
    and "aimee_private_media_public_asset_path" in repair
    and repair.find("aimee_private_media_public_asset_path")
    < repair.find("aimee_private_media_migrate_item"),
    "public-mode repair revalidates one selected item and remains read-only",
)
check(
    "aimee_public_media_catalogue_mode_enabled" not in media_url
    and "aimee_private_media_static_path" not in media_url
    and "content_url" not in media_url
    and "aimee_private_media_controller_url($key, $delivery_id)" in media_url,
    "canonical media URLs are controller-only in every storage mode",
)
check(
    "admin_url('admin-post.php')" in controller_url
    and "'action' => 'aimee_private_media'" in controller_url
    and "'key' => $key" in controller_url
    and "$args['delivery_id'] = $delivery_id" in controller_url
    and "aimee_private_media_controller_url($key, $delivery_id)" in media_url,
    "controller helper owns authenticated URLs and optional delivery references",
)
check(
    "aimee_private_media_delivery_asset" in media_payload
    and "'url'            => aimee_private_media_url($key, $delivery_id)" in media_payload
    and "aimee_private_media_controller_url" not in media_payload
    and "content_url" not in media_payload,
    "every in-app image payload delegates to the controller-only canonical URL",
)
check(
    "aimee_private_media_public_catalogue_status" in admin_page
    and "public_media_operational" in admin_page
    and "aimee_private_media_public_validation_is_fresh" not in admin_page
    and "array_unique(array_filter(array_merge(" in admin_page
    and "invalid_entries" in admin_page
    and "missing_files" in admin_page
    and "required_keys_missing" in admin_page
    and "unavailable item" in admin_page
    and "authenticated controller" in admin_page
    and "skipped" in admin_page,
    "administrator status explains controller delivery and uniquely counts every skipped invalid item",
)

print(
    f"\nPUBLIC MEDIA CATALOGUE RESULT: {passes} checks passed, "
    f"{failures} failed"
)
sys.exit(1 if failures else 0)
