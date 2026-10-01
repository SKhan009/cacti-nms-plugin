"use strict";
const dashboard = document.querySelector(".rack-dashboard");
const details = document.querySelector("#device-details");
let zoom = 1;
document.querySelectorAll(".device").forEach(button => {
  button.addEventListener("click", () => {
    details.querySelector("h2").textContent = `Device #${button.dataset.device}`;
    details.querySelector(".device-status").textContent = `Status: ${button.dataset.state === "online" ? "Online" : button.dataset.state === "offline" ? "Offline" : "Maintenance"}`;
    details.showModal();
  });
});
document.querySelectorAll(".view-controls button").forEach(button => {
  button.addEventListener("click", async () => {
    const action = button.dataset.action;
    if (action === "fullscreen") {
      try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await dashboard.requestFullscreen();
      } catch { document.querySelector("#zoom-status").textContent = "Fullscreen is unavailable in this browser."; }
      return;
    }
    zoom = action === "fit" ? 1 : Math.max(.6, Math.min(1.5, zoom + (action === "in" ? .1 : -.1)));
    dashboard.style.setProperty("--zoom", zoom.toFixed(1));
    document.querySelector("#zoom-status").textContent = `Zoom ${Math.round(zoom * 100)}%`;
    document.querySelector('[data-action="in"]').disabled = zoom >= 1.5;
    document.querySelector('[data-action="out"]').disabled = zoom <= .6;
  });
});
document.addEventListener("fullscreenchange", () => {
  document.querySelector('[data-action="fullscreen"]').setAttribute("aria-label", document.fullscreenElement ? "Exit fullscreen" : "Enter fullscreen");
});
