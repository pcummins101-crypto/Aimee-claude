(function () {
	"use strict";

	const roots = document.querySelectorAll(".ahl");
	if (!roots.length) return;

	roots.forEach(function (root) {
		root.querySelectorAll('a[href^="#ahl-"]').forEach(function (link) {
			link.addEventListener("click", function (event) {
				const target = document.querySelector(link.getAttribute("href"));
				if (!target) return;
				event.preventDefault();
				target.scrollIntoView({
					behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth",
					block: "start",
				});
			});
		});

		const routeTabs = Array.from(root.querySelectorAll("[data-ahl-route-tab]"));
		const routePanels = Array.from(root.querySelectorAll("[data-ahl-route-panel]"));
		const selectRouteBoard = function (routeId, moveFocus) {
			routeTabs.forEach(function (tab) {
				const selected = tab.getAttribute("data-ahl-route-tab") === routeId;
				tab.setAttribute("aria-selected", selected ? "true" : "false");
				tab.setAttribute("tabindex", selected ? "0" : "-1");
				if (selected && moveFocus) tab.focus();
			});
			routePanels.forEach(function (panel) {
				panel.hidden = panel.getAttribute("data-ahl-route-panel") !== routeId;
			});
		};

		routeTabs.forEach(function (tab, index) {
			tab.addEventListener("click", function () {
				selectRouteBoard(tab.getAttribute("data-ahl-route-tab") || "city", false);
			});
			tab.addEventListener("keydown", function (event) {
				if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
				event.preventDefault();
				let nextIndex = index;
				if (event.key === "Home") nextIndex = 0;
				if (event.key === "End") nextIndex = routeTabs.length - 1;
				if (event.key === "ArrowLeft") nextIndex = (index - 1 + routeTabs.length) % routeTabs.length;
				if (event.key === "ArrowRight") nextIndex = (index + 1) % routeTabs.length;
				const nextRoute = routeTabs[nextIndex].getAttribute("data-ahl-route-tab") || "city";
				selectRouteBoard(nextRoute, true);
			});
		});

		const lightbox = root.querySelector("[data-ahl-lightbox]");
		if (!lightbox || typeof HTMLDialogElement === "undefined" || !(lightbox instanceof HTMLDialogElement)) return;

		const lightboxImage = lightbox.querySelector("img");
		const closeButton = lightbox.querySelector("[data-ahl-lightbox-close]");

		root.querySelectorAll("[data-ahl-lightbox-src]").forEach(function (trigger) {
			trigger.addEventListener("click", function () {
				if (!lightboxImage) return;
				lightboxImage.src = trigger.getAttribute("data-ahl-lightbox-src") || "";
				lightboxImage.alt = trigger.getAttribute("data-ahl-lightbox-alt") || "Hyperlane screenshot";
				lightbox.showModal();
			});
		});

		if (closeButton) closeButton.addEventListener("click", function () { lightbox.close(); });
		lightbox.addEventListener("click", function (event) {
			if (event.target === lightbox) lightbox.close();
		});
	});
})();
