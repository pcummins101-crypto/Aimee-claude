# Aimee Global 1.8.11 test report

- `tests/gocardless-direct-debit-1.8.11-regression.php` added: Direct Debit intent payload, scheme-aware intent and provider terms matching, access-granting statuses per scheme, and abandonment of a stored Faster Payments intent.
- `tests/gocardless-creditor-binding-regression.php` pins `GOCARDLESS_MANDATE_SCHEME=faster_payments` so its VRP expectations are unchanged.
- Full native audit suite re-run after the change; see the 1.8.11 line in the results section of this document once staging has completed the sandbox checkout, provisional access and failure scenarios.

# Current regression report: Aimee Global 1.8.10

The current release evidence and clean-archive replay checklist are recorded
in `TEST-REPORT-1.8.10.md`. The complete 1.8.9 relationship-restoration report
remains available in `TEST-REPORT-1.8.9.md`.

Version `1.8.10` keeps schema `2026.08.20.3` and relationship policy `2.2.1`.
It corrects customer-facing subscription state when the server reports active
`goodwill_extension` access together with a future reactivation or
new-subscription requirement. Those billing facts remain stored and visible to
the server; they no longer override active access in chat, settings or pricing.

Final deterministic source evidence:

- audit command groups: `17` passed, `0` failed;
- assertions: `5,792` passed, `0` failed;
- goodwill UI static regression: `44/44`;
- chat notice runtime regression: `46/46`;
- main static integration regression: `342/342`;
- production PHP syntax: `48/48` on PHP 8.3 and PHP 7.4; and
- no database schema or payment-provider mutation was introduced.

The exact six-digit new-account PIN, reliable registration, GoCardless-only
new UK checkout, unavailable new US/SMS checkout, legacy Stripe runoff,
relationship restoration, authenticated catalogue delivery, Camera Roll,
adult assurance, special-category consent and explicit-image relationship
gates remain in force. The final archive SHA-256 is published in its sibling
`.sha256` manifest.
