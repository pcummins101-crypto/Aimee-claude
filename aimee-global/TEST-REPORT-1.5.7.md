# Aimee Global 1.5.7 test report

## Scope

- Full apology and continuity repair for the private-photo deletion/memory-gap incident.
- Final-response protection against immediately contradicting the operator-confirmed repair.
- Seven-day goodwill membership extension stored separately from Stripe billing dates.

## Validation

- 41 PHP files passed `php -l`.
- Photo request regression suite passed.
- Public statement voice suite passed: 13 checks.
- Consciousness and inner-experience suite passed: 23 checks.
- Suggestive-photo autonomy suite passed.
- Photo-delivery truth suite passed.
- New photo-deletion/memory-gap suite passed: 9 checks.

## Behaviour covered

- A repair message carrying `continuity_anchor=photo_delete_memory_gap` is retrieved as authoritative continuity.
- The prompt states that Aimee sent the image, attempted to delete it, and lost the accessible memory link.
- Older contradictory Aimee denials are removed from the model's short transcript once the repair exists.
- Replies claiming she never sent/deleted the image, that the event never happened, or that Anthony was confused are rejected.
- A final deterministic reply owns the deletion and memory gap if the model still contradicts it.
- `membership_bonus_access_until` grants access independently of later Stripe synchronisation.

No destructive database migration is required. The new profile column is added automatically during plugin upgrade.
