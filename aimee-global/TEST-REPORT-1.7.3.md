# Aimee Global 1.7.3 — Georgia colleague repair regression report

Date: 5 August 2026  
Tree tested: `work/aimee-global`  
Result: **6 commands passed; 1,296 assertions passed; 0 failed**

## One-command source run

```bash
python3 tests/run-audit-suite.py
```

Final result:

```text
AUDIT SUITE RESULT: 6 commands passed, 0 failed; 1296 assertions passed, 0 failed
```

## Clean packaged-archive replay

The release ZIP was extracted into a new temporary directory and the bundled
suite was run from that extracted copy, not from the working tree. Result:

```text
AUDIT SUITE RESULT: 6 commands passed, 0 failed; 1296 assertions passed, 0 failed
```

This proves that the archive contains the tested 1.7.3 source, the Georgia
regression, both PHP-runtime runners and the required documentation. It does
not claim a live WordPress deployment or mutation of Georgia's production
account.

## Exact command groups and counts

| Command group | Result | Assertions |
|---|---:|---:|
| `intimacy-policy-simulation.py` | Pass | 70, plus 44 committed scenario-policy summaries |
| `static-integration-regression.py` | Pass | 251 |
| `chat-notice-regression.mjs` | Pass | 26 |
| PHP 8.3: intimacy/media + service grace + Georgia colleague + production syntax | Pass | 422 |
| PHP 7.4: intimacy/media + service grace + Georgia colleague + production syntax | Pass | 422 |
| Carried-forward consciousness/photo/public-statement regressions | Pass | 105 |
| **Total** | **Pass** | **1,296** |

Each PHP policy group executes 263 intimacy/media assertions, 91
service-grace assertions and 67 Georgia colleague assertions, then parses all
**39/39** production PHP files with `TOKEN_PARSE`. The focused Georgia suite
therefore contributes **134/134** passing assertions across PHP 8.3 and PHP
7.4. It includes the original 49 workflow checks, three immutable-identity
checks, eight dynamic one-time state-repair checks and seven deliverable-type
fallback checks on each runtime.

## 1.7.3 colleague workflow coverage

The focused regression proves that:

- the immutable configured Georgia identity defaults to WordPress user 24;
- adjacent accounts, an editable name and ordinary administrator privileges
  cannot inherit Georgia's colleague context;
- authenticated colleague turns use `colleague_primary`, independently of the
  consumer dating route and subscription-derived access labels;
- written lists, captions, descriptions and safe or brand-appropriate flirty
  photo concepts are recognised as professional creative briefs;
- written ideation is explicitly text-only, creates no attachment contract and
  cannot be replaced by a relationship-stage, membership, pressure or image-
  delivery refusal;
- actual requests to send, show, attach or resend a photograph remain actual
  media requests and continue through the ordinary media authorisation and
  delivery controls;
- a requested item count is persisted in the brief and a partial, missing or
  stock-boundary response fails completion validation;
- one constrained provider repair is attempted, after which an inspectable
  deterministic fallback supplies the complete numbered written deliverable
  without claiming or promising an attachment; caption requests remain caption
  sets rather than degrading into generic photo ideas;
- short creative continuations inherit the established deliverable type and
  permitted brand-appropriate flirty tone;
- the known false Georgia rupture is repaired once only when the immutable
  user, stored false-boundary reply and/or exact rupture evidence match;
- a genuine or unrelated rupture is not cleared, a completed repair is not
  replayed, and Georgia's stored consumer intimacy score and stage are not
  manufactured or rewritten;
- the colleague prompt preserves a warm, close-friend but professionally
  grounded talent/manager relationship, always uses Georgia's identity and
  pronouns, and keeps private biography out of public copy; and
- Luke and first-home check-ins are available on a bounded occasional cadence,
  after the current work request and only when they fit naturally.

## Narrowed false-coercion handling

The regression includes Georgia's supplied wording asking Aimee to “send me”
ten social-media post ideas. In the authenticated colleague workflow, “send me
post ideas” and “send me caption ideas” mean written creative work; the words
`send me` alone cannot turn them into a private-media demand. The narrowing is
not global permission to bypass boundaries: a real repeated demand to deliver
media remains detectable as coercive, and actual attachments retain the adult,
consent, catalogue, cooldown and delivery gates.

## Preserved 1.7.2 coverage

All 1.7.2 August service-grace, replacement-billing, SMS, chat-notice,
relationship, intimacy, model-route, proactive-media and delivery-truth
regressions remain green. The relationship policy remains `2.1.0` and the
schema remains `2026.08.03.6`; this release changes colleague routing and
repairs one evidence-bound false inner-state rupture without reinterpreting
consumer relationship state.

The historical 1.7.2 source and packaged-archive results remain documented in
`TEST-REPORT-1.7.2.md`.

## Staging gates

Before production:

1. Confirm `AIMEE_GEORGIA_USER_ID` resolves to Georgia's immutable WordPress
   user ID 24 and that Paul and neighbouring/admin accounts do not enter
   colleague mode.
2. On Georgia's signed-in chat, request ten safe ideas, ten flirty written
   concepts and a short “more please” continuation; verify each answer is
   complete and uses `colleague_primary`.
3. Verify an actual image attachment request remains separate and retains the
   existing media decision, authorisation and delivery-state controls.
4. Inspect the one-time repair result and confirm it cleared only the known
   false rupture while leaving Georgia's stored score and stage unchanged.
5. Confirm a genuine unrelated rupture is preserved and the repair cannot run
   again after its completion marker is stored.
6. Verify the occasional Luke/first-home question never displaces an unfinished
   work request and does not expose private details in public-facing copy.
7. Re-run the full suite after packaging and perform the existing 1.7.2 billing,
   SMS, chat-notice and media staging gates.

No live account mutation, external message or production deployment was
performed by this source-tree test run.
