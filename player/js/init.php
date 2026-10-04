<?php header("Content-Type: application/javascript"); ?>
"use strict";

// ── INIT ─────────────────────────────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", () => {
  resizeCanvases();

  const cont = $("slide-container");
  if(cont&&window.ResizeObserver) new ResizeObserver(()=>requestAnimationFrame(resizeCanvases)).observe(cont);

  // Sidebar toggle
  $("sidebar-toggle-btn").addEventListener("click", () => {
    $("right-sidebar").classList.toggle("collapsed");
    setTimeout(resizeCanvases, 220);
  });

  // Off-board scratch content strip (opt-in; off by default).
  window.UA_SHOW_OFFBOARD = false;
  window.offboardPanel = new OffboardPanel($("offboard-strip-track"));
  const offboardBtn = $("offboard-toggle-btn"), offboardStrip = $("offboard-strip");
  offboardBtn.addEventListener("click", () => {
    window.UA_SHOW_OFFBOARD = !window.UA_SHOW_OFFBOARD;
    offboardBtn.classList.toggle("active", window.UA_SHOW_OFFBOARD);
    offboardStrip.classList.toggle("hidden", !window.UA_SHOW_OFFBOARD);
    if(window.UA_SHOW_OFFBOARD && window.canvasRenderer){
      // Force a repaint so update() runs even if nothing else changed.
      window.offboardPanel._lastSig = null;
      window.canvasRenderer.invalidate();
      window.canvasRenderer.render(window.stateManager, window.pdfRenderer.annoLayout);
    }
  });

  // Init features
  initSidebarTabs();
  initUserDraw();
  initHighlightReel();
  initSeekThumbnail();
  initTranslate();
  _hookNoteMarkersOnLoad();

  // ── Setup-screen tab switching ─────────────────────────────────────────────
  document.querySelectorAll(".tab-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
      document.querySelectorAll(".tab-pane").forEach(p => p.classList.remove("active"));
      btn.classList.add("active");
      $(btn.dataset.tab).classList.add("active");
    });
  });

  // ── Online mode ────────────────────────────────────────────────────────────
  const params = new URLSearchParams(window.location.search);
  const vParam = params.get("video");
  const pParam = params.get("pdf");

  if(vParam){
    $("inp-video").value = vParam;
    $("inp-pdf").value   = pParam || "";
    loadSession(vParam, pParam || "");
  }

  $("load-btn-online").addEventListener("click", () => {
    const v = $("inp-video").value.trim();
    const p = $("inp-pdf").value.trim();
    if(!v){ alert("Please paste a .webm video URL."); return; }
    loadSession(v, p);
  });
  $("inp-video").addEventListener("keydown", e => { if(e.key==="Enter") $("load-btn-online").click(); });
  $("inp-pdf").addEventListener("keydown",   e => { if(e.key==="Enter") $("load-btn-online").click(); });

  // ── Offline mode — file pickers + drag & drop ──────────────────────────────
  let offlineFiles = { video: null, json: null, pdf: null };

  function setupFileDrop(dropId, inputId, lblId, key) {
    const drop  = $(dropId);
    const input = $(inputId);
    const lbl   = $(lblId);

    const setFile = file => {
      if(!file) return;
      if(key==="json" && !file.name.match(/\.json$/i)){ alert("Please pick a .json file"); return; }
      if(key==="pdf"  && !file.name.match(/\.pdf$/i)) { alert("Please pick a .pdf file");  return; }
      if(key==="video"&& !file.type.startsWith("video/")&&!file.name.match(/\.(webm|mp4|mkv)$/i)){ alert("Please pick a video file"); return; }
      offlineFiles[key] = file;
      lbl.textContent = "✓ " + file.name;
      drop.classList.add("has-file");
    };

    input.addEventListener("change", () => setFile(input.files[0]));

    drop.addEventListener("dragover",  e => { e.preventDefault(); drop.classList.add("drag-over"); });
    drop.addEventListener("dragleave", ()  => drop.classList.remove("drag-over"));
    drop.addEventListener("drop", e => {
      e.preventDefault(); drop.classList.remove("drag-over");
      setFile(e.dataTransfer.files[0]);
    });
  }

  setupFileDrop("drop-video", "file-video", "lbl-video", "video");
  setupFileDrop("drop-json",  "file-json",  "lbl-json",  "json");
  setupFileDrop("drop-pdf",   "file-pdf",   "lbl-pdf",   "pdf");

  $("load-btn-offline").addEventListener("click", async () => {
    if(!offlineFiles.video){ alert("Please select the output.webm video file."); return; }
    if(!offlineFiles.json) { alert("Please select the data.json file."); return; }
    await loadSessionOffline(offlineFiles.video, offlineFiles.json, offlineFiles.pdf);
  });
});

// ── Offline loader — reads files locally, no network needed ──────────────────
async function loadSessionOffline(videoFile, jsonFile, pdfFile) {
  showLoading("Reading data.json…");
  try {
    const jsonText = await jsonFile.text();
    const raw = JSON.parse(jsonText);

    // Object URLs stay in memory for this session
    const videoUrl = URL.createObjectURL(videoFile);
    const pdfUrl   = pdfFile ? URL.createObjectURL(pdfFile) : "";

    await loadLesson(raw, videoUrl, pdfUrl);

  } catch(err) {
    hideLoading();
    setupSection.classList.remove("hidden");
    playerSection.classList.add("hidden");
    alert("Error loading files: " + err.message);
  }
}