/**
 * Avenra Hyperlane 3.2.0 route-home progressive enhancement.
 *
 * This file deliberately leaves the React setup component in charge. It only
 * decorates the existing route radio buttons, so their original handlers,
 * state, validation and subsequent setup screens remain the single source of
 * truth.
 */
(function hyperlaneHomeV320() {
  "use strict";

  if (typeof window === "undefined" || window.__avenraHyperlaneHomeV320) {
    return;
  }

  var VERSION = "3.2.0";
  var BESTS_KEY = "avenra-hyperlane-best-by-route-v1";
  var LEGACY_BEST_KEY = "avenra-hyperlane-best";
  var ROUTES = [
    {
      id: "motorway",
      className: "route-motorway",
      shield: "M1",
      place: "M1 · YORKSHIRE",
      title: "NORTHERN CHARGE",
      strapline: "Three lanes. One clean run."
    },
    {
      id: "rural",
      className: "route-rural",
      shield: "A66",
      place: "YORKSHIRE DALES",
      title: "PENNINE RUN",
      strapline: "Read the road. Own the rhythm."
    },
    {
      id: "city",
      className: "route-city",
      shield: "A40",
      place: "WEST LONDON · RAIN",
      title: "AFTER DARK",
      strapline: "Wet streets. Electric response."
    }
  ];
  var ROUTE_BY_ID = ROUTES.reduce(function makeRouteMap(result, route) {
    result[route.id] = route;
    return result;
  }, Object.create(null));
  var state = {
    frame: 0,
    observer: null,
    keyboardGroups: typeof WeakSet === "function" ? new WeakSet() : null
  };

  function safeLocalStorageGet(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function normaliseScore(value) {
    var score = Number(value);
    return Number.isFinite(score) && score > 0 ? Math.round(score) : 0;
  }

  function readBestScores() {
    var scores = { city: 0, rural: 0, motorway: 0 };
    var stored = safeLocalStorageGet(BESTS_KEY);

    if (stored) {
      try {
        var parsed = JSON.parse(stored);
        if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
          scores.city = normaliseScore(parsed.city);
          scores.rural = normaliseScore(parsed.rural);
          scores.motorway = normaliseScore(parsed.motorway);
        }
      } catch (error) {
        // A damaged preference must never prevent the setup screen opening.
      }
    }

    if (!scores.city) {
      scores.city = normaliseScore(safeLocalStorageGet(LEGACY_BEST_KEY));
    }

    return scores;
  }

  function formatScore(score) {
    return score > 0 ? score.toLocaleString("en-GB") : "—";
  }

  function routeForCard(card) {
    for (var index = 0; index < ROUTES.length; index += 1) {
      if (card.classList.contains(ROUTES[index].className)) {
        return ROUTES[index];
      }
    }
    return null;
  }

  function element(tagName, className, text) {
    var node = document.createElement(tagName);
    node.className = className;
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  function makeMasthead() {
    var masthead = element("div", "hl-home-masthead");
    masthead.setAttribute("aria-hidden", "true");

    var identity = element("div", "hl-home-identity");
    identity.appendChild(element("span", "hl-home-wordmark", "AVENRÀ"));

    var edition = element("span", "hl-home-edition");
    edition.appendChild(document.createTextNode("INTERNAL EXPERIENCE · "));
    edition.appendChild(element("b", "hl-home-version", "V" + VERSION));
    identity.appendChild(edition);

    var titleBlock = element("div", "hl-home-title-block");
    titleBlock.appendChild(element("strong", "hl-home-title", "EVO RIDER"));
    titleBlock.appendChild(element("span", "hl-home-tagline", "BRITAIN. FULL CHARGE."));

    masthead.appendChild(identity);
    masthead.appendChild(titleBlock);
    masthead.appendChild(element("span", "hl-home-select-label", "SELECT ROUTE"));
    return masthead;
  }

  function makeRouteFace(route) {
    var face = element("span", "hl-route-face");
    face.setAttribute("aria-hidden", "true");

    var top = element("span", "hl-route-topline");
    top.appendChild(element("b", "hl-route-shield", route.shield));
    top.appendChild(element("span", "hl-route-selected-label", "SELECTED"));

    var copy = element("span", "hl-route-copy-v320");
    copy.appendChild(element("small", "hl-route-place", route.place));
    copy.appendChild(element("strong", "hl-route-title", route.title));
    copy.appendChild(element("span", "hl-route-strapline", route.strapline));
    copy.appendChild(element("b", "hl-route-best", "BEST —"));

    face.appendChild(top);
    face.appendChild(copy);
    return face;
  }

  function updateRouteCard(card, route, score) {
    var face = card.querySelector(":scope > .hl-route-face");
    if (!face) {
      face = makeRouteFace(route);
      card.appendChild(face);
    }

    var scoreText = formatScore(score);
    var best = face.querySelector(".hl-route-best");
    if (best && best.textContent !== "BEST " + scoreText) {
      best.textContent = "BEST " + scoreText;
    }

    if (card.getAttribute("data-hl-route") !== route.id) {
      card.setAttribute("data-hl-route", route.id);
    }
    if (card.getAttribute("data-hl-best") !== scoreText) {
      card.setAttribute("data-hl-best", scoreText);
    }

    var ariaLabel = route.shield + " " + route.title + ". " + route.place + ". " +
      route.strapline + " Personal best " + (score ? scoreText + " points" : "not set") + ".";
    if (card.getAttribute("aria-label") !== ariaLabel) {
      card.setAttribute("aria-label", ariaLabel);
    }
  }

  function orderedRouteCards(group) {
    return ROUTES.map(function findRouteCard(route) {
      return group.querySelector(".setup-route-card." + route.className);
    }).filter(Boolean);
  }

  function enableKeyboardNavigation(group) {
    if (!state.keyboardGroups || state.keyboardGroups.has(group)) {
      return;
    }

    group.addEventListener("keydown", function handleRouteKeys(event) {
      if (!["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown", "Home", "End"].includes(event.key)) {
        return;
      }

      var cards = orderedRouteCards(group);
      var activeIndex = cards.indexOf(document.activeElement);
      if (activeIndex < 0) {
        activeIndex = Math.max(0, cards.findIndex(function isChecked(card) {
          return card.getAttribute("aria-checked") === "true" || card.classList.contains("is-selected");
        }));
      }

      var targetIndex;
      if (event.key === "Home") {
        targetIndex = 0;
      } else if (event.key === "End") {
        targetIndex = cards.length - 1;
      } else {
        var forwards = event.key === "ArrowRight" || event.key === "ArrowDown";
        targetIndex = (activeIndex + (forwards ? 1 : -1) + cards.length) % cards.length;
      }

      var target = cards[targetIndex];
      if (!target) {
        return;
      }

      event.preventDefault();
      target.focus({ preventScroll: true });
      // Invoke the existing React action once; this enhancement has no click handler.
      target.click();
    });

    state.keyboardGroups.add(group);
  }

  function clearInactiveHomePanels(activePanel) {
    document.querySelectorAll(".start-panel.hl-home-route").forEach(function clearPanel(panel) {
      if (panel !== activePanel) {
        panel.classList.remove("hl-home-route");
        panel.removeAttribute("data-hl-selected-route");
      }
    });
  }

  function decorate() {
    state.frame = 0;

    var routeStep = document.querySelector(".setup-step.setup-step-route");
    if (!routeStep) {
      clearInactiveHomePanels(null);
      document.querySelectorAll(".hl-home-masthead").forEach(function removeDetachedMasthead(node) {
        node.remove();
      });
      return;
    }

    var panel = routeStep.closest(".start-panel");
    var routeGroup = routeStep.querySelector(".setup-route-options");
    if (!panel || !routeGroup) {
      return;
    }

    clearInactiveHomePanels(panel);
    if (!panel.classList.contains("hl-home-route")) {
      panel.classList.add("hl-home-route");
    }

    var masthead = routeStep.querySelector(":scope > .hl-home-masthead");
    if (!masthead) {
      masthead = makeMasthead();
      routeStep.insertBefore(masthead, routeStep.firstChild);
    }

    var bestScores = readBestScores();
    var cards = Array.from(routeGroup.querySelectorAll(":scope > .setup-route-card"));
    var selectedRoute = "city";

    cards.forEach(function decorateCard(card) {
      var route = routeForCard(card);
      if (!route) {
        return;
      }
      updateRouteCard(card, route, bestScores[route.id]);
      if (card.getAttribute("aria-checked") === "true" || card.classList.contains("is-selected")) {
        selectedRoute = route.id;
      }
    });

    if (!ROUTE_BY_ID[selectedRoute]) {
      selectedRoute = "city";
    }
    if (panel.getAttribute("data-hl-selected-route") !== selectedRoute) {
      panel.setAttribute("data-hl-selected-route", selectedRoute);
    }

    // Radio groups expose one tab stop. Arrow keys move and select in the same
    // M1, A66, A40 order used visually and by assistive technology.
    cards.forEach(function updateRouteTabStop(card) {
      var route = routeForCard(card);
      if (route) {
        card.tabIndex = route.id === selectedRoute ? 0 : -1;
      }
    });

    enableKeyboardNavigation(routeGroup);
  }

  function scheduleDecorate() {
    if (!state.frame) {
      state.frame = window.requestAnimationFrame(decorate);
    }
  }

  function start() {
    var observedRoot = document.getElementById("root") || document.body;
    state.observer = new MutationObserver(scheduleDecorate);
    state.observer.observe(observedRoot, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ["class", "aria-checked"]
    });
    window.addEventListener("storage", scheduleDecorate);
    window.addEventListener("pageshow", scheduleDecorate);
    document.addEventListener("visibilitychange", function refreshWhenVisible() {
      if (!document.hidden) {
        scheduleDecorate();
      }
    });
    scheduleDecorate();
  }

  window.__avenraHyperlaneHomeV320 = {
    version: VERSION,
    refresh: scheduleDecorate,
    destroy: function destroy() {
      if (state.observer) {
        state.observer.disconnect();
      }
      if (state.frame) {
        window.cancelAnimationFrame(state.frame);
        state.frame = 0;
      }
      clearInactiveHomePanels(null);
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start, { once: true });
  } else {
    start();
  }
}());
