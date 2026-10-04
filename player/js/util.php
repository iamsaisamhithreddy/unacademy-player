<?php header("Content-Type: application/javascript"); ?>
"use strict";

// ─── PDF.js worker ───────────────────────────────────────────────────────────
pdfjsLib.GlobalWorkerOptions.workerSrc =
  "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";

// ─── Tiny helpers ────────────────────────────────────────────────────────────
const $ = id => document.getElementById(id);

const fmtTime = s => {
  if (!s || isNaN(s)) s = 0;
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sc = Math.floor(s % 60);
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(sc).padStart(2,'0')}`;
};

const sleep = ms => new Promise(r => setTimeout(r, ms));

// ─── Cached DOM references ───────────────────────────────────────────────────
// This file is loaded at the end of <body>, so the elements already exist.
const loadingOverlay = $("loading-overlay"), loadingStatus = $("loading-status");
const setupSection   = $("setup-section"),   playerSection = $("player-section");
const slideCanvas    = $("slide-canvas"),    drawCanvas    = $("draw-canvas");
const videoEl        = $("webcam-video");
const seekBarEl      = $("seek-bar"),        volSlider     = $("volume-slider-bar");
const statsBar       = $("stats-bar"),       bufferingOverlay = $("buffering-overlay");

const showLoading = msg => { loadingStatus.textContent = msg; loadingOverlay.classList.remove("hidden"); };
const hideLoading = ()  => loadingOverlay.classList.add("hidden");

function escHtml(s) {
  return String(s||"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}
