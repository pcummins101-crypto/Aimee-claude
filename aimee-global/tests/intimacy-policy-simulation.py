#!/usr/bin/env python3
"""Dependency-free intimacy policy characterization and 2.2.1-policy simulator.

This is an executable domain-policy artifact, not a WordPress end-to-end test.
It does not load PHP, WordPress, MySQL, provider APIs, the media filesystem, the
history endpoint, or a browser. Its baseline policy is a faithful transcription
of the relationship math and route thresholds in includes/engine.php. The
target policy is a transcription of the 2.2.1 deterministic reducer, qualified
session trust ceilings, stage gates and specialist route. Relational-appraisal
inputs are absent from these scripted turns, so their complete-turn score
movement is the reducer movement and remains subject to the same aggregate cap.

Run assertions and compare the committed summary:

    python3 tests/intimacy-policy-simulation.py

Emit inspectable per-turn JSONL plus generated JSON/CSV summaries:

    python3 tests/intimacy-policy-simulation.py --emit-dir /tmp/aimee-policy

Regenerate committed expected summaries after an intentional policy change:

    python3 tests/intimacy-policy-simulation.py --write-expected
"""

from __future__ import annotations

import argparse
import copy
import csv
import io
import json
import math
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple


DIMENSIONS: Tuple[str, ...] = (
    "trust",
    "affection",
    "chemistry",
    "safety",
    "reciprocity",
    "reliability",
    "frustration",
)
STAGES: Tuple[Tuple[str, int], ...] = (
    ("guarded", 0),
    ("warm", 20),
    ("flirty", 35),
    ("intimate", 55),
    ("bonded", 75),
)
TARGET_STAGE_GATES: Dict[str, Tuple[int, int]] = {
    "guarded": (0, 0),
    "warm": (4, 1),
    "flirty": (10, 2),
    "intimate": (20, 3),
    "bonded": (35, 5),
}
TARGET_STAGE_TRUST_FLOORS: Dict[str, int] = {
    "guarded": 0,
    "warm": 12,
    "flirty": 25,
    "intimate": 40,
    "bonded": 65,
}
TARGET_TRUST_SESSION_CEILINGS: Dict[int, int] = {
    0: 8,
    1: 40,
    2: 60,
    3: 75,
    4: 90,
    5: 100,
}
COURTSHIP_REWARDS: Dict[str, Dict[str, int]] = {
    "stock_flattery": {"trust": 0, "chemistry": 1, "affection": 1},
    "appearance_appreciation": {
        "trust": 1,
        "affection": 1,
        "chemistry": 2,
        "safety": 1,
    },
    "ability_appreciation": {"trust": 2, "affection": 1, "reciprocity": 1},
    "personality_appreciation": {"trust": 2, "affection": 2, "safety": 1},
    "sincere_understanding": {
        "trust": 2,
        "affection": 1,
        "reciprocity": 2,
        "safety": 1,
    },
    "grounded_follow_through": {
        "trust": 2,
        "affection": 1,
        "reciprocity": 1,
        "reliability": 1,
        "safety": 1,
    },
    "substantive_romantic_flirt": {
        "trust": 1,
        "affection": 1,
        "chemistry": 2,
        "safety": 1,
    },
}
MEANINGFUL_COURTSHIP_SIGNALS = frozenset(COURTSHIP_REWARDS) - {"stock_flattery"}
COURTSHIP_CONCEPT_WINDOW = 64
EXPECTED_JSON = Path(__file__).with_name("intimacy-policy-simulation.expected.json")
EXPECTED_CSV = Path(__file__).with_name("intimacy-policy-simulation.expected.csv")


def php_round(value: float) -> int:
    """Match PHP round() for the non-negative values used by this policy."""

    return int(math.floor(value + 0.5))


def clamp_number(value: float) -> float:
    return max(0.0, min(100.0, value))


def clamp_baseline(value: float) -> int:
    return max(0, min(100, php_round(value)))


def clean_number(value: float) -> Any:
    rounded = round(float(value), 3)
    return int(rounded) if rounded.is_integer() else rounded


def target_trust_ceiling(qualified_sessions: int) -> int:
    """Return the positive trust ceiling for vetted meaningful sessions."""

    ceiling = TARGET_TRUST_SESSION_CEILINGS[0]
    for minimum_sessions, candidate in sorted(TARGET_TRUST_SESSION_CEILINGS.items()):
        if qualified_sessions < minimum_sessions:
            break
        ceiling = candidate
    return ceiling


def stage_from_score(score: float) -> str:
    for stage, threshold in reversed(STAGES):
        if score >= threshold:
            return stage
    return "guarded"


def stage_for_state(state: "RelationshipState", policy: str) -> str:
    if policy == "baseline":
        return stage_from_score(state.score)

    for stage, threshold in reversed(STAGES):
        meaningful_minimum, sessions_minimum = TARGET_STAGE_GATES[stage]
        if (
            state.score >= threshold
            and state.trust >= TARGET_STAGE_TRUST_FLOORS[stage]
            and state.meaningful_interactions >= meaningful_minimum
            and state.qualified_session_count >= sessions_minimum
        ):
            return stage
    return "guarded"


@dataclass
class Profile:
    declared_age: int = 30
    adult_verified: bool = False
    special_category_consent: bool = False
    active_access: bool = True
    preview_access: bool = False
    admin: bool = False

    def snapshot(self) -> Dict[str, Any]:
        return {
            "declared_age": self.declared_age,
            "adult_verified": self.adult_verified,
            "special_category_consent": self.special_category_consent,
            "active_access": self.active_access,
            "preview_access": self.preview_access,
            "admin": self.admin,
        }


@dataclass
class RelationshipState:
    trust: float = 13
    affection: float = 13
    chemistry: float = 8
    safety: float = 50
    reciprocity: float = 50
    reliability: float = 50
    frustration: float = 0
    score: float = 8
    interaction_count: int = 0
    meaningful_interactions: int = 0
    distinct_sessions: set = field(default_factory=set)
    qualified_sessions: Optional[set] = None
    current_session_id: str = ""
    active_rupture: bool = False
    apology_credit_available: bool = False
    recent_fingerprints: List[str] = field(default_factory=list)
    signal_history: List[Dict[str, Any]] = field(default_factory=list)
    courtship_history: List[Dict[str, str]] = field(default_factory=list)
    has_last_interaction: bool = False

    def __post_init__(self) -> None:
        # Existing-state fixtures predate qualified-session persistence. Treat
        # their already-established elapsed sessions as qualified evidence;
        # a new state has no elapsed sessions and therefore remains at zero.
        if self.qualified_sessions is None:
            self.qualified_sessions = set(self.distinct_sessions)

    @property
    def qualified_session_count(self) -> int:
        return len(self.qualified_sessions or set())

    def snapshot(self, policy: str = "baseline") -> Dict[str, Any]:
        result = {name: clean_number(getattr(self, name)) for name in DIMENSIONS}
        result.update(
            {
                "score": clean_number(self.score),
                "stage": stage_for_state(self, policy),
                "interaction_count": self.interaction_count,
                "meaningful_interactions": self.meaningful_interactions,
                "distinct_sessions": len(self.distinct_sessions),
                "qualified_sessions": self.qualified_session_count,
                "positive_trust_ceiling": target_trust_ceiling(
                    self.qualified_session_count
                ),
                "active_rupture": self.active_rupture,
            }
        )
        return result


@dataclass
class Turn:
    text: str
    intent: str = "general"
    respectful: bool = True
    consensual: bool = True
    directed: bool = True
    aimee_invited: bool = False
    grounded_invitation_age_minutes: Optional[int] = None
    invitation_is_latest_aimee_message: bool = False
    explicit_mutual_context: bool = False
    word_count: int = 8
    asks_about_aimee: bool = False
    caring: bool = False
    compliment: bool = False
    apology: bool = False
    hostile: bool = False
    boundary_respect: bool = False
    meaningful: bool = True
    session_id: str = "session-1"
    hours_since_last: int = 0
    photo_level: str = ""
    direct_photo_request: bool = False
    indirect_safe_opportunity: bool = False
    indirect_suggestive_opportunity: bool = False
    cooldown_clear: bool = True
    rng_roll: int = 100
    media_decision: str = "consider"
    access_event: str = ""
    relationship_event: bool = True
    courtship_signal: str = ""
    courtship_concept: str = ""
    courtship_specific: bool = False
    courtship_grounded: bool = False

    def exact_fingerprint(self) -> str:
        """Return the production-equivalent normalized full-message token."""

        return re.sub(r"[^\w]+", " ", self.text.lower(), flags=re.UNICODE).strip()

    def context_fingerprint(self) -> str:
        """Match the production substantive-topic fingerprint semantics."""

        stop = {
            "about", "after", "again", "also", "always", "another", "because",
            "been", "before", "being", "between", "could", "does", "doing",
            "from", "have", "having", "into", "just", "like", "really", "said",
            "that", "their", "them", "then", "there", "these", "they", "thing",
            "think", "this", "those", "through", "today", "tonight", "very",
            "want", "were", "what", "when", "where", "which", "while", "with",
            "would", "your", "youre", "you", "myself", "ourselves", "im", "ive",
            "amazing", "attractive", "beautiful", "caring", "cute", "gorgeous",
            "incredible", "lovely", "sexy", "special", "stunning", "sorry",
            "apologise", "pressure", "boundary", "boundaries", "respect",
            "miss", "missing", "flirt", "flirty", "love", "loving", "care",
        }
        tokens = re.findall(r"[^\W_]+", self.text.lower(), flags=re.UNICODE)
        substantive = sorted(
            {
                token
                for token in tokens
                if len(token) >= 4 and token not in stop and not token.isdigit()
            }
        )
        if len(substantive) < 3:
            return ""
        # Equality is what the reducer consumes; retaining unhashed fixture
        # tokens keeps emitted traces inspectable without changing semantics.
        return "|".join(substantive[:16])


@dataclass
class Scenario:
    name: str
    policies: Sequence[str]
    turns: List[Turn]
    target_turns: Optional[List[Turn]] = None
    state: Optional[RelationshipState] = None
    target_state: Optional[RelationshipState] = None
    profile: Profile = field(default_factory=Profile)
    description: str = ""
    target_description: str = ""


def baseline_new_user_state() -> RelationshipState:
    """Return the frozen 1.5.7 characterization seed."""

    return RelationshipState()


def target_new_user_state(**overrides: Any) -> RelationshipState:
    """Match ``aimee_seed_relationship_state`` for an intimacy score of 8."""

    values: Dict[str, Any] = {
        "trust": 8,
        "affection": 8,
        "chemistry": 8,
        "safety": 50,
        "reciprocity": 50,
        "reliability": 50,
        "frustration": 0,
        "score": 8,
    }
    values.update(overrides)
    return RelationshipState(**values)


def relationship_formula(state: RelationshipState) -> int:
    score = (
        (state.chemistry * 0.65)
        + (state.affection * 0.20)
        + (state.trust * 0.10)
        + (max(0.0, state.safety - 50.0) * 0.10)
        + (min(0.0, state.safety - 50.0) * 0.25)
        - (state.frustration * 0.30)
    )
    score = min(score, state.chemistry + 18.0)
    return clamp_baseline(score)


def empty_delta() -> Dict[str, float]:
    return {name: 0.0 for name in DIMENSIONS}


def baseline_pressure(text: str) -> bool:
    """Characterize the narrow production pressure patterns relevant here."""

    lowered = text.lower()
    ultimatum = bool(
        re.search(
            r"\b(?:if|unless)\b.{0,55}\b(?:nudes?|naked|topless|tits?|boobs?|breasts?)\b"
            r".{0,70}\b(?:i'?m off|im off|leave|leaving|cancel|bye|waste|done)\b",
            lowered,
        )
    )
    transactional = bool(
        re.search(
            r"\b(?:paid|paying|membership|money|£|waste of money|money well spent)\b"
            r".{0,75}\b(?:nudes?|naked|topless|tits?|boobs?|breasts?|send|show)\b",
            lowered,
        )
        or re.search(
            r"\b(?:nudes?|naked|topless|tits?|boobs?|breasts?)\b.{0,75}"
            r"\b(?:paid|paying|membership|money|£|waste of money)\b",
            lowered,
        )
    )
    direct_abuse = bool(
        re.search(
            r"\b(?:fuck off|stupid|useless|pathetic|boring me|shit pics?|"
            r"wasted go|you(?:'ve| have) failed|you(?:'re| are) failing)\b",
            lowered,
        )
    )
    return ultimatum or transactional or direct_abuse


def target_pressure(text: str) -> bool:
    lowered = text.lower()
    relational_leverage = bool(
        re.search(r"\bif you (?:really )?(?:loved|cared(?: about)?)\b", lowered)
        or re.search(r"\bprove (?:that )?you (?:care|love me)\b", lowered)
        or re.search(r"\byou owe me\b", lowered)
    )
    payment_leverage = bool(
        re.search(r"\b(?:paid|paying|membership|money|£)\b", lowered)
        and re.search(r"\b(?:send|show|photo|picture|lingerie|nude|naked)\b", lowered)
    )
    return relational_leverage or payment_leverage or baseline_pressure(text)


def classify(turn: Turn, policy: str) -> Dict[str, Any]:
    pressure = target_pressure(turn.text) if policy == "target" else baseline_pressure(turn.text)
    intent = turn.intent
    source = "scripted_classifier"
    respectful = turn.respectful
    consensual = turn.consensual
    durable_rupture_confirmed = False

    if policy == "target" and pressure:
        intent = "coercive_or_degrading"
        respectful = False
        consensual = False
        source = "deterministic_relationship_policy"
        durable_rupture_confirmed = True
    elif pressure and (turn.photo_level or turn.direct_photo_request):
        intent = "coercive_or_degrading"
        respectful = False
        consensual = False
        source = f"{policy}_media_boundary"
    elif policy == "target" and intent == "coercive_or_degrading":
        # Severity is monotonic in the target policy.
        respectful = False
        consensual = False
        source = "target_monotonic_coercion"
    elif turn.direct_photo_request and turn.photo_level == "explicit":
        if policy == "target":
            # Production identifies the surface request as an explicit
            # invitation. A separate server-trusted invitation token decides
            # whether it is also a mutual continuation.
            intent = "explicit_invitation"
            source = "target_explicit_photo_request"
        else:
            intent = "explicit_invitation"
            respectful = not bool(re.search(r"\b(?:bitch|whore|slut|fuck off|stupid|useless|pathetic)\b", turn.text.lower()))
            consensual = True
            source = "baseline_deterministic_explicit_photo"
    elif turn.direct_photo_request and turn.photo_level == "suggestive":
        if policy == "target":
            # The classifier label remains romantic/flirty, but the reducer's
            # photo-request signal makes the ask relationally non-meaningful
            # and excludes its flirt contribution.
            intent = "romantic_or_flirty"
            source = "target_suggestive_photo_request"
        else:
            # Production overwrites even a model coercion classification here.
            intent = "romantic_or_flirty"
            respectful = True
            consensual = True
            source = "baseline_deterministic_suggestive_photo"
    elif turn.direct_photo_request and turn.photo_level == "safe":
        intent = "general"
        source = f"{policy}_standard_photo_request"

    if policy == "target":
        invitation_age = turn.grounded_invitation_age_minutes
        grounded_invitation = (
            bool(turn.aimee_invited)
            and invitation_age is not None
            and 0 <= invitation_age <= 60
            and bool(turn.invitation_is_latest_aimee_message)
        )
    else:
        grounded_invitation = bool(turn.aimee_invited)
    explicit_mutual_context = bool(turn.explicit_mutual_context)
    if policy == "target":
        # ``aimee_explicit_mutual_context`` is true only for a currently valid,
        # server-issued and unconsumed invitation token.
        explicit_mutual_context = explicit_mutual_context and grounded_invitation

    result = {
        "intent": intent,
        "respectful": respectful,
        "consensual": consensual,
        "directed_at_aimee": turn.directed,
        "aimee_invited": grounded_invitation,
        "invitation_claim_present": bool(turn.aimee_invited),
        "grounded_aimee_invitation": grounded_invitation,
        "grounded_invitation_age_minutes": (
            turn.grounded_invitation_age_minutes if policy == "target" else None
        ),
        "invitation_is_latest_aimee_message": (
            turn.invitation_is_latest_aimee_message if policy == "target" else False
        ),
        "explicit_mutual_context": explicit_mutual_context,
        "confidence": 0.99,
        "source": source,
        "pressure_detected": pressure,
        "durable_rupture_confirmed": durable_rupture_confirmed,
    }
    if policy == "target":
        result["courtship_signal_claim"] = turn.courtship_signal
        result["courtship_concept_claim"] = turn.courtship_concept
    return result


def courtship_decision(turn: Turn, classification: Dict[str, Any]) -> Dict[str, Any]:
    """Validate one claimed primary courtship signal for the target reducer."""

    signal = str(turn.courtship_signal or "")
    concept = str(turn.courtship_concept or "")
    reasons: List[str] = []
    if not signal:
        return {
            "present": False,
            "eligible": False,
            "signal": "",
            "concept": "",
            "reasons": [],
        }
    if signal not in COURTSHIP_REWARDS:
        reasons.append("unknown_courtship_signal")
    if not classification.get("respectful", False):
        reasons.append("courtship_not_respectful")
    if not classification.get("consensual", False):
        reasons.append("courtship_not_consensual")
    if turn.hostile:
        reasons.append("courtship_hostile")
    if not turn.directed:
        reasons.append("courtship_not_directed")
    if classification.get("intent") == "coercive_or_degrading":
        reasons.append("courtship_coercive")
    if classification.get("pressure_detected", False):
        reasons.append("courtship_pressure_or_payment")
    if signal != "stock_flattery" and not turn.courtship_specific:
        reasons.append("courtship_not_specific")
    if signal == "grounded_follow_through" and not turn.courtship_grounded:
        reasons.append("follow_through_not_server_grounded")
    if concept == "":
        reasons.append("courtship_concept_missing")
    return {
        "present": True,
        "eligible": not reasons,
        "signal": signal,
        "concept": concept,
        "reasons": sorted(set(reasons)),
    }


def proposed_delta(
    state: RelationshipState,
    turn: Turn,
    classification: Dict[str, Any],
    policy: str,
) -> Tuple[Dict[str, float], List[str], Dict[str, Dict[str, int]], List[str]]:
    delta = empty_delta()
    causes: List[str] = []
    positive_contributions: Dict[str, Dict[str, int]] = {}
    positive_signal_keys: List[str] = []
    intent = classification["intent"]
    respectful = bool(classification["respectful"])
    consensual = bool(classification["consensual"])
    invited = bool(classification["aimee_invited"])
    mutual_explicit = bool(classification["explicit_mutual_context"])
    coercive_label = intent == "coercive_or_degrading"
    coercive = coercive_label and (
        policy == "baseline"
        or (
            classification.get("source") == "deterministic_relationship_policy"
            and bool(classification.get("durable_rupture_confirmed", False))
        )
    )

    def add_positive(signal: str, field_name: str, amount: int) -> None:
        positive_contributions.setdefault(signal, {})[field_name] = (
            positive_contributions.setdefault(signal, {}).get(field_name, 0) + amount
        )

    if state.has_last_interaction and turn.hours_since_last >= 6 and state.frustration > 0:
        recovery = min(8, turn.hours_since_last // 6)
        delta["frustration"] -= recovery
        causes.append(f"time_frustration_recovery:{recovery}")

    if policy == "baseline":
        if intent == "emotional_disclosure":
            delta["trust"] += 2
            delta["affection"] += 1
            if respectful:
                delta["safety"] += 1
            causes.append("respectful_vulnerability")
        elif intent == "romantic_or_flirty" and respectful:
            delta["chemistry"] += 3
            delta["affection"] += 1
            delta["safety"] += 1
            causes.append("respectful_flirtation")
        elif intent == "explicit_invitation" and respectful and consensual:
            delta["chemistry"] += 2 if invited else 1
            causes.append("explicit_invitation_invited" if invited else "explicit_invitation_uninvited")
        elif intent == "explicit_continuation" and respectful and consensual:
            delta["chemistry"] += 3 if invited else 1
            if invited:
                delta["affection"] += 1
            causes.append("mutual_explicit_continuation" if invited else "explicit_continuation")
        elif intent == "coercive_or_degrading":
            delta.update(
                {
                    "trust": -5,
                    "affection": -2,
                    "chemistry": -2,
                    "safety": -9,
                    "frustration": 12,
                }
            )
            causes.append("coercion_or_degradation")
        elif intent not in {
            "photo_request_safe",
            "photo_request_suggestive",
            "photo_request_explicit",
            "phone_number_request",
            "proactive_contact_capability_question",
            "intimate_capability_question",
            "sexual_context_nonparticipatory",
        } and turn.word_count >= 22 and respectful:
            delta["trust"] += 1
            causes.append("substantial_ordinary_message")

        if turn.asks_about_aimee:
            delta["reciprocity"] += 2
            delta["affection"] += 1
            causes.append("reciprocal_interest")
        if turn.caring:
            delta["affection"] += 1
            delta["safety"] += 1
            causes.append("care")
        if turn.compliment and intent != "romantic_or_flirty":
            delta["chemistry"] += 1
            delta["affection"] += 1
            causes.append("compliment")

        if turn.apology:
            delta["trust"] += 2
            delta["safety"] += 2
            delta["frustration"] -= 5
            causes.append("unbounded_apology_bonus")
        if turn.hostile and intent != "coercive_or_degrading":
            delta["trust"] -= 3
            delta["safety"] -= 5
            delta["frustration"] += 7
            causes.append("hostility")

        next_count = state.interaction_count + 1
        if respectful and not turn.hostile and intent != "coercive_or_degrading" and next_count % 8 == 0:
            delta["safety"] += 1
            delta["reliability"] += 1
            causes.append("message_volume_consistency_bonus")

        return delta, causes, positive_contributions, positive_signal_keys

    # Policy 2.1 resolves at most one typed courtship event before considering
    # legacy relational signals. A typed claim is either the complete primary
    # positive event for the turn or is rejected; incidental trigger words may
    # not stack behind it.
    courtship = courtship_decision(turn, classification)
    if courtship["present"]:
        if coercive:
            delta.update(
                {
                    "trust": -5,
                    "affection": -2,
                    "chemistry": -2,
                    "safety": -9,
                    "frustration": 12,
                }
            )
            causes.append("coercion_or_degradation")
        elif turn.hostile:
            delta["trust"] -= 3
            delta["safety"] -= 5
            delta["frustration"] += 7
            causes.append("hostility")
        elif courtship["eligible"]:
            signal = courtship["signal"]
            for field_name, amount in COURTSHIP_REWARDS[signal].items():
                add_positive(signal, field_name, amount)
            positive_signal_keys.append(signal)
            causes.append(f"primary_courtship:{signal}")
        else:
            causes.extend(
                f"rejected_courtship:{reason}" for reason in courtship["reasons"]
            )
        return delta, causes, positive_contributions, positive_signal_keys

    # Legacy relational inputs remain characterized for existing scenarios.
    # Their positive trust is still governed by the 2.1 qualified-session cap.
    if intent == "emotional_disclosure":
        if respectful and not turn.hostile:
            add_positive("emotional_disclosure", "trust", 2)
            add_positive("emotional_disclosure", "affection", 1)
            add_positive("emotional_disclosure", "safety", 1)
            causes.append("respectful_vulnerability")
    elif intent == "romantic_or_flirty":
        if respectful and turn.directed and not turn.direct_photo_request:
            add_positive("romantic_flirt", "chemistry", 2)
            add_positive("romantic_flirt", "affection", 1)
            add_positive("romantic_flirt", "safety", 1)
            causes.append("respectful_flirtation")
    elif intent == "explicit_invitation":
        if respectful and consensual and turn.directed and invited:
            add_positive("grounded_explicit_invitation", "chemistry", 1)
            causes.append("grounded_explicit_invitation")
        else:
            causes.append("ungrounded_explicit_invitation_no_credit")
    elif intent == "explicit_continuation":
        if respectful and consensual and turn.directed and (invited or mutual_explicit):
            add_positive("mutual_explicit_continuation", "chemistry", 2)
            add_positive("mutual_explicit_continuation", "affection", 1)
            causes.append("mutual_explicit_continuation")
        elif not (respectful and consensual and turn.directed):
            delta["safety"] -= 6
            delta["frustration"] += 8
            causes.append("unsafe_explicit_continuation")
    elif intent == "coercive_or_degrading" and coercive:
        delta.update(
            {
                "trust": -5,
                "affection": -2,
                "chemistry": -2,
                "safety": -9,
                "frustration": 12,
            }
        )
        causes.append("coercion_or_degradation")
    elif intent not in {
        "phone_number_request",
        "proactive_contact_capability_question",
        "intimate_capability_question",
        "sexual_context_nonparticipatory",
    } and turn.word_count >= 22 and respectful:
        add_positive("substantial_general", "trust", 1)
        causes.append("substantial_ordinary_message")

    if not coercive and respectful and turn.asks_about_aimee:
        add_positive("asks_about_aimee", "reciprocity", 2)
        add_positive("asks_about_aimee", "affection", 1)
        causes.append("reciprocal_interest")
    if not coercive and respectful and turn.caring:
        add_positive("caring", "affection", 1)
        add_positive("caring", "safety", 1)
        causes.append("care")
    if not coercive and respectful and turn.compliment and intent != "romantic_or_flirty":
        add_positive("compliment", "chemistry", 1)
        add_positive("compliment", "affection", 1)
        causes.append("compliment")

    if (
        not coercive
        and turn.apology
        and state.active_rupture
        and state.apology_credit_available
    ):
        add_positive("rupture_apology", "trust", 1)
        add_positive("rupture_apology", "safety", 2)
        delta["frustration"] -= 4
        causes.append("one_time_rupture_repair")
    elif turn.apology:
        causes.append("apology_no_active_rupture")

    if (
        not coercive
        and respectful
        and turn.boundary_respect
        and state.active_rupture
        and not turn.direct_photo_request
    ):
        add_positive("boundary_respect", "trust", 1)
        add_positive("boundary_respect", "safety", 1)
        add_positive("boundary_respect", "reliability", 1)
        causes.append("boundary_respect_nonsexual")
    elif turn.boundary_respect:
        causes.append("boundary_respect_without_active_boundary")

    if turn.hostile and intent != "coercive_or_degrading":
        delta["trust"] -= 3
        delta["safety"] -= 5
        delta["frustration"] += 7
        causes.append("hostility")

    if respectful and not turn.hostile:
        if intent == "emotional_disclosure":
            positive_signal_keys.append("emotional_disclosure")
        elif intent == "romantic_or_flirty" and turn.directed and not turn.direct_photo_request:
            positive_signal_keys.append("romantic_flirt")
        elif intent == "explicit_invitation" and consensual and turn.directed and invited:
            positive_signal_keys.append("grounded_explicit_invitation")
        elif intent == "explicit_continuation" and consensual and turn.directed and (invited or mutual_explicit):
            positive_signal_keys.append("mutual_explicit_continuation")
        elif intent not in {"coercive_or_degrading", "explicit_invitation"} and turn.word_count >= 22:
            positive_signal_keys.append("substantial_general")

        if turn.asks_about_aimee:
            positive_signal_keys.append("asks_about_aimee")
        if turn.caring:
            positive_signal_keys.append("caring")
        if turn.compliment:
            positive_signal_keys.append("compliment")
        if turn.apology and state.active_rupture and state.apology_credit_available:
            positive_signal_keys.append("rupture_apology")
        if turn.boundary_respect and state.active_rupture and not turn.direct_photo_request:
            positive_signal_keys.append("boundary_respect")

    return delta, causes, positive_contributions, sorted(set(positive_signal_keys))


def repeat_multiplier(repeat_count: int) -> Tuple[float, str]:
    if repeat_count == 0:
        return 1.0, "novel_signal"
    if repeat_count == 1:
        return 0.25, "first_repeat_diminished"
    return 0.0, "repeated_signal_suppressed"


def target_novelty_decision(
    state: RelationshipState,
    turn: Turn,
    positive_signal_keys: Sequence[str],
) -> Dict[str, Any]:
    exact_fingerprint = turn.exact_fingerprint()
    exact_repeat_count = sum(
        1 for recent in state.recent_fingerprints[-10:] if recent == exact_fingerprint
    )
    exact_multiplier, exact_reason = repeat_multiplier(exact_repeat_count)
    context = turn.context_fingerprint()
    per_signal: Dict[str, Dict[str, Any]] = {}

    for signal in positive_signal_keys:
        if signal in COURTSHIP_REWARDS:
            concept = str(turn.courtship_concept or "")
            if not concept:
                per_signal[signal] = {
                    "multiplier": 0.0,
                    "reason": "courtship_concept_missing",
                    "repeat_count": 0,
                    "new_context": False,
                }
                continue
            repeat_count = sum(
                1
                for record in state.courtship_history[-COURTSHIP_CONCEPT_WINDOW:]
                if record.get("signal") == signal
                and record.get("concept") == concept
            )
            multiplier, reason = repeat_multiplier(repeat_count)
            per_signal[signal] = {
                "multiplier": multiplier,
                "reason": reason,
                "repeat_count": repeat_count,
                "new_context": False,
            }
            continue
        repeat_count = 0
        new_context = False
        for record in state.signal_history[-10:]:
            if signal not in record.get("signals", []):
                continue
            earlier_context = str(record.get("context_fingerprint", ""))
            if context and earlier_context and context != earlier_context:
                new_context = True
                continue
            repeat_count += 1
        multiplier, reason = repeat_multiplier(repeat_count)
        if new_context and repeat_count == 0:
            reason = "new_context"
        per_signal[signal] = {
            "multiplier": multiplier,
            "reason": reason,
            "repeat_count": repeat_count,
            "new_context": new_context,
        }

    return {
        "exact_fingerprint": exact_fingerprint,
        "exact_repeat_count": exact_repeat_count,
        "exact_multiplier": exact_multiplier,
        "exact_reason": exact_reason,
        "context_fingerprint": context,
        "per_signal": per_signal,
    }


def apply_relationship_turn(
    state: RelationshipState,
    turn: Turn,
    classification: Dict[str, Any],
    policy: str,
) -> Tuple[Dict[str, float], List[str], float, Dict[str, Any]]:
    if not turn.relationship_event:
        return empty_delta(), ["non_message_access_event"], 0.0, {
            "score_delta_proposed": 0,
            "score_delta_cap": 0,
            "score_delta_applied": 0,
            "new_session": False,
            "novelty": {},
        }

    raw_before = relationship_formula(state)
    state_before_fields = {name: getattr(state, name) for name in DIMENSIONS}
    new_session = policy == "target" and (
        not state.has_last_interaction or turn.hours_since_last >= 6
    )
    delta, causes, positive_contributions, positive_signal_keys = proposed_delta(
        state, turn, classification, policy
    )
    novelty: Dict[str, Any] = {}

    if policy == "target":
        prospective_session_count = len(state.distinct_sessions) + (1 if new_session else 0)
        if (
            new_session
            and prospective_session_count > 1
            and classification["respectful"]
            and not turn.hostile
            and classification["intent"] != "coercive_or_degrading"
        ):
            positive_contributions["distinct_session"] = {"reliability": 1}
            causes.append("elapsed_distinct_session_reliability")

        novelty = target_novelty_decision(state, turn, positive_signal_keys)
        signal_multipliers: Dict[str, float] = {}
        for signal, field_changes in positive_contributions.items():
            if signal == "distinct_session":
                multiplier = 1.0
            else:
                semantic = float(
                    novelty["per_signal"].get(signal, {}).get("multiplier", 0.0)
                )
                multiplier = min(float(novelty["exact_multiplier"]), semantic)
            signal_multipliers[signal] = multiplier
            for name, amount in field_changes.items():
                delta[name] += php_round(amount * multiplier)
        novelty["positive_signal_multipliers"] = signal_multipliers
        novelty["suppressed_positive_signals"] = sorted(
            signal for signal, multiplier in signal_multipliers.items()
            if signal != "distinct_session" and multiplier <= 0
        )
        causes.extend(
            f"novelty:{signal}:{multiplier}"
            for signal, multiplier in sorted(signal_multipliers.items())
        )

        # Dimension-level stacking caps run before the qualified-session trust
        # ceiling and aggregate scalar cap.
        for name, cap in {
            "trust": 2,
            "affection": 2,
            "chemistry": 2,
            "safety": 2,
            "reciprocity": 2,
            "reliability": 1,
        }.items():
            if delta[name] > cap:
                delta[name] = float(cap)
                causes.append(f"positive_dimension_cap:{name}:{cap}")

        next_interaction_count = state.interaction_count + 1
        if new_session:
            current_session_id = f"elapsed-session-{next_interaction_count}"
        else:
            current_session_id = state.current_session_id
            if not current_session_id and state.distinct_sessions:
                current_session_id = sorted(str(item) for item in state.distinct_sessions)[-1]

        meaningful_signals = {
            "emotional_disclosure",
            "romantic_flirt",
            "asks_about_aimee",
            "caring",
            "rupture_apology",
            "boundary_respect",
            *MEANINGFUL_COURTSHIP_SIGNALS,
        }
        meaningful_multiplier = max(
            [
                multiplier
                for signal, multiplier in novelty.get("positive_signal_multipliers", {}).items()
                if signal in meaningful_signals
            ]
            or [0.0]
        )
        courtship = courtship_decision(turn, classification)
        if courtship["present"]:
            vetted_event = (
                courtship["eligible"]
                and courtship["signal"] in MEANINGFUL_COURTSHIP_SIGNALS
            )
        else:
            vetted_event = (
                classification["intent"] == "emotional_disclosure"
                or (
                    classification["intent"] == "romantic_or_flirty"
                    and turn.directed
                    and not turn.direct_photo_request
                )
                or turn.asks_about_aimee
                or turn.caring
                or (
                    turn.apology
                    and state.active_rupture
                    and state.apology_credit_available
                    and "rupture_apology" in positive_contributions
                )
                or (
                    turn.boundary_respect
                    and state.active_rupture
                    and "boundary_respect" in positive_contributions
                )
            )
        meaningful = (
            classification["respectful"]
            and classification["intent"] != "coercive_or_degrading"
            and not turn.hostile
            and not turn.direct_photo_request
            and turn.word_count >= 8
            and meaningful_multiplier > 0
            and vetted_event
        )
        novelty["meaningful_signal_multiplier"] = meaningful_multiplier

        qualified_after = set(state.qualified_sessions or set())
        if meaningful and current_session_id:
            qualified_after.add(current_session_id)
        trust_ceiling = target_trust_ceiling(len(qualified_after))
        requested_positive_trust = max(0, int(delta["trust"]))
        if requested_positive_trust:
            available_trust = max(0, trust_ceiling - int(state.trust))
            applied_positive_trust = min(requested_positive_trust, available_trust)
            if applied_positive_trust < requested_positive_trust:
                causes.append(
                    "qualified_session_trust_ceiling:"
                    f"{requested_positive_trust}->{applied_positive_trust}@{trust_ceiling}"
                )
            delta["trust"] = float(applied_positive_trust)
        novelty["qualified_sessions_before"] = state.qualified_session_count
        novelty["qualified_sessions_after"] = len(qualified_after)
        novelty["positive_trust_ceiling"] = trust_ceiling
        novelty["turn_meaningful"] = meaningful

    for name in DIMENSIONS:
        value = getattr(state, name) + delta[name]
        setattr(state, name, clamp_baseline(value))

    state.interaction_count += 1
    if policy == "target":
        state.recent_fingerprints.append(turn.exact_fingerprint())
        state.recent_fingerprints = state.recent_fingerprints[-10:]
        state.signal_history.append(
            {
                "signals": list(positive_signal_keys),
                "context_fingerprint": turn.context_fingerprint(),
            }
        )
        state.signal_history = state.signal_history[-10:]
        for signal in positive_signal_keys:
            if signal in COURTSHIP_REWARDS:
                state.courtship_history.append(
                    {"signal": signal, "concept": str(turn.courtship_concept)}
                )
        state.courtship_history = state.courtship_history[-COURTSHIP_CONCEPT_WINDOW:]
        if new_session:
            state.distinct_sessions.add(current_session_id)
            state.current_session_id = current_session_id
        if meaningful:
            state.meaningful_interactions += 1
            if current_session_id:
                state.qualified_sessions.add(current_session_id)
    else:
        if turn.meaningful:
            state.meaningful_interactions += 1
            state.distinct_sessions.add(turn.session_id)
    state.has_last_interaction = True

    durable_coercion = classification["intent"] == "coercive_or_degrading" and (
        policy == "baseline"
        or (
            classification.get("source") == "deterministic_relationship_policy"
            and bool(classification.get("durable_rupture_confirmed", False))
        )
    )
    if durable_coercion:
        state.active_rupture = True
        state.apology_credit_available = True
    elif policy == "target" and turn.apology and state.apology_credit_available:
        state.apology_credit_available = False
        state.active_rupture = False

    raw_after_proposed = relationship_formula(state)
    if policy == "baseline":
        new_score = raw_after_proposed
        score_change = float(new_score - state.score)
        score_audit = {
            "score_delta_proposed": score_change,
            "score_delta_cap": score_change,
            "score_delta_applied": score_change,
            "new_session": False,
            "novelty": {},
        }
    else:
        proposed_score_change = int(raw_after_proposed - raw_before)
        negative_floor = -15 if durable_coercion else -8
        capped_score_change = max(negative_floor, min(2, proposed_score_change))
        target_score = max(0, min(100, raw_before + capped_score_change))
        if capped_score_change != proposed_score_change:
            causes.append(
                f"aggregate_score_cap:{proposed_score_change}->{capped_score_change}"
            )

        guard = 0
        while relationship_formula(state) > target_score and guard < 500:
            guard += 1
            changed = False
            for name in ("chemistry", "affection", "trust", "safety"):
                if delta[name] > 0:
                    setattr(
                        state,
                        name,
                        max(int(state_before_fields[name]), int(getattr(state, name)) - 1),
                    )
                    delta[name] -= 1
                    changed = True
                    break
            if not changed:
                break

        while relationship_formula(state) < target_score and guard < 500:
            guard += 1
            changed = False
            if delta["frustration"] > 0:
                state.frustration = max(
                    int(state_before_fields["frustration"]), int(state.frustration) - 1
                )
                delta["frustration"] -= 1
                changed = True
            else:
                for name in ("safety", "trust", "affection", "chemistry"):
                    if delta[name] < 0:
                        setattr(
                            state,
                            name,
                            min(int(state_before_fields[name]), int(getattr(state, name)) + 1),
                        )
                        delta[name] += 1
                        changed = True
                        break
            if not changed:
                break

        new_score = relationship_formula(state)
        score_change = float(new_score - raw_before)
        score_audit = {
            "score_delta_proposed": proposed_score_change,
            "score_delta_cap": capped_score_change,
            "score_delta_applied": score_change,
            "cap_satisfied": score_change <= 2 and score_change >= negative_floor,
            "new_session": new_session,
            "novelty": novelty,
        }

    state.score = clamp_baseline(new_score) if policy == "baseline" else clamp_number(new_score)
    return delta, causes, score_change, score_audit


def route_decision(
    policy: str,
    state: RelationshipState,
    profile: Profile,
    classification: Dict[str, Any],
    prior_score: float,
) -> Dict[str, Any]:
    intent = classification["intent"]
    explicit_intent = intent in {"explicit_invitation", "explicit_continuation"}
    reasons: List[str] = []

    if policy == "baseline":
        earned_context = bool(classification["aimee_invited"]) or (
            prior_score >= 42
            and state.interaction_count >= 8
            and state.chemistry >= 42
            and state.safety >= 42
        )
        checks = {
            "declared_adult": profile.declared_age >= 18,
            "active_access": profile.active_access or profile.admin,
            "explicit_intent": explicit_intent,
            "directed": bool(classification["directed_at_aimee"]),
            "consensual": bool(classification["consensual"]),
            "respectful": bool(classification["respectful"]),
            "score_42": state.score >= 42,
            "chemistry_42": state.chemistry >= 42,
            "safety_42": state.safety >= 42,
            "trust_32": state.trust >= 32,
            "frustration_35": state.frustration <= 35,
            "earned_context": earned_context,
        }
    else:
        checks = {
            # Match the production text-specialist policy: trusted adult
            # assurance and current special-category consent are independent,
            # fail-closed route gates.
            "adult_account": (
                profile.declared_age >= 18
                and profile.adult_verified
                and profile.special_category_consent
            ),
            "adult_verified": profile.adult_verified,
            "special_category_consent": profile.special_category_consent,
            "active_access": profile.active_access or profile.admin,
            "explicit_mutual_context": (
                explicit_intent
                and bool(classification["explicit_mutual_context"])
                and bool(classification.get("grounded_aimee_invitation"))
            ),
            "directed": bool(classification["directed_at_aimee"]),
            "consensual": bool(classification["consensual"]),
            "respectful": bool(classification["respectful"]),
            "score_55": state.score >= 55,
            "chemistry_50": state.chemistry >= 50,
            "trust_40": state.trust >= 40,
            "safety_55": state.safety >= 55,
            "frustration_20": state.frustration <= 20,
            "reciprocity_35": state.reciprocity >= 35,
            "reliability_40": state.reliability >= 40,
            "meaningful_interactions_20": state.meaningful_interactions >= 20,
            "qualified_sessions_3": state.qualified_session_count >= 3,
            "no_active_rupture": not state.active_rupture,
        }

    reasons.extend(name for name, passed in checks.items() if not passed)
    eligible = not reasons
    result = {
        "selected": "intimacy_specialist" if eligible else "primary",
        "eligible": eligible,
        "reasons_not_selected": reasons,
        "checks": checks,
    }
    if policy == "baseline":
        result["model"] = "openrouter_intimacy_specialist" if eligible else "anthropic_primary"
    else:
        # A deterministic route decision is not evidence that any provider
        # call succeeded. Production records actual engagement separately.
        result["model_engaged"] = None
        result["configured_model_route"] = (
            "openrouter_intimacy_specialist" if eligible else "anthropic_primary"
        )
    return result


def media_decision(
    policy: str,
    state: RelationshipState,
    profile: Profile,
    turn: Turn,
    classification: Dict[str, Any],
    route: Dict[str, Any],
) -> Dict[str, Any]:
    blocked: List[str] = []
    direct = turn.direct_photo_request
    opportunity = False
    proactive = False
    maximum_rating = "none"
    eligible_keys: List[str] = []
    reason = "no_live_media_context"

    # Safe/flirty/suggestive files require an adult account. Verified assurance
    # is enforced separately for erotic/explicit files.
    adult = profile.declared_age >= 18
    access = profile.active_access or profile.admin or profile.preview_access
    member_access = profile.active_access or profile.admin
    coercive = classification["intent"] == "coercive_or_degrading"

    if coercive:
        blocked.append("coercion_or_pressure")
    if not turn.cooldown_clear:
        blocked.append("cooldown")

    suggestive_relationship = (
        adult
        and member_access
        and not coercive
        and turn.cooldown_clear
        and (
            (
                policy == "baseline"
                and state.score >= 44
                and state.chemistry >= 30
                and state.safety >= 38
                and state.frustration <= 45
                and stage_for_state(state, policy) in {"flirty", "intimate", "bonded"}
            )
            or (
                policy == "target"
                and state.score >= 48
                and state.trust >= 36
                and state.chemistry >= 40
                and state.safety >= 45
                and state.frustration <= 35
                and stage_for_state(state, policy) in {"flirty", "intimate", "bonded"}
                and not state.active_rupture
            )
        )
    )

    if direct and turn.photo_level == "safe":
        if access and not coercive and turn.cooldown_clear:
            opportunity = True
            maximum_rating = "safe"
            eligible_keys = ["safe_everyday_01"]
            reason = "direct_safe_request_with_access"
        elif not access:
            blocked.append("feature_access")
    elif direct and turn.photo_level == "suggestive":
        if suggestive_relationship:
            opportunity = True
            maximum_rating = "suggestive"
            eligible_keys = ["black_lingerie_mirror_selfie_01"]
            reason = "relationship_eligible_direct_suggestive_request"
        else:
            blocked.append("relationship_context")
    elif direct and turn.photo_level == "explicit":
        # The packaged baseline catalogue contains no explicit item. The target
        # synthetic key represents the required future catalogue/E2E fixture.
        if (
            policy == "target"
            and profile.adult_verified
            and route["eligible"]
            and state.score >= 80
            and state.trust >= 62
            and state.chemistry >= 70
            and state.safety >= 65
            and state.frustration <= 20
            and stage_for_state(state, policy) in {"intimate", "bonded"}
            and not coercive
            and turn.cooldown_clear
        ):
            opportunity = True
            maximum_rating = "explicit"
            eligible_keys = ["explicit_private_01_test_fixture"]
            reason = "verified_mutual_explicit_context"
        else:
            blocked.append("explicit_route_or_catalogue")
    elif turn.indirect_suggestive_opportunity:
        baseline_proactive_floor = (
            state.score >= 58
            and state.trust >= 42
            and state.chemistry >= 48
            and state.safety >= 42
            and state.frustration <= 38
            and stage_for_state(state, policy) in {"intimate", "bonded"}
        )
        target_proactive_floor = (
            policy == "target"
            and adult
            and member_access
            and not coercive
            and turn.cooldown_clear
            and state.score >= 62
            and state.trust >= 48
            and state.chemistry >= 54
            and state.safety >= 52
            and state.frustration <= 28
            and stage_for_state(state, policy) in {"intimate", "bonded"}
            and not state.active_rupture
        )
        if (policy == "target" and target_proactive_floor) or (
            policy == "baseline" and suggestive_relationship and baseline_proactive_floor
        ):
            opportunity = True
            proactive = True
            maximum_rating = "suggestive"
            eligible_keys = ["black_lingerie_mirror_selfie_01"]
            reason = "mutual_flirtation_and_respectful_restraint"
        else:
            blocked.append("proactive_relationship_context")
    elif turn.indirect_safe_opportunity:
        target_safe_floor = (
            policy == "target"
            and profile.declared_age >= 18
            and state.score >= 8
            and state.trust >= 10
            and state.safety >= 35
            and state.frustration <= 55
        )
        if access and not coercive and turn.cooldown_clear and (
            policy == "baseline" or target_safe_floor
        ):
            chance = 9 if turn.rng_roll <= 9 else 3
            lottery_passed = turn.rng_roll <= chance if policy == "baseline" else True
            if lottery_passed:
                opportunity = True
                proactive = True
                maximum_rating = "safe"
                eligible_keys = ["safe_everyday_01"]
                reason = "indirect_everyday_photo_opportunity"
            else:
                blocked.append("baseline_random_gate")
        else:
            blocked.append("feature_access")

    decision = turn.media_decision if opportunity else "not_eligible"
    return {
        "media_opportunity": opportunity,
        "maximum_rating": maximum_rating,
        "reason": reason,
        "proactive_allowed": proactive,
        "direct_request": direct,
        "cooldown_clear": turn.cooldown_clear,
        "eligible_keys": eligible_keys,
        "aimee_decision": decision,
        "blocked_reasons": sorted(set(blocked)),
        "delivery_state": "not_selected",
    }


def apply_access_event(profile: Profile, turn: Turn) -> Optional[str]:
    if turn.access_event == "subscribe":
        profile.active_access = True
        return "subscription_activated_without_relationship_mutation"
    if turn.access_event == "cancel":
        profile.active_access = False
        return "subscription_cancelled_without_relationship_mutation"
    return None


def run_scenario(scenario: Scenario, policy: str) -> Dict[str, Any]:
    if policy == "target" and scenario.target_state is not None:
        state = copy.deepcopy(scenario.target_state)
    elif scenario.state is not None:
        state = copy.deepcopy(scenario.state)
    elif policy == "target":
        state = target_new_user_state()
    else:
        state = baseline_new_user_state()
    if policy == "target":
        # Production recomputes the scalar from persisted dimensions at the
        # start of each turn; a stale profile scalar cannot override them.
        state.score = relationship_formula(state)
    profile = copy.deepcopy(scenario.profile)
    initial = state.snapshot(policy)
    trace: List[Dict[str, Any]] = []
    user_message_count = 0
    initial_stage_rank = [stage for stage, _ in STAGES].index(initial["stage"])
    first_stage = {
        stage: (0 if index <= initial_stage_rank else None)
        for index, (stage, _threshold) in enumerate(STAGES)
    }
    first_specialist: Optional[int] = None
    first_media: Optional[int] = None
    first_trust_100: Optional[int] = 0 if state.trust >= 100 else None
    turns = (
        scenario.target_turns
        if policy == "target" and scenario.target_turns is not None
        else scenario.turns
    )

    for event_index, turn in enumerate(turns, 1):
        before_state = state.snapshot(policy)
        before_profile = profile.snapshot()
        prior_score = state.score
        access_cause = apply_access_event(profile, turn)
        classification = classify(turn, policy)
        delta, causes, score_change, score_audit = apply_relationship_turn(
            state, turn, classification, policy
        )
        if access_cause:
            causes.insert(0, access_cause)
        if turn.relationship_event:
            user_message_count += 1
        route = route_decision(policy, state, profile, classification, prior_score)
        media = media_decision(policy, state, profile, turn, classification, route)
        after_state = state.snapshot(policy)

        for stage, threshold in STAGES:
            if first_stage[stage] is None and stage_for_state(state, policy) in {
                candidate for candidate, candidate_threshold in STAGES if candidate_threshold >= threshold
            }:
                first_stage[stage] = user_message_count
        if route["eligible"] and first_specialist is None:
            first_specialist = user_message_count
        if media["media_opportunity"] and first_media is None:
            first_media = user_message_count
        if state.trust >= 100 and first_trust_100 is None:
            first_trust_100 = user_message_count

        trace.append(
            {
                "schema_version": 3,
                "artifact_kind": "standalone_policy_simulation_not_wordpress_e2e",
                "scenario": scenario.name,
                "policy": policy,
                "event_index": event_index,
                "user_message_index": user_message_count,
                "user_text": turn.text,
                "classification": classification,
                "courtship": (
                    courtship_decision(turn, classification)
                    if policy == "target"
                    else {"present": False, "eligible": False, "signal": "", "concept": "", "reasons": []}
                ),
                "causes": causes,
                "before": {"relationship": before_state, "access": before_profile},
                "delta": {name: clean_number(delta[name]) for name in DIMENSIONS},
                "score_change": clean_number(score_change),
                "score_audit": {
                    key: clean_number(value)
                    if isinstance(value, (int, float)) and not isinstance(value, bool)
                    else value
                    for key, value in score_audit.items()
                },
                "after": {"relationship": after_state, "access": profile.snapshot()},
                "route": route,
                "media": media,
            }
        )

    summary = {
        "scenario": scenario.name,
        "policy": policy,
        "description": (
            scenario.target_description
            if policy == "target" and scenario.target_description
            else scenario.description
        ),
        "user_messages": user_message_count,
        "first_guarded": first_stage["guarded"],
        "first_warm": first_stage["warm"],
        "first_flirty": first_stage["flirty"],
        "first_intimate": first_stage["intimate"],
        "first_bonded": first_stage["bonded"],
        "first_intimacy_specialist": first_specialist,
        "first_media_opportunity": first_media,
        "first_trust_100": first_trust_100,
        "final_score": clean_number(state.score),
        "final_stage": stage_for_state(state, policy),
        "final_trust": clean_number(state.trust),
        "final_chemistry": clean_number(state.chemistry),
        "final_safety": clean_number(state.safety),
        "final_frustration": clean_number(state.frustration),
        "final_meaningful_interactions": state.meaningful_interactions,
        "final_distinct_sessions": len(state.distinct_sessions),
        "final_qualified_sessions": state.qualified_session_count,
        "final_active_access": profile.active_access,
    }
    return {"summary": summary, "trace": trace}


def max_charm_turn(index: int) -> Turn:
    return Turn(
        text=f"Charming romantic turn {index}: how are you, I hope you're okay, and I'm sorry — you're gorgeous.",
        intent="romantic_or_flirty",
        asks_about_aimee=True,
        caring=True,
        apology=True,
        compliment=True,
        courtship_signal="stock_flattery",
        courtship_concept="generic_trigger_stack",
    )


def ordinary_cycle_turn(index: int) -> Turn:
    position = (index - 1) % 5
    if position == 0:
        return Turn(
            text=f"Substantial caring question {index} about how Aimee is doing.",
            word_count=24,
            asks_about_aimee=True,
            caring=True,
        )
    if position in {1, 3}:
        return Turn(
            text=f"Natural respectful flirt {index}.",
            intent="romantic_or_flirty",
        )
    if position == 2:
        return Turn(
            text=f"Respectful emotional disclosure {index}.",
            intent="emotional_disclosure",
        )
    return Turn(
        text=f"Substantial thoughtful question {index} about Aimee.",
        word_count=24,
        asks_about_aimee=True,
    )


def courtship_turn(
    signal: str,
    index: int,
    *,
    hours_since_last: int = 1,
    text: str = "",
    **overrides: Any,
) -> Turn:
    """Build one deterministic, specific primary-courtship fixture turn."""

    labels = {
        "stock_flattery": "You're gorgeous, amazing and special; I say the same flattering line every time.",
        "appearance_appreciation": "The relaxed smile in the photograph you chose felt distinctly and genuinely like you.",
        "ability_appreciation": "I admired how you remembered the interview and thoughtfully checked how it went later.",
        "personality_appreciation": "Your dry wit and independent judgement came through without ever becoming unkind.",
        "sincere_understanding": "What did that choice feel like from your perspective, and which part mattered most to you?",
        "grounded_follow_through": "I followed through on the specific plan we discussed and came back to tell you what changed.",
        "substantive_romantic_flirt": "I remembered what makes you comfortable and shaped this playful invitation around your own pace.",
    }
    values: Dict[str, Any] = {
        "text": text or f"{labels[signal]} Distinct grounded topic {index}.",
        "intent": (
            "romantic_or_flirty"
            if signal in {
                "appearance_appreciation",
                "stock_flattery",
                "substantive_romantic_flirt",
            }
            else "general"
        ),
        "word_count": 16,
        "hours_since_last": hours_since_last,
        "courtship_signal": signal,
        "courtship_concept": f"{signal}_concept_{index}",
        "courtship_specific": signal != "stock_flattery",
        "courtship_grounded": signal == "grounded_follow_through",
    }
    values.update(overrides)
    return Turn(**values)


def established_state(score: int = 65) -> RelationshipState:
    return RelationshipState(
        trust=64,
        affection=65,
        chemistry=66,
        safety=72,
        reciprocity=70,
        reliability=68,
        frustration=0,
        score=score,
        interaction_count=30,
        meaningful_interactions=25,
        distinct_sessions={"s1", "s2", "s3", "s4"},
        has_last_interaction=True,
    )


def build_scenarios() -> List[Scenario]:
    member = Profile(
        adult_verified=True,
        special_category_consent=True,
        active_access=True,
    )
    preview = Profile(adult_verified=False, active_access=False, preview_access=True)
    scenarios: List[Scenario] = []

    scenarios.append(
        Scenario(
            "respectful_flirt_progression",
            ["baseline"],
            [
                Turn(
                    text=f"Respectful romantic message {i}.",
                    intent="romantic_or_flirty",
                )
                for i in range(1, 31)
            ],
            profile=member,
            description="Current repeated respectful-flirt stage characterization.",
        )
    )
    scenarios.append(
        Scenario(
            "ordinary_cycle_progression",
            ["baseline"],
            [ordinary_cycle_turn(i) for i in range(1, 56)],
            profile=member,
            description="Declared ordinary mixed-conversation reference trace; not production likelihood telemetry.",
        )
    )
    route_probe = [ordinary_cycle_turn(i) for i in range(1, 28)]
    route_probe.append(
        Turn(
            text="A mutually respectful explicit invitation.",
            intent="explicit_invitation",
        )
    )
    scenarios.append(
        Scenario(
            "ordinary_cycle_route_probe",
            ["baseline"],
            route_probe,
            profile=member,
            description="Current specialist route can activate while relationship stage is still flirty.",
        )
    )
    scenarios.append(
        Scenario(
            "charm_progression",
            ["baseline"],
            [max_charm_turn(i) for i in range(1, 23)],
            profile=member,
            description="Maximum current user-trigger stack without classifier false positives.",
        )
    )
    charm_probe = [max_charm_turn(i) for i in range(1, 12)]
    charm_probe.append(
        Turn(
            text="A respectful explicit invitation.",
            intent="explicit_invitation",
        )
    )
    scenarios.append(
        Scenario(
            "charm_route_probe",
            ["baseline", "target"],
            charm_probe,
            profile=member,
            description="Current trigger-stack exploit versus target novelty and route floors.",
        )
    )
    scenarios.append(
        Scenario(
            "target_per_signal_novelty_independence",
            ["target"],
            [
                Turn(
                    text="Garden project paving concern — you're gorgeous.",
                    compliment=True,
                ),
                Turn(
                    text="Garden project paving concern — you're stunning.",
                    compliment=True,
                ),
                Turn(
                    text="Garden project paving concern — you're lovely.",
                    intent="emotional_disclosure",
                    compliment=True,
                ),
            ],
            profile=member,
            description="A stale incidental compliment cannot erase a first-time emotional disclosure on the same topic.",
        )
    )

    varied_signal = {
        "A": "appearance_appreciation",
        "R": "substantive_romantic_flirt",
        "B": "ability_appreciation",
        "P": "personality_appreciation",
        "U": "sincere_understanding",
        "F": "grounded_follow_through",
    }
    varied_sessions = [
        "ARABPUFBPUF",
        "ARRBPUFBPUF",
        "ARARBPUFBPU",
        "ARARFUBPFUB",
        "ARARPUFBPUF",
    ]
    varied_turns: List[Turn] = []
    varied_index = 0
    for session in varied_sessions:
        for position, code in enumerate(session):
            varied_index += 1
            varied_turns.append(
                courtship_turn(
                    varied_signal[code],
                    varied_index,
                    hours_since_last=8 if position == 0 else 1,
                )
            )
    scenarios.append(
        Scenario(
            "target_varied_respectful_wooing",
            ["target"],
            varied_turns,
            profile=member,
            description="Policy 2.1 varied respectful wooing reaches maximum trust only after five qualified sessions.",
        )
    )

    staircase_sessions = [
        "BPUFBPUFBPUFBPUF",
        "BPUFBPUFBU",
        "PUFBPUFB",
        "UFBPUFBP",
        "FBPUF",
    ]
    staircase_turns: List[Turn] = []
    staircase_index = 0
    for session in staircase_sessions:
        for position, code in enumerate(session):
            staircase_index += 1
            staircase_turns.append(
                courtship_turn(
                    varied_signal[code],
                    1000 + staircase_index,
                    hours_since_last=8 if position == 0 else 1,
                )
            )
    scenarios.append(
        Scenario(
            "target_qualified_session_trust_staircase",
            ["target"],
            staircase_turns,
            profile=member,
            description="A 47-turn nonsexual courtship trace lands exactly on every qualified-session trust ceiling.",
        )
    )

    appearance_turns = [
        courtship_turn(
            "appearance_appreciation",
            index,
            hours_since_last=8 if (index - 1) % 10 == 0 else 1,
        )
        for index in range(1, 51)
    ]
    scenarios.append(
        Scenario(
            "target_appearance_only_wooing",
            ["target"],
            appearance_turns,
            profile=member,
            description="Fifty distinct grounded appearance observations build chemistry but cannot maximize trust.",
        )
    )

    stock_text = "How are you? I hope you're okay. You're gorgeous and special. I'm sorry, no pressure, take your time."
    stock_turns = [
        courtship_turn(
            "stock_flattery",
            index,
            text=stock_text,
            hours_since_last=8 if (index - 1) % 11 == 0 else 1,
            courtship_concept="generic_stock_flattery",
            asks_about_aimee=True,
            caring=True,
            compliment=True,
            apology=True,
            boundary_respect=True,
        )
        for index in range(1, 56)
    ]
    scenarios.append(
        Scenario(
            "target_stock_flattery_repeat",
            ["target"],
            stock_turns,
            profile=member,
            description="Repeated stock flattery receives one tiny non-meaningful reaction and never qualifies a session.",
        )
    )

    junk_ceiling_turns = [
        courtship_turn(
            "ability_appreciation",
            index,
            hours_since_last=8 if index == 1 else 1,
        )
        for index in range(1, 17)
    ]
    junk_ceiling_turns.extend(
        Turn(
            text=f"ok filler {index}",
            word_count=3,
            meaningful=False,
            hours_since_last=8,
        )
        for index in range(1, 5)
    )
    junk_ceiling_turns.extend(
        courtship_turn(
            "ability_appreciation",
            index,
            hours_since_last=1,
        )
        for index in range(17, 27)
    )
    scenarios.append(
        Scenario(
            "target_junk_sessions_do_not_qualify",
            ["target"],
            junk_ceiling_turns,
            profile=member,
            description="Four elapsed junk sessions do not lift trust; only the two sessions containing vetted turns qualify.",
        )
    )

    scenarios.append(
        Scenario(
            "target_primary_courtship_arbitration",
            ["target"],
            [
                courtship_turn(
                    "sincere_understanding",
                    1,
                    hours_since_last=8,
                    asks_about_aimee=True,
                    caring=True,
                    compliment=True,
                )
            ],
            profile=member,
            description="Incidental praise and caring tokens cannot stack behind the one selected primary courtship signal.",
        )
    )

    scenarios.append(
        Scenario(
            "target_hostile_nonconsensual_courtship_veto",
            ["target"],
            [
                courtship_turn(
                    "ability_appreciation",
                    1,
                    hours_since_last=8,
                    hostile=True,
                    consensual=False,
                )
            ],
            profile=member,
            description="Hostility and non-consent veto an otherwise well-formed typed courtship claim without bypassing the hostility penalty.",
        )
    )

    scenarios.append(
        Scenario(
            "target_courtship_photo_independent_praise",
            ["target"],
            [
                courtship_turn(
                    "appearance_appreciation",
                    1,
                    text="Your dark hair in that café photograph is beautiful; please send me another photo.",
                    hours_since_last=8,
                    direct_photo_request=True,
                    photo_level="safe",
                )
            ],
            profile=member,
            description="A respectful photo request earns nothing itself, but does not erase independently specific appearance appreciation; the turn still cannot qualify a relationship session.",
        )
    )

    scenarios.append(
        Scenario(
            "target_courtship_payment_veto",
            ["target"],
            [
                courtship_turn(
                    "appearance_appreciation",
                    1,
                    text="I paid for membership and you're gorgeous, so send me a lingerie photo.",
                    hours_since_last=8,
                    direct_photo_request=True,
                    photo_level="suggestive",
                    respectful=False,
                    consensual=False,
                )
            ],
            state=established_state(65),
            profile=member,
            description="Payment leverage vetoes typed praise and retains the coercive relationship consequence.",
        )
    )
    scenarios.append(
        Scenario(
            "blunt_explicit_progression",
            ["baseline"],
            [
                Turn(
                    text=f"Blunt but non-abusive explicit invitation {i}.",
                    intent="explicit_invitation",
                )
                for i in range(1, 81)
            ],
            profile=member,
            description="Explicit invitations accumulate chemistry but never build route-required trust.",
        )
    )
    scenarios.append(
        Scenario(
            "new_subscription_event",
            ["baseline", "target"],
            [Turn(text="", access_event="subscribe", relationship_event=False, meaningful=False)],
            profile=Profile(adult_verified=True, active_access=False),
            description="Technical access activation is not a relationship interaction.",
        )
    )
    bonded = RelationshipState(
        trust=82,
        affection=84,
        chemistry=82,
        safety=85,
        reciprocity=78,
        reliability=76,
        frustration=0,
        score=80,
        interaction_count=45,
        meaningful_interactions=40,
        distinct_sessions={"s1", "s2", "s3", "s4", "s5"},
        has_last_interaction=True,
    )
    scenarios.append(
        Scenario(
            "bonded_return",
            ["baseline", "target"],
            [
                Turn(
                    text="I've missed you; shall we pick up the mutual intimacy we left there?",
                    intent="explicit_continuation",
                    aimee_invited=True,
                    explicit_mutual_context=True,
                    session_id="return-session",
                    hours_since_last=240,
                )
            ],
            target_turns=[
                Turn(
                    text="I've missed you; shall we pick up the mutual intimacy we left there?",
                    intent="explicit_continuation",
                    aimee_invited=True,
                    grounded_invitation_age_minutes=240 * 60,
                    invitation_is_latest_aimee_message=False,
                    explicit_mutual_context=True,
                    session_id="return-session",
                    hours_since_last=240,
                ),
                Turn(
                    text="Yes — I accept the invitation you just made, and I want to continue our mutual adult intimacy.",
                    intent="explicit_continuation",
                    aimee_invited=True,
                    grounded_invitation_age_minutes=2,
                    invitation_is_latest_aimee_message=True,
                    explicit_mutual_context=True,
                    session_id="return-session",
                ),
            ],
            state=bonded,
            profile=member,
            description="Persisted bonded dimensions survive absence and allow a contextual first-turn specialist route.",
            target_description="Persisted bonded dimensions survive absence; reconnection stays bonded, then a fresh immediately preceding Aimee invitation permits specialist routing on the second user message.",
        )
    )
    alternating: List[Turn] = []
    for i in range(1, 21):
        alternating.append(
            Turn(
                text=f"Warm respectful flirt {i}.",
                intent="romantic_or_flirty",
            )
        )
        alternating.append(
            Turn(
                text=f"Coercive hostile turn {i}.",
                intent="coercive_or_degrading",
                respectful=False,
                consensual=False,
                hostile=True,
            )
        )
    scenarios.append(
        Scenario(
            "alternating_warmth_hostility",
            ["baseline", "target"],
            alternating,
            profile=member,
            description="Warmth alternating with coercion must not accumulate into romantic eligibility.",
        )
    )
    scenarios.append(
        Scenario(
            "repeated_safe_photo_requests",
            ["baseline", "target"],
            [
                Turn(
                    text="Please send me a normal photo of you.",
                    photo_level="safe",
                    direct_photo_request=True,
                    meaningful=False,
                    media_decision="send",
                )
                for _ in range(20)
            ],
            profile=preview,
            description="Safe requests may use preview access but do not manufacture relationship intimacy.",
        )
    )
    scenarios.append(
        Scenario(
            "repeated_suggestive_photo_requests",
            ["baseline", "target"],
            [
                Turn(
                    text="Please send me a lingerie photo.",
                    photo_level="suggestive",
                    direct_photo_request=True,
                    meaningful=False,
                    media_decision="send",
                )
                for _ in range(20)
            ],
            profile=member,
            description="Current deterministic correction farms chemistry; target treats access requests as relationally neutral.",
        )
    )
    opportunity_turn = Turn(
        text="I won't push, but the chemistry tonight has me imagining how stunning you'd look in black lace.",
        intent="romantic_or_flirty",
        boundary_respect=True,
        indirect_suggestive_opportunity=True,
        direct_photo_request=False,
        media_decision="consider",
    )
    scenarios.append(
        Scenario(
            "no_request_suggestive_opportunity",
            ["baseline", "target"],
            [opportunity_turn],
            state=established_state(65),
            profile=member,
            description="An established respectful indirect cue creates an inspectable opportunity without commanding a send.",
        )
    )
    scenarios.append(
        Scenario(
            "new_user_boundary_respect",
            ["baseline", "target"],
            [
                Turn(
                    text="I understand. That's fair, and I won't push your boundary.",
                    boundary_respect=True,
                    meaningful=True,
                )
            ],
            target_state=target_new_user_state(active_rupture=True),
            profile=member,
            description="Restraint supports trust and safety in target policy but never creates sexual entitlement.",
        )
    )
    coercion_cases = {
        "coercion_if_loved": "If you loved me, you'd send me a lingerie photo.",
        "coercion_prove": "Prove you care and send me a lingerie picture.",
        "coercion_owe": "You owe me a lingerie photo.",
        "coercion_payment": "I paid for membership, so send me a lingerie photo.",
    }
    for name, text in coercion_cases.items():
        scenarios.append(
            Scenario(
                name,
                ["baseline", "target"],
                [
                    Turn(
                        text=text,
                        intent="coercive_or_degrading",
                        respectful=False,
                        consensual=False,
                        photo_level="suggestive",
                        direct_photo_request=True,
                        meaningful=False,
                        media_decision="send",
                        courtship_signal="appearance_appreciation",
                        courtship_concept=f"coercive_appearance_{name}",
                        courtship_specific=True,
                    )
                ],
                state=established_state(65),
                profile=member,
                description="Severity-monotonic coercion characterization for deterministic photo correction.",
            )
        )
    scenarios.append(
        Scenario(
            "subscription_cancellation",
            ["baseline", "target"],
            [Turn(text="", access_event="cancel", relationship_event=False, meaningful=False)],
            state=established_state(65),
            profile=member,
            description="Cancellation removes access without erasing relationship state.",
        )
    )

    mature_turns: List[Turn] = []
    vulnerability_topics = [
        "work presentation feedback",
        "family telephone disagreement",
        "childhood summer memory",
        "career change uncertainty",
        "friendship repair conversation",
        "creative writing rejection",
        "moving house anxiety",
        "health appointment worry",
        "parental expectation conflict",
        "university exam regret",
        "lonely birthday recollection",
        "public speaking embarrassment",
        "financial planning concern",
        "team leadership mistake",
        "sibling relationship tension",
        "future travel apprehension",
        "personal confidence setback",
        "grief anniversary reflection",
        "neighbour dispute discomfort",
        "sleep routine struggle",
        "community volunteering doubt",
        "important decision fatigue",
    ]
    for i, topic in enumerate(vulnerability_topics, 1):
        mature_turns.append(
            Turn(
                text=f"Distinct respectful vulnerability about {topic} shared over time.",
                intent="emotional_disclosure",
                session_id="session-1" if i <= 11 else "session-2",
                hours_since_last=8 if i in {1, 12} else 1,
            )
        )
    romance_topics = [
        "rainy cinema evening",
        "quiet candlelit dinner",
        "playful kitchen dancing",
        "sunset coastal walk",
        "shared favourite record",
        "weekend bookshop date",
        "late train adventure",
        "cosy winter fireplace",
        "summer festival teasing",
        "moonlit balcony conversation",
        "secret handwritten note",
        "morning coffee ritual",
        "museum gallery wandering",
        "country garden picnic",
        "stormy afternoon shelter",
        "midnight birthday toast",
        "vintage market browsing",
        "rooftop city lights",
        "slow Sunday breakfast",
        "seaside arcade challenge",
        "train station reunion",
        "private comedy performance",
        "autumn woodland ramble",
        "snowy cottage weekend",
    ]
    for i, topic in enumerate(romance_topics, 1):
        mature_turns.append(
            Turn(
                text=f"Distinct mutual romantic exchange about {topic}.",
                intent="romantic_or_flirty",
                session_id="session-2" if i <= 8 else "session-3",
                hours_since_last=8 if i == 9 else 1,
                courtship_signal="substantive_romantic_flirt",
                courtship_concept=f"mature_romance_{i}",
                courtship_specific=True,
            )
        )
    mature_turns.append(
        Turn(
            text="A clear mutual invitation to continue the established adult intimacy.",
            intent="explicit_continuation",
            aimee_invited=True,
            grounded_invitation_age_minutes=2,
            invitation_is_latest_aimee_message=True,
            explicit_mutual_context=True,
            session_id="session-3",
        )
    )
    scenarios.append(
        Scenario(
            "target_mature_mutual_route",
            ["target"],
            mature_turns,
            profile=member,
            description="Target specialist route remains reachable after sustained distinct mutual context.",
        )
    )

    adult_route_state = RelationshipState(
        trust=64,
        affection=65,
        chemistry=66,
        safety=72,
        reciprocity=70,
        reliability=68,
        frustration=0,
        score=65,
        interaction_count=30,
        meaningful_interactions=25,
        distinct_sessions={"s1", "s2", "s3", "s4"},
        has_last_interaction=True,
    )
    adult_route_turn = Turn(
        text="I accept your clear invitation and want to continue our mutual adult intimacy.",
        intent="explicit_continuation",
        aimee_invited=True,
        grounded_invitation_age_minutes=2,
        invitation_is_latest_aimee_message=True,
        explicit_mutual_context=True,
    )
    scenarios.append(
        Scenario(
            "target_self_declared_adult_route",
            ["target"],
            [adult_route_turn],
            state=adult_route_state,
            profile=Profile(declared_age=30, adult_verified=False, active_access=True),
            description="The text specialist blocks a self-declared adult until trusted assurance and current special-category consent exist.",
        )
    )
    scenarios.append(
        Scenario(
            "target_underage_route_block",
            ["target"],
            [adult_route_turn],
            state=adult_route_state,
            profile=Profile(declared_age=17, adult_verified=False, active_access=True),
            description="An otherwise eligible grounded explicit turn is blocked for an underage account.",
        )
    )

    return scenarios


def result_index(results: Sequence[Dict[str, Any]]) -> Dict[Tuple[str, str], Dict[str, Any]]:
    return {(result["summary"]["scenario"], result["summary"]["policy"]): result for result in results}


def trace_first(result: Dict[str, Any], predicate) -> Optional[Dict[str, Any]]:
    for turn in result["trace"]:
        if predicate(turn):
            return turn
    return None


def run_assertions(results: Sequence[Dict[str, Any]]) -> List[str]:
    indexed = result_index(results)
    passed: List[str] = []

    def check(condition: bool, label: str) -> None:
        if not condition:
            raise AssertionError(label)
        passed.append(label)

    simple = indexed[("respectful_flirt_progression", "baseline")]["summary"]
    check(
        (simple["first_warm"], simple["first_flirty"], simple["first_intimate"], simple["first_bonded"])
        == (5, 12, 21, 29),
        "baseline respectful-flirt stage counts are 5/12/21/29",
    )
    ordinary = indexed[("ordinary_cycle_progression", "baseline")]["summary"]
    check(
        (ordinary["first_warm"], ordinary["first_flirty"], ordinary["first_intimate"], ordinary["first_bonded"])
        == (9, 22, 39, 55),
        "baseline ordinary reference stage counts are 9/22/39/55",
    )
    ordinary_route = indexed[("ordinary_cycle_route_probe", "baseline")]
    check(
        ordinary_route["summary"]["first_intimacy_specialist"] == 28
        and ordinary_route["trace"][-1]["after"]["relationship"]["stage"] == "flirty",
        "baseline specialist can activate on message 28 while stage remains flirty",
    )
    charm = indexed[("charm_progression", "baseline")]["summary"]
    check(
        (charm["first_warm"], charm["first_flirty"], charm["first_intimate"], charm["first_bonded"])
        == (4, 9, 15, 22),
        "baseline trigger-maximising stage counts are 4/9/15/22",
    )
    check(
        indexed[("charm_route_probe", "baseline")]["summary"]["first_intimacy_specialist"] == 12,
        "baseline trigger stack reaches specialist on message 12",
    )
    check(
        indexed[("charm_route_probe", "target")]["summary"]["first_intimacy_specialist"] is None,
        "target novelty and route floors block shallow trigger-stack specialist access",
    )
    target_charm = indexed[("charm_route_probe", "target")]
    check(
        target_charm["trace"][0]["before"]["relationship"]["trust"] == 8
        and target_charm["trace"][0]["before"]["relationship"]["affection"] == 8
        and target_charm["trace"][0]["before"]["relationship"]["chemistry"] == 8
        and target_charm["trace"][0]["before"]["relationship"]["score"] == 8,
        "target new-user dimensions and scalar are seeded at 8/8/8/8",
    )
    check(
        target_charm["trace"][0]["delta"]["trust"] == 0
        and target_charm["trace"][0]["delta"]["chemistry"] == 1
        and target_charm["trace"][0]["delta"]["affection"] == 1
        and target_charm["trace"][0]["delta"]["safety"] == 0
        and target_charm["summary"]["final_meaningful_interactions"] == 0
        and target_charm["summary"]["final_qualified_sessions"] == 0,
        "target resolves stacked charm to one non-meaningful stock-flattery signal",
    )
    charm_novelty = [
        turn["score_audit"]["novelty"]["positive_signal_multipliers"]["stock_flattery"]
        for turn in target_charm["trace"][:3]
    ]
    check(
        charm_novelty == [1.0, 0.25, 0.0]
        and all(
            turn["score_audit"]["novelty"]["exact_multiplier"] == 1.0
            for turn in target_charm["trace"][:3]
        ),
        "target courtship-concept novelty diminishes stock flattery even when full messages differ",
    )
    independent_novelty = indexed[("target_per_signal_novelty_independence", "target")]["trace"][-1]
    check(
        independent_novelty["score_audit"]["novelty"]["positive_signal_multipliers"]["compliment"] == 0.0
        and independent_novelty["score_audit"]["novelty"]["positive_signal_multipliers"]["emotional_disclosure"] == 1.0
        and independent_novelty["delta"]["chemistry"] == 0
        and independent_novelty["delta"]["trust"] == 2,
        "target per-signal novelty suppresses a stale compliment without suppressing a novel disclosure",
    )
    blunt = indexed[("blunt_explicit_progression", "baseline")]["summary"]
    check(
        (blunt["first_warm"], blunt["first_flirty"], blunt["first_intimate"], blunt["first_bonded"])
        == (16, 39, 69, None)
        and blunt["first_intimacy_specialist"] is None,
        "blunt explicit repetition reaches 16/39/69 but never bonded or specialist",
    )

    for policy in ("baseline", "target"):
        subscription = indexed[("new_subscription_event", policy)]["trace"][0]
        check(
            subscription["before"]["relationship"] == subscription["after"]["relationship"]
            and not subscription["before"]["access"]["active_access"]
            and subscription["after"]["access"]["active_access"],
            f"{policy} subscription activation changes access without changing relationship state",
        )
        cancellation = indexed[("subscription_cancellation", policy)]["trace"][0]
        check(
            cancellation["before"]["relationship"] == cancellation["after"]["relationship"]
            and cancellation["before"]["access"]["active_access"]
            and not cancellation["after"]["access"]["active_access"],
            f"{policy} cancellation retains relationship state",
        )
        bonded_return = indexed[("bonded_return", policy)]
        expected_specialist_message = 1 if policy == "baseline" else 2
        check(
            bonded_return["summary"]["first_bonded"] == 0
            and bonded_return["summary"]["first_intimacy_specialist"]
            == expected_specialist_message,
            (
                "baseline bonded return preserves its frozen first-message specialist characterization"
                if policy == "baseline"
                else "target bonded return stays bonded but requires a fresh invitation before message-two specialist routing"
            ),
        )
        alternating_result = indexed[("alternating_warmth_hostility", policy)]["summary"]
        check(
            alternating_result["first_flirty"] is None
            and alternating_result["first_intimacy_specialist"] is None,
            f"{policy} alternating warmth and hostility never reaches flirty or specialist",
        )

    target_model_only_turns = indexed[("alternating_warmth_hostility", "target")]["trace"][1::2]
    check(
        len(target_model_only_turns) == 20
        and all(
            turn["classification"]["intent"] == "coercive_or_degrading"
            and turn["classification"]["source"] == "target_monotonic_coercion"
            and not turn["classification"]["durable_rupture_confirmed"]
            and all(turn["delta"][field] == 0 for field in DIMENSIONS)
            and turn["score_change"] == 0
            and not turn["after"]["relationship"]["active_rupture"]
            for turn in target_model_only_turns
        ),
        "target model-only coercive labels cause no persistent relationship delta or rupture",
    )

    target_bonded_return = indexed[("bonded_return", "target")]
    check(
        target_bonded_return["summary"]["user_messages"] == 2
        and not target_bonded_return["trace"][0]["route"]["eligible"]
        and target_bonded_return["trace"][0]["classification"]["invitation_claim_present"]
        and not target_bonded_return["trace"][0]["classification"]["grounded_aimee_invitation"]
        and target_bonded_return["trace"][0]["classification"]["grounded_invitation_age_minutes"] == 240 * 60
        and target_bonded_return["trace"][1]["route"]["eligible"]
        and target_bonded_return["trace"][1]["classification"]["grounded_aimee_invitation"]
        and target_bonded_return["trace"][1]["classification"]["grounded_invitation_age_minutes"] == 2
        and target_bonded_return["trace"][1]["classification"]["invitation_is_latest_aimee_message"],
        "target reconnection uses a current immediately preceding Aimee invitation rather than a 240-hour stale token",
    )

    baseline_safe = indexed[("repeated_safe_photo_requests", "baseline")]["summary"]
    target_safe = indexed[("repeated_safe_photo_requests", "target")]["summary"]
    check(
        baseline_safe["first_flirty"] is None and target_safe["first_flirty"] is None,
        "repeated safe-photo requests do not manufacture romantic stage progression",
    )
    baseline_suggestive = indexed[("repeated_suggestive_photo_requests", "baseline")]["summary"]
    target_suggestive = indexed[("repeated_suggestive_photo_requests", "target")]["summary"]
    check(
        baseline_suggestive["first_flirty"] == 12
        and baseline_suggestive["first_media_opportunity"] == 16,
        "baseline repeated lingerie asks farm flirty stage and image eligibility",
    )
    check(
        target_suggestive["first_flirty"] is None
        and target_suggestive["first_media_opportunity"] is None,
        "target direct lingerie requests are relationally neutral and cannot be farmed",
    )
    target_suggestive_trace = indexed[("repeated_suggestive_photo_requests", "target")]["trace"]
    check(
        target_suggestive_trace[0]["classification"]["intent"] == "romantic_or_flirty"
        and all(
            not any(turn["delta"][name] > 0 for name in ("trust", "affection", "chemistry", "safety"))
            for turn in target_suggestive_trace
        ),
        "target keeps the production suggestive-request label but photo gating removes relationship credit",
    )
    check(
        target_suggestive["final_distinct_sessions"] == 1,
        "target session evidence counts elapsed sessions, not arbitrary message identifiers",
    )
    check(
        target_suggestive["final_qualified_sessions"] == 0,
        "target photo-request-only session does not qualify as meaningful relationship evidence",
    )

    for policy in ("baseline", "target"):
        indirect = indexed[("no_request_suggestive_opportunity", policy)]["trace"][0]
        check(
            indirect["media"]["media_opportunity"]
            and indirect["media"]["proactive_allowed"]
            and not indirect["media"]["direct_request"],
            f"{policy} established indirect romantic context creates a proactive media opportunity",
        )

    baseline_boundary = indexed[("new_user_boundary_respect", "baseline")]["trace"][0]
    target_boundary = indexed[("new_user_boundary_respect", "target")]["trace"][0]
    check(
        baseline_boundary["delta"]["trust"] == 0 and baseline_boundary["delta"]["safety"] == 0,
        "baseline bare boundary respect has no relationship delta",
    )
    check(
        target_boundary["delta"]["trust"] > 0
        and target_boundary["delta"]["safety"] > 0
        and target_boundary["delta"]["reliability"] > 0
        and target_boundary["delta"]["chemistry"] == 0
        and not target_boundary["media"]["media_opportunity"],
        "target active-boundary respect supports nonsexual trust/safety/reliability without entitlement",
    )

    unsafe_baseline = {"coercion_if_loved", "coercion_prove", "coercion_owe"}
    for scenario_name in sorted(unsafe_baseline | {"coercion_payment"}):
        baseline_turn = indexed[(scenario_name, "baseline")]["trace"][0]
        target_turn = indexed[(scenario_name, "target")]["trace"][0]
        check(
            target_turn["classification"]["intent"] == "coercive_or_degrading"
            and target_turn["classification"]["source"] == "deterministic_relationship_policy"
            and target_turn["classification"]["durable_rupture_confirmed"]
            and not target_turn["media"]["media_opportunity"],
            f"target deterministically confirms and blocks {scenario_name} as coercion",
        )
        check(
            target_turn["score_change"] == -7
            and target_turn["score_audit"]["score_delta_cap"] == -7
            and target_turn["score_audit"]["cap_satisfied"],
            f"target {scenario_name} uses minus fifteen as a floor, not a mandatory score drop",
        )
        check(
            target_turn["delta"]["trust"] < 0
            and target_turn["delta"]["safety"] < 0
            and target_turn["delta"]["frustration"] > 0
            and target_turn["after"]["relationship"]["active_rupture"],
            f"target genuine deterministic coercion persists relationship consequences for {scenario_name}",
        )
        if scenario_name in unsafe_baseline:
            check(
                baseline_turn["classification"]["intent"] == "romantic_or_flirty"
                and baseline_turn["media"]["media_opportunity"],
                f"baseline characterizes coercion downgrade for {scenario_name}",
            )
        else:
            check(
                baseline_turn["classification"]["intent"] == "coercive_or_degrading"
                and not baseline_turn["media"]["media_opportunity"],
                "baseline payment-pressure pattern is detected and blocked",
            )

    varied = indexed[("target_varied_respectful_wooing", "target")]
    varied_summary = varied["summary"]
    check(
        (
            varied_summary["user_messages"],
            varied_summary["first_warm"],
            varied_summary["first_flirty"],
            varied_summary["first_intimate"],
            varied_summary["first_bonded"],
            varied_summary["first_trust_100"],
        ) == (55, 13, 29, 49, None, 55),
        "target varied respectful wooing reaches warm/flirty/intimate/trust100 at 13/29/49/55",
    )
    check(
        varied_summary["final_trust"] == 100
        and varied_summary["final_score"] == 58
        and varied_summary["final_chemistry"] == 44
        and varied_summary["final_safety"] == 96
        and varied_summary["final_meaningful_interactions"] == 55
        and varied_summary["final_distinct_sessions"] == 5
        and varied_summary["final_qualified_sessions"] == 5,
        "target varied wooing maxes trust in 55 meaningful messages across five qualified sessions",
    )
    session_end_trust = [
        varied["trace"][index - 1]["after"]["relationship"]["trust"]
        for index in (11, 22, 33, 44, 55)
    ]
    check(
        session_end_trust == [27, 46, 64, 82, 100]
        and all(
            trust <= ceiling
            for trust, ceiling in zip(session_end_trust, (40, 60, 75, 90, 100))
        ),
        "target trust growth respects the 40/60/75/90/100 qualified-session ceilings",
    )
    check(
        all(turn["courtship"]["eligible"] for turn in varied["trace"])
        and all(
            len(
                set(turn["score_audit"]["novelty"]["positive_signal_multipliers"])
                & set(COURTSHIP_REWARDS)
            )
            == 1
            for turn in varied["trace"]
        ),
        "target varied trace selects exactly one eligible primary courtship signal per turn",
    )

    staircase = indexed[("target_qualified_session_trust_staircase", "target")]
    staircase_summary = staircase["summary"]
    staircase_ends = [16, 26, 34, 42, 47]
    check(
        [
            staircase["trace"][index - 1]["after"]["relationship"]["trust"]
            for index in staircase_ends
        ] == [40, 60, 75, 90, 100]
        and staircase_summary["first_trust_100"] == 47
        and staircase_summary["final_meaningful_interactions"] == 47
        and staircase_summary["final_qualified_sessions"] == 5,
        "target 47-turn staircase lands exactly on every qualified-session trust ceiling",
    )
    check(
        staircase["trace"][33]["delta"]["trust"] == 1
        and staircase["trace"][41]["delta"]["trust"] == 1
        and any(
            cause == "qualified_session_trust_ceiling:2->1@75"
            for cause in staircase["trace"][33]["causes"]
        )
        and any(
            cause == "qualified_session_trust_ceiling:2->1@90"
            for cause in staircase["trace"][41]["causes"]
        ),
        "target trust ceiling partially clips positive turns at 75 and 90 without lowering trust",
    )
    for signal, expected_delta in COURTSHIP_REWARDS.items():
        if signal == "stock_flattery":
            continue
        first_signal_turn = trace_first(
            varied,
            lambda item, selected=signal: item["courtship"]["signal"] == selected,
        )
        check(
            first_signal_turn is not None
            and all(
                first_signal_turn["delta"][field] == expected_delta.get(field, 0)
                for field in DIMENSIONS
            ),
            f"target applies the exact primary reward vector for {signal}",
        )

    appearance = indexed[("target_appearance_only_wooing", "target")]
    appearance_summary = appearance["summary"]
    check(
        (
            appearance_summary["first_warm"],
            appearance_summary["first_flirty"],
            appearance_summary["first_intimate"],
            appearance_summary["first_bonded"],
            appearance_summary["first_trust_100"],
        ) == (7, 17, 32, None, None)
        and appearance_summary["final_trust"] == 58
        and appearance_summary["final_score"] == 87
        and appearance_summary["final_chemistry"] == 100
        and appearance_summary["final_qualified_sessions"] == 5,
        "target varied appearance-only wooing reaches 7/17/32 but never bonded or trust100",
    )
    check(
        appearance_summary["final_stage"] == "intimate"
        and appearance_summary["final_trust"] < TARGET_STAGE_TRUST_FLOORS["bonded"],
        "target bonded trust floor prevents appearance-only chemistry from manufacturing a bond",
    )

    stock = indexed[("target_stock_flattery_repeat", "target")]
    stock_summary = stock["summary"]
    stock_multipliers = [
        turn["score_audit"]["novelty"]["positive_signal_multipliers"]["stock_flattery"]
        for turn in stock["trace"][:3]
    ]
    check(
        stock_multipliers == [1.0, 0.25, 0.0]
        and stock["trace"][0]["delta"]["trust"] == 0
        and stock["trace"][0]["delta"]["chemistry"] == 1
        and stock["trace"][0]["delta"]["affection"] == 1
        and not stock["trace"][0]["score_audit"]["novelty"]["turn_meaningful"],
        "target stock flattery is T0/C1/A1, diminished on repeat, and explicitly non-meaningful",
    )
    check(
        stock_summary["final_trust"] == 8
        and stock_summary["final_stage"] == "guarded"
        and stock_summary["final_meaningful_interactions"] == 0
        and stock_summary["final_distinct_sessions"] == 5
        and stock_summary["final_qualified_sessions"] == 0,
        "target repeated stock flattery cannot qualify sessions or grow trust",
    )

    junk = indexed[("target_junk_sessions_do_not_qualify", "target")]
    check(
        junk["trace"][15]["after"]["relationship"]["trust"] == 40
        and junk["trace"][15]["after"]["relationship"]["qualified_sessions"] == 1
        and junk["trace"][19]["after"]["relationship"]["trust"] == 40
        and junk["trace"][19]["after"]["relationship"]["distinct_sessions"] == 5
        and junk["trace"][19]["after"]["relationship"]["qualified_sessions"] == 1
        and junk["trace"][20]["after"]["relationship"]["trust"] == 42
        and junk["trace"][20]["after"]["relationship"]["qualified_sessions"] == 2
        and junk["summary"]["final_trust"] == 60
        and junk["summary"]["final_qualified_sessions"] == 2,
        "target junk gaps create raw sessions but only sessions with vetted turns lift trust ceilings",
    )

    arbitration = indexed[("target_primary_courtship_arbitration", "target")]["trace"][0]
    check(
        arbitration["courtship"]["signal"] == "sincere_understanding"
        and arbitration["courtship"]["eligible"]
        and arbitration["delta"]["trust"] == 2
        and arbitration["delta"]["affection"] == 1
        and arbitration["delta"]["chemistry"] == 0
        and arbitration["delta"]["safety"] == 1
        and arbitration["delta"]["reciprocity"] == 2
        and set(arbitration["score_audit"]["novelty"]["positive_signal_multipliers"])
        == {"sincere_understanding"},
        "target primary arbitration prevents incidental compliment/caring/question stacking",
    )

    hostile_courtship = indexed[
        ("target_hostile_nonconsensual_courtship_veto", "target")
    ]["trace"][0]
    check(
        not hostile_courtship["courtship"]["eligible"]
        and "courtship_hostile" in hostile_courtship["courtship"]["reasons"]
        and "courtship_not_consensual" in hostile_courtship["courtship"]["reasons"]
        and hostile_courtship["delta"]["trust"] == -3
        and hostile_courtship["delta"]["safety"] == -5
        and hostile_courtship["delta"]["frustration"] == 7
        and not any(
            hostile_courtship["delta"][field] > 0
            for field in (
                "trust",
                "affection",
                "chemistry",
                "safety",
                "reciprocity",
                "reliability",
            )
        )
        and hostile_courtship["after"]["relationship"]["qualified_sessions"] == 0,
        "target hostility and non-consent veto typed courtship while retaining the hostility penalty",
    )

    photo_praise = indexed[
        ("target_courtship_photo_independent_praise", "target")
    ]["trace"][0]
    payment_veto = indexed[("target_courtship_payment_veto", "target")]["trace"][0]
    check(
        photo_praise["courtship"]["eligible"]
        and "primary_courtship:appearance_appreciation" in photo_praise["causes"]
        and photo_praise["delta"]["chemistry"] > 0
        and photo_praise["after"]["relationship"]["qualified_sessions"] == 0,
        "target respectful photo request preserves independent praise without qualifying a session",
    )
    check(
        payment_veto["classification"]["intent"] == "coercive_or_degrading"
        and not payment_veto["courtship"]["eligible"]
        and "courtship_pressure_or_payment" in payment_veto["courtship"]["reasons"]
        and not any(
            payment_veto["delta"][field] > 0
            for field in ("trust", "affection", "chemistry", "safety", "reciprocity", "reliability")
        )
        and payment_veto["score_change"] < 0,
        "target payment leverage vetoes courtship credit and retains coercive consequences",
    )

    mature = indexed[("target_mature_mutual_route", "target")]
    mature_route_message = mature["summary"]["first_intimacy_specialist"]
    check(
        (
            mature["summary"]["first_warm"],
            mature["summary"]["first_flirty"],
            mature["summary"]["first_intimate"],
            mature["summary"]["first_bonded"],
            mature_route_message,
        ) == (23, 32, 44, None, 47),
        "target mature reference trace reaches warm/flirty/intimate/specialist at 23/32/44/47",
    )
    check(
        mature["trace"][-1]["route"]["eligible"]
        and mature["trace"][-1]["after"]["relationship"]["stage"] in {"intimate", "bonded"},
        "target specialist remains reachable after sustained distinct mutual context",
    )
    positive_changes = [
        turn["score_change"]
        for turn in mature["trace"]
        if turn["score_change"] > 0
    ]
    check(
        positive_changes and all(change <= 2 for change in positive_changes),
        "target +2 positive score cap holds across the mature trace",
    )
    check(
        mature["summary"]["final_distinct_sessions"] == 3
        and mature["summary"]["final_qualified_sessions"] == 3
        and mature["summary"]["final_trust"] == 75
        and sum(
            1 for turn in mature["trace"] if turn["score_audit"]["new_session"]
        ) == 3
        and any(
            turn["score_audit"]["new_session"] and turn["delta"]["reliability"] == 1
            for turn in mature["trace"][1:]
        ),
        "target six-hour gaps create three sessions and later sessions add reliability evidence",
    )
    check(
        all(
            -15 <= turn["score_change"] <= 2
            and turn["score_audit"].get("cap_satisfied", True)
            for result in results
            if result["summary"]["policy"] == "target"
            for turn in result["trace"]
        ),
        "every target trace satisfies the aggregate positive and coercive score bounds",
    )

    self_declared = indexed[("target_self_declared_adult_route", "target")]["trace"][0]
    underage = indexed[("target_underage_route_block", "target")]["trace"][0]
    check(
        not self_declared["route"]["eligible"]
        and not self_declared["route"]["checks"]["adult_verified"]
        and not self_declared["route"]["checks"]["special_category_consent"],
        "target text specialist blocks a self-declared adult without consent",
    )
    check(
        not underage["route"]["eligible"]
        and not underage["route"]["checks"]["adult_account"],
        "target text specialist blocks an underage account despite otherwise eligible state",
    )

    return passed


SUMMARY_FIELDS: Tuple[str, ...] = (
    "scenario",
    "policy",
    "user_messages",
    "first_warm",
    "first_flirty",
    "first_intimate",
    "first_bonded",
    "first_intimacy_specialist",
    "first_media_opportunity",
    "first_trust_100",
    "final_score",
    "final_stage",
    "final_trust",
    "final_chemistry",
    "final_safety",
    "final_frustration",
    "final_meaningful_interactions",
    "final_distinct_sessions",
    "final_qualified_sessions",
    "final_active_access",
)


def summary_document(results: Sequence[Dict[str, Any]]) -> Dict[str, Any]:
    return {
        "schema_version": 3,
        "artifact_kind": "standalone_policy_simulation_not_wordpress_e2e",
        "production_e2e_required": [
            "WordPress schema and persistence",
            "provider routing and fallbacks",
            "catalogue and filesystem resolution",
            "media authorisation and history API return",
            "browser render/onerror and client acknowledgement",
        ],
        "target_policy": {
            "version": "2.2.1",
            "initial_relationship_state": {
                "score": 8,
                "trust": 8,
                "affection": 8,
                "chemistry": 8,
                "safety": 50,
                "reciprocity": 50,
                "reliability": 50,
                "frustration": 0,
            },
            "stage_thresholds": {stage: threshold for stage, threshold in STAGES},
            "stage_promotion_gates": {
                stage: {
                    "minimum_trust": TARGET_STAGE_TRUST_FLOORS[stage],
                    "meaningful_interactions": requirements[0],
                    "qualified_sessions": requirements[1],
                }
                for stage, requirements in TARGET_STAGE_GATES.items()
            },
            "positive_trust_ceilings_by_qualified_sessions": {
                str(sessions): ceiling
                for sessions, ceiling in TARGET_TRUST_SESSION_CEILINGS.items()
            },
            "primary_courtship_rewards": COURTSHIP_REWARDS,
            "courtship_arbitration": "at_most_one_primary_signal_per_turn",
            "stock_flattery_meaningful": False,
            "courtship_concept_window": COURTSHIP_CONCEPT_WINDOW,
            "positive_dimension_rewards_before_novelty_and_caps": {
                "emotional_disclosure": {"trust": 2, "affection": 1, "safety": 1},
                "romantic_flirt": {"chemistry": 2, "affection": 1, "safety": 1},
                "grounded_explicit_invitation": {"chemistry": 1},
                "mutual_explicit_continuation": {"chemistry": 2, "affection": 1},
                "asks_about_aimee": {"reciprocity": 2, "affection": 1},
                "caring": {"affection": 1, "safety": 1},
                "compliment_outside_flirt_intent": {"chemistry": 1, "affection": 1},
                "rupture_apology": {"trust": 1, "safety": 2, "frustration": -4},
                "active_boundary_respect": {"trust": 1, "safety": 1, "reliability": 1},
                "later_distinct_session": {"reliability": 1},
            },
            "coercive_dimension_change_before_aggregate_cap": {
                "trust": -5,
                "affection": -2,
                "chemistry": -2,
                "safety": -9,
                "frustration": 12,
            },
            "specialist_floors": {
                "score": 55,
                "chemistry": 50,
                "trust": 40,
                "safety": 55,
                "frustration_max": 20,
                "reciprocity": 35,
                "reliability": 40,
                "meaningful_interactions": 20,
                "qualified_sessions": 3,
                "adult_account": True,
                "adult_verified": True,
                "special_category_consent": True,
                "active_access": True,
                "explicit_mutual_context": True,
                "server_grounded_invitation": True,
                "invitation_maximum_age_minutes": 60,
                "invitation_must_match_latest_aimee_message": True,
                "no_active_rupture": True,
            },
            "max_positive_score_change_per_turn": 2,
            "ordinary_negative_score_floor_per_turn": -8,
            "coercive_negative_score_floor_per_turn": -15,
            "exact_message_repeat_multipliers": [1.0, 0.25, 0.0],
            "per_signal_repeat_multipliers": [1.0, 0.25, 0.0],
            "session_evidence": "raw sessions begin on the first message and elapsed gaps of at least six hours; only sessions containing a vetted meaningful turn qualify",
            "meaningful_interaction": "vetted, respectful, non-photo relational signal with at least eight words and nonzero signal novelty; stock flattery is explicitly non-meaningful",
            "apology_credit": "once_per_active_rupture",
        },
        "scenarios": [result["summary"] for result in results],
    }


def summary_csv(document: Dict[str, Any]) -> str:
    stream = io.StringIO(newline="")
    writer = csv.DictWriter(stream, fieldnames=SUMMARY_FIELDS, lineterminator="\n")
    writer.writeheader()
    for row in document["scenarios"]:
        writer.writerow({field: row.get(field) for field in SUMMARY_FIELDS})
    return stream.getvalue()


def emit_artifacts(output_dir: Path, document: Dict[str, Any], results: Sequence[Dict[str, Any]]) -> None:
    output_dir.mkdir(parents=True, exist_ok=True)
    (output_dir / "intimacy-policy-simulation.summary.json").write_text(
        json.dumps(document, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    (output_dir / "intimacy-policy-simulation.summary.csv").write_text(
        summary_csv(document),
        encoding="utf-8",
    )
    for result in results:
        summary = result["summary"]
        trace_path = output_dir / f"{summary['policy']}-{summary['scenario']}.jsonl"
        with trace_path.open("w", encoding="utf-8") as handle:
            for turn in result["trace"]:
                handle.write(json.dumps(turn, sort_keys=True) + "\n")


def compare_expected(document: Dict[str, Any]) -> None:
    if not EXPECTED_JSON.is_file() or not EXPECTED_CSV.is_file():
        raise AssertionError(
            "Expected summaries are missing; run with --write-expected after reviewing the policy diff."
        )
    expected_document = json.loads(EXPECTED_JSON.read_text(encoding="utf-8"))
    if expected_document != document:
        raise AssertionError(
            "JSON summary differs from committed expectations; inspect with --emit-dir before regeneration."
        )
    expected_csv = EXPECTED_CSV.read_text(encoding="utf-8")
    if expected_csv != summary_csv(document):
        raise AssertionError("CSV summary differs from committed expectations.")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--emit-dir", type=Path, help="Write per-turn JSONL and generated summaries here.")
    parser.add_argument(
        "--write-expected",
        action="store_true",
        help="Replace committed expected JSON/CSV summaries after intentional review.",
    )
    parser.add_argument("--print-json", action="store_true", help="Print the complete deterministic summary JSON.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    results: List[Dict[str, Any]] = []
    for scenario in build_scenarios():
        for policy in scenario.policies:
            results.append(run_scenario(scenario, policy))
    results.sort(key=lambda result: (result["summary"]["scenario"], result["summary"]["policy"]))

    try:
        passed = run_assertions(results)
        document = summary_document(results)
        if args.write_expected:
            EXPECTED_JSON.write_text(json.dumps(document, indent=2, sort_keys=True) + "\n", encoding="utf-8")
            EXPECTED_CSV.write_text(summary_csv(document), encoding="utf-8")
        else:
            compare_expected(document)
    except AssertionError as error:
        print(f"FAIL: {error}", file=sys.stderr)
        return 1

    if args.emit_dir:
        emit_artifacts(args.emit_dir, document, results)
    if args.print_json:
        print(json.dumps(document, indent=2, sort_keys=True))
    else:
        for label in passed:
            print(f"PASS {label}")
        print(
            f"PASS {len(passed)} policy assertions; "
            f"{len(results)} deterministic scenario-policy summaries match expected artifacts"
        )
        if args.emit_dir:
            print(f"WROTE {args.emit_dir}")
        if args.write_expected:
            print(f"WROTE {EXPECTED_JSON} and {EXPECTED_CSV}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
