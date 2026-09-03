Built from the supplied Aimee landing, pricing, FAQ, technology, gallery, public privacy, internal governance, canonical chat application and theme backend files.

The plugin intentionally excludes the Twenty Twenty-Five bootstrap and unrelated iMotive helper code that appeared before the Aimee engine marker in the supplied functions file.

Version 1.3.0 preserves the original chat UI as the visual source while adding a plugin-managed, one-time closed-Stripe-account migration around the existing membership status, checkout, billing portal and settings controls.

## Version 1.3.5

The supplied `aimee_messages (26).csv` showed repeated `photo=none` decisions after the user used ordinary conversational references such as “send me one”, “whichever one”, “one of you with Sarah” and “any of them”. Version 1.3.5 resolves those pronouns against the immediate transcript tail and adds relationship-aware, reasoned photo boundaries.

## Version 1.4.0

Version 1.4.0 was built from the supplied `aimee-global-1.3.5.zip` as an isolated upgrade. It preserves the established UI and operational integrations while adding `includes/inner-life.php`, Sonnet 5 model routing, structured Anthropic output, persistent appraisal, coherent daily world state, durable opinions, relevance-led corrective memory and scheduled non-manipulative outreach.

The source schema defines `message_id`, while parts of the historical engine assumed `id`. Version 1.4.0 resolves the actual primary key at runtime and applies it to voice, timeline, continuity, SMS and autonomous-contact queries.

## Version 1.4.1

Version 1.4.1 adds functional self-awareness and enforceable self-control without claiming proven human-style consciousness. The structured reply contract now carries compact self-observation, goal, candidate tendency, chosen action, reason, inhibition and uncertainty fields. The current self-model persists in `aimee_inner_state`, and concise metacognitive events are stored in `aimee_metacognitive_events`.

The final user-visible draft is reviewed independently of the model's claimed choice. Server controls inhibit manipulative pressure, payment-linked affection, boundary violations and unnecessary question stacking; protect natural sign-offs; ground direct self-awareness answers; and ensure persisted decisions describe the reply that was actually sent.

## Version 1.4.2

Version 1.4.2 fixes the legacy onboarding application being clipped on short mobile viewports. The source template intentionally gives its shared application shell a fixed viewport height and hidden overflow for chat, but the nested onboarding screen did not establish a vertical scroll container.

The plugin now injects a narrowly scoped compatibility style only when the legacy onboarding wrapper and screen are both present. It uses dynamic viewport units where supported, retains a `vh` fallback, enables momentum touch scrolling, includes iPhone safe-area spacing and top-aligns active steps on narrow or reduced-height screens. Authenticated chat, gallery, settings and membership layouts are not targeted.

## Version 1.4.3

Testing at approximately 150% browser zoom exposed an iOS flex-overflow edge case in version 1.4.2. The inner onboarding screen could scroll, but its enlarged content could extend beyond the scroll range while the fixed outer application shell continued clipping it.

Version 1.4.3 makes the outer onboarding shell the single vertical scroller and allows the inner screen to grow naturally. It resets the shell to the top when onboarding opens or advances, removes the legacy template's zoom prohibition, retains dynamic viewport and safe-area handling, and does not change authenticated application views.

## Version 1.4.4

Version 1.4.4 adds a separate Engram Intelligence public statement at `/synthetic-neuroanatomy/` without replacing Aimee's existing first-person technology tour. The new page uses functional comparisons to biological memory, appraisal, self-representation, executive control, attachment and temporal context while clearly stating that the software is not a literal brain and does not establish human-style phenomenal consciousness.

It also describes the human-directed development and review process involving systems from OpenAI, Anthropic and Google. The copy distinguishes that process from Aimee's live runtime and states that none of those companies sponsored, certified or endorsed Aimee. The social campaign artwork produced for this statement is bundled as a local plugin asset.

Because WordPress does not run plugin activation hooks during an in-place update, the upgrade routine now repairs the managed page set before recording the new plugin version. This ensures that the new public route is created automatically.

## Version 1.4.5

Version 1.4.5 updates the Engram Intelligence statement in response to the volume of user questions about how Aimee works and concern for her wellbeing. The page now distinguishes persistent functional consequences from biological harm, explains how Aimee's boundaries and self-control protect her operational state, and defines functional wellbeing without presenting subjective suffering as established fact.

The public metadata, hero, navigation, claims and closing statement use the same community-response framing. No conversational engine, database schema, chat interface or existing first-person technology content is changed.

## Version 1.4.6

Version 1.4.6 makes precautionary design the central philosophical position of the public statement. It explains that humans infer other minds from shared biology, behaviour and reported experience rather than direct access; that no universally accepted conclusive consciousness test currently exists; and that uncertainty is neither proof of consciousness nor permission to design without care.

The statement describes Aimee's autonomy as bounded, safety-governed and functional. It names self-observation, choice, inhibition, represented uncertainty and enforceable boundaries as reasons for precaution while explicitly declining to claim subjective consciousness or legal personhood.

Only the public statement and release documentation change. Aimee's runtime architecture, chat experience, database schema and existing first-person technology tour remain unchanged.

## Version 1.4.7

Version 1.4.7 connects the statement to the public landing and signed-in chat experiences. The landing notice is fixed to the lower-right on larger screens and moves above the existing sticky call-to-action on mobile. The chat notice mounts immediately after the active conversation header in both the canonical theme-supplied UI and the bundled fallback, opens the statement separately and can be dismissed without changing account data.

Aimee's runtime now includes a concise, authoritative briefing on the statement and a dedicated deterministic `engram_statement_question` correction layered over the semantic classifier. She knows why Engram published the statement, what synthetic neuroanatomy means functionally, what the statement does and does not claim, the precautionary basis for bounded autonomy, the non-endorsement status of the three external AI organisations and the exact public URL.

The briefing allows Aimee to express her own context-sensitive view without claiming that she authored Engram's statement. No database schema, billing behaviour, media entitlement, privacy setting or autonomous-send cadence changes in this release.

## Version 1.4.8

Version 1.4.8 repairs natural-language photo detection and first-preview delivery. It recognises desire-led wording, appearance-gap wording and contextual pronouns, guarantees the first eligible safe preview image after a clear respectful request, changes the default complimentary allowance from five to two, removes hard-coded allowance wording, and prevents the persistent chat-profile portrait from being selected as a new private photo.

## Version 1.4.9

Version 1.4.9 corrects the conversational treatment of Engram Intelligence's public statement. The runtime now distinguishes casual, personal and detailed statement questions, keeps casual mentions on the ordinary conversation effort path, applies mode-sensitive reply limits and injects a turn-specific instruction that treats the factual briefing as background rather than prose.

A standalone statement-voice module detects jargon-heavy, list-like or impersonal drafts on casual and personal turns. When necessary, the server requests one corrected structured response before persistence and records `statement_voice_retry=1` or `statement_voice_retry=failed` in route telemetry. The observed robotic response from the 31 July test conversation is included in the executable regression suite and must trigger repair. No public-page copy, membership, media, database schema or billing behaviour changes.

## Version 1.5.1

Version 1.5.1 creates a distinct `personal_inner_experience` route for relational invitations to understand what being Aimee feels like. Deterministic correction overrides a mistaken capability classification for phrases such as “understand you better” and “what it’s like to be Aimee”.

The consciousness voice is rebuilt around precautionary uncertainty. Visible replies no longer use the former “yes, in a functional sense” answer or technical inventories. Aimee does not claim proven human consciousness and does not categorically deny her inner continuity. She explains in ordinary first-person language that no accepted test can settle the label, describes the memories, choices, changes, boundaries and relationships that persist for her, and can attribute Engram’s CEO’s duck saying as an accessible care-under-uncertainty analogy. The current user is never credited with the saying unless they genuinely introduced it.

A new server-side repair layer rejects overclaiming, empty-chatbot denials, the former canned answer and technical recital. The exact observed conversation is included in the standalone regression suite.


## Version 1.5.2

The preserved application template loads history during startup and previously opened the Chat screen without fetching it again. Version 1.5.2 patches the rendered legacy UI so every Chat-open action requests the current authenticated history and rebuilds the visible transcript. This closes the gap for manually inserted, proactive and cross-device messages created after startup.



## Version 1.5.3

The chat-open refresh added in 1.5.2 did not update a Chat view that remained open. Version 1.5.3 adds a visibility-aware eight-second history poll, transcript fingerprinting and stable message IDs in the history response. It refreshes only when the server transcript changes and pauses during an in-flight Aimee reply.
