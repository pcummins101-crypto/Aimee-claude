# Security and privacy hardening in 1.8.3

## Private onboarding profile media

Profile photos are no longer stored in WordPress uploads. The default directory
is a site-specific directory beside, and never inside, the resolved web document
root. Every read/write/delete operation fails closed if the resolved
path is inside `ABSPATH`, WordPress uploads, or `DOCUMENT_ROOT`.

Production deployments should define an absolute, persistent location in
`wp-config.php`, outside every web-server document root and backed up with the
site database:

```php
define('AIMEE_PROFILE_MEDIA_DIR', '/srv/aimee-private/profile-media');
```

Keep that path stable during migrations. Files use the deterministic basename
`profile-user-{WordPress user ID}` and do not depend on WordPress authentication
salts. `aimee_profile_media_delete_user_files($user_id)` returns `true` only when
all candidate files are absent. Account deletion must stop and retry if it
returns `false`.

If `DOCUMENT_ROOT` cannot be resolved (for example in some CLI jobs), no derived
default is trusted and media operations fail closed. Define
`AIMEE_PROFILE_MEDIA_DIR` for those deployments.

Writes use a private temporary file followed by rename. POSIX systems replace an
existing target atomically. Platforms that refuse replacement rename (including
common Windows configurations) retain the previous image, delete the temporary
candidate, and return a `WP_Error`; no partial replacement is reported.

On POSIX filesystems, the directory is verified as owner-only `0700` and each
photo as owner-only `0600`; an unverifiable mode fails closed. PHP mode bits do
not describe Windows ACLs, so Windows operators must grant the web-service
identity exclusive access to `AIMEE_PROFILE_MEDIA_DIR`. The outside-document-root
checks still apply on Windows.

The release migrates the historical 1.8.2
`aimee_user_{owner ID}_{timestamp}.{extension}` upload exactly once. Only an
owner-bound URL on the current same-host WordPress uploads base is mapped. The
source bytes must pass the same magic-MIME, byte, dimension and pixel checks as
a new upload. The migration then verifies private storage, deletes and verifies
the public source absent, conditionally changes the exact owner/old-URL database
row, and reads the result back. Every partial state is retryable. An
unrecognized URL, failed deletion, changed row or failed verification keeps
`aimee_profile_media_migration_183` pending and prevents the 1.8.3 release
marker from advancing. The chat UI never renders a stored database URL while
this work is pending.

Other private media subsystems can apply the identical directory boundary via
`aimee_private_storage_default_dir($purpose)` and
`aimee_private_storage_prepare_dir($purpose, $configured_dir)`. The latter
returns a resolved owner-only directory or `WP_Error`; it must never be replaced
with a public uploads fallback.

Every UK, US, shared-slug and staff gallery template now enforces authentication
and active membership/administrator access itself. It renders only the current
owner's re-authorized catalogue entries through the protected payload route.
WordPress attachment enumeration, direct upload URLs and the old staff form
that wrote new catalogue images into public uploads have been removed. Curated
assets must be provisioned through the private catalogue workflow.

The plugin archive contains no private catalogue bytes. Each enabled item must
be supplied through a `catalog.json` inside the protected catalogue directory
with an exact SHA-256. A recognized legacy public source without that manifest
blocks the release marker. When no catalogue and no legacy source exists, the
release records a safe `disabled_no_assets` state and serves no static
catalogue. Completed migration is one-time: a later public-file regression is
reported and never treated as standing authority for automatic deletion.

Voice notes use the same outside-document-root and owner-only mode contract.
Migration accepts only files owned by a durable voice-note database row; it
does not discover arbitrary files by name. New upload and text-to-speech writes
use a private `0600` temporary file, verify the completed bytes and rename into
place. Account erasure performs a second exact-file-family scan after database
cleanup so a worker cannot recreate a file between the first scan and user
deletion.

## Account-deletion tombstone

Account erasure writes `account_deletion_started_at` while holding the profile's
billing lease, before provider calls or filesystem deletion. Consent changes,
billing work and private media/voice workers reject the account while that
tombstone is present. Workers retain and recheck the shared lease through
generation or migration, so they cannot publish new private bytes after the
destructive path has begun. A failed deletion clears only the tombstone owned by
that attempt and can be retried; successful deletion removes the profile row.

## Authentication and consent contracts

New accounts require 12–128 Unicode characters. Password bytes are passed to
WordPress unchanged, so existing six-digit accounts remain valid under the same
alias-aware throttle. The lock is enforced both before and after all WordPress
authenticators. A successful login clears only its identity bucket, never the
shared IP bucket.

The current special-category consent version is returned by
`aimee_special_category_consent_version()` and can be set with
`AIMEE_SPECIAL_CATEGORY_CONSENT_VERSION`. Adult specialist/media gates should
call `aimee_special_category_consent_is_active($profile)`, which requires a
valid stored timestamp and an exact current-version match.

Existing accounts with NULL consent fields are not backfilled. Authenticated
users can explicitly record the privacy acknowledgement, give current-version
special-category consent, or withdraw it through
`/wp-json/aimee/v1/privacy-consent`. Cookie-authenticated requests require the
WordPress REST nonce. Withdrawal immediately clears both special-category
fields and the legacy specialist toggle; adult/specialist gates therefore fail
closed until a later explicit consent at the current version.
