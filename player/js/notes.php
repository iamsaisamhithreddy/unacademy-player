<?php header("Content-Type: application/javascript"); ?>
"use strict";

// ════════════════════════════════════════════════════════════════════════════
// NOTES / BOOKMARKS SYSTEM  — saved per logged-in user + lecture via notes_api.php
// Falls back to localStorage when lecture_id is missing or the user is not logged in
// ════════════════════════════════════════════════════════════════════════════
let _notesKey    = "ua_notes_default";   // fallback localStorage key
let _notesCache  = [];   // [{id, ts, type, text, videoSec, slideUrl}]
let _currentVideoUrl = "";

// ── Resolve lecture_id from URL param (added by user.php watch-video link) ──
function getLectureId() {
  const p = new URLSearchParams(window.location.search);
  return parseInt(p.get("lecture_id") || "0", 10);
}

// ── Storage key (fallback only) ─────────────────────────────────────────────
function notesKey(videoUrl) {
  try {
    const u = new URL(videoUrl);
    return "ua_notes_" + u.pathname.split("/").filter(Boolean).join("_");
  } catch(_) {
    return "ua_notes_" + videoUrl.slice(-24).replace(/\W/g,"_");
  }
}

// ── API helper ──────────────────────────────────────────────────────────────
// notes_api.php lives next to user.php (one folder above /player/). We try that
// first and fall back to the same folder, so it works wherever you put it.
const NOTES_API_CANDIDATES = ["../notes_api.php", "notes_api.php"];
let   _notesApiUrl = null;      // remembered once one candidate answers with JSON
let   _dbReady     = false;     // true only after a successful load from the DB

async function notesApi(payload) {
  const urls = _notesApiUrl ? [_notesApiUrl] : NOTES_API_CANDIDATES;
  let lastErr = null;
  for (const url of urls) {
    try {
      const res  = await fetch(url, {
        method:      "POST",
        credentials: "same-origin",
        headers:     { "Content-Type": "application/json" },
        body:        JSON.stringify(payload)
      });
      const data = await res.json();      // throws if the URL was a 404 page
      _notesApiUrl = url;
      return data;
    } catch (e) { lastErr = e; }
  }
  throw lastErr || new Error("notes api unreachable");
}

// ── "Please log in" banner (shown if the login session has expired) ─────────
function showNotesAuthBanner() {
  if (document.getElementById("notes-auth-banner")) return;
  const b = document.createElement("div");
  b.id = "notes-auth-banner";
  b.style.cssText = "position:fixed;top:0;left:0;right:0;z-index:99999;background:#7f1d1d;color:#fff;" +
                    "padding:8px 14px;font:13px/1.4 sans-serif;text-align:center;";
  b.innerHTML = 'You are not logged in — notes are saved on this device only. ' +
                '<a href="../auth.php" style="color:#fde68a;font-weight:600">Log in</a>';
  document.body.appendChild(b);
}

// ── Load: this user's notes from the DB, fall back to localStorage ──────────
async function loadNotes() {
  const lid = getLectureId();
  let localCopy = [];
  try { localCopy = JSON.parse(localStorage.getItem(_notesKey) || "[]") || []; }
  catch(_) { localCopy = []; }

  if (lid) {
    try {
      const data = await notesApi({ action: "load", lecture_id: lid });
      if (data.ok) {
        _dbReady = true;
        if (!data.exists && localCopy.length) {
          // First time this account opens this lecture: adopt the notes that were
          // only stored in this browser before login existed, and push them to the DB.
          _notesCache = localCopy;
          renderNotesList();
          saveNotes();
          return;
        }
        _notesCache = data.notes || [];
        try { localStorage.setItem(_notesKey, JSON.stringify(_notesCache)); } catch(_) {}
        renderNotesList();
        return;
      }
      if (data.error === "auth") showNotesAuthBanner();
    } catch(_) { /* network fail — fall through */ }
  }
  // Fallback (not logged in / offline / no lecture_id)
  _notesCache = localCopy;
  renderNotesList();
}

// ── Save: DB (per user) + localStorage mirror as offline backup ─────────────
async function saveNotes() {
  const lid = getLectureId();
  // Local copy first, so nothing is lost even if the DB call fails
  try { localStorage.setItem(_notesKey, JSON.stringify(_notesCache)); }
  catch(_) {}

  // Only write to the DB after a successful load — otherwise a failed load would
  // overwrite the saved notes with an empty list.
  if (lid && _dbReady) {
    try {
      const data = await notesApi({ action: "save", lecture_id: lid, notes: _notesCache });
      if (!data.ok && data.error === "auth") { _dbReady = false; showNotesAuthBanner(); }
    } catch(_) { /* silent — localStorage above is the backup */ }
  }
  // Broadcast to other tabs
  if (typeof broadcastNotesUpdate === "function") broadcastNotesUpdate();
}

// ── Get slide URL active at a given video second ────────────────────────────
function getSlideUrlAtSec(sec) {
  if(!timeline || !stateManager) return null;
  const vidDurMs  = (videoEl.duration || 0) * 1000;
  const eventMs   = timeline.videoToEventMs(sec * 1000, vidDurMs);
  const slideInfo = stateManager.getSlideAtTime(eventMs);
  return (slideInfo && slideInfo.url) ? slideInfo.url : null;
}

// ── CRUD ────────────────────────────────────────────────────────────────────
function addNote(videoSec, type, text) {
  const id       = Date.now().toString(36) + Math.random().toString(36).slice(2,6);
  const slideUrl = getSlideUrlAtSec(videoSec);
  const note     = { id, ts: fmtTime(videoSec), videoSec, type, text, slideUrl };
  const idx      = _notesCache.findIndex(n => n.videoSec > videoSec);
  if(idx === -1) _notesCache.push(note);
  else _notesCache.splice(idx, 0, note);
  saveNotes();
  renderNotesList();
}

function deleteNote(id) {
  _notesCache = _notesCache.filter(n => n.id !== id);
  saveNotes();
  renderNotesList();
}

// ── Render list ─────────────────────────────────────────────────────────────
// NOTE: features.js wraps this function to also redraw the seek-bar markers.
function renderNotesList() {
  const list  = $("notes-list");
  const empty = $("notes-empty");
  if(!list) return;
  list.querySelectorAll(".note-item").forEach(el => el.remove());
  if(!_notesCache.length) { if(empty) empty.style.display = ""; return; }
  if(empty) empty.style.display = "none";

  _notesCache.forEach(note => {
    const el = document.createElement("div");
    el.className = "note-item";
    el.dataset.id = note.id;
    el.innerHTML = `
      <div class="note-item-top">
        <span class="note-ts ${note.type}">${note.ts}</span>
        <span class="note-tag">${note.type}</span>
      </div>
      ${note.text ? `<div class="note-body">${escHtml(note.text)}</div>` : ""}
      <button class="note-del" title="Delete" data-id="${note.id}">✕</button>`;
    el.addEventListener("click", e => {
      if(e.target.closest(".note-del")) return;
      seekToNoteTime(note.videoSec);
    });
    el.querySelector(".note-del").addEventListener("click", e => {
      e.stopPropagation();
      if(confirm("Delete this note?")) deleteNote(note.id);
    });
    list.appendChild(el);
  });
}

// ── Seek ────────────────────────────────────────────────────────────────────
function seekToNoteTime(sec) {
  if(!videoEl || !timeline) return;
  videoEl.currentTime = sec;
  const vidDurMs = (videoEl.duration || 0) * 1000;
  const eventMs  = timeline.videoToEventMs(sec * 1000, vidDurMs);
  if(stateManager) stateManager.rebuildState(eventMs);
  videoEl.play().catch(()=>{});
}

// ── Export JSON ──────────────────────────────────────────────────────────────
function exportJSON() {
  const payload = {
    version: 1,
    videoUrl: _currentVideoUrl,
    exportedAt: new Date().toISOString(),
    notes: _notesCache
  };
  const blob = new Blob([JSON.stringify(payload, null, 2)], {type:"application/json"});
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = "ua-notes-" + new Date().toISOString().slice(0,10) + ".json";
  a.click();
  setTimeout(()=>URL.revokeObjectURL(a.href), 5000);
}

// ── Render arbitrary-script text (Devanagari/Telugu/Tamil/Arabic/CJK/...) to
//    a PNG for embedding in the PDF, since jsPDF's built-in font can't draw
//    those glyphs. Word-wraps at maxWidthMm; falls back to a binary-search
//    character split for a single token that's still too wide (helps
//    scripts that don't reliably use spaces, e.g. Chinese/Japanese). ────────
function _wrapCanvasText(ctx, text, maxWidthPx) {
  const words = String(text).split(/\s+/).filter(Boolean);
  const lines = [];
  let cur = '';
  const pushHardWrapped = (token) => {
    while (ctx.measureText(token).width > maxWidthPx && token.length > 1) {
      let lo = 1, hi = token.length, fit = 1;
      while (lo <= hi) {
        const mid = (lo + hi) >> 1;
        if (ctx.measureText(token.slice(0, mid)).width <= maxWidthPx) { fit = mid; lo = mid + 1; }
        else hi = mid - 1;
      }
      lines.push(token.slice(0, fit));
      token = token.slice(fit);
    }
    return token;
  };
  for (const w of words) {
    const test = cur ? cur + ' ' + w : w;
    if (!cur || ctx.measureText(test).width <= maxWidthPx) {
      cur = test;
    } else {
      lines.push(cur);
      cur = pushHardWrapped(w);
    }
  }
  if (cur) lines.push(pushHardWrapped(cur));
  return lines.length ? lines : [''];
}

function renderTextToPdfImage(text, maxWidthMm, fontSizeMm, colorHex) {
  const PX_PER_MM = 4; // render resolution; keeps text crisp at print size
  const maxWidthPx = Math.max(10, Math.round(maxWidthMm * PX_PER_MM));
  const fontPx = Math.max(8, Math.round(fontSizeMm * PX_PER_MM));
  const lineHeightPx = Math.round(fontPx * 1.55);
  const fontStack = `${fontPx}px "Noto Sans", "Noto Sans Devanagari", "Noto Sans Tamil", ` +
    `"Noto Sans Telugu", "Noto Sans Arabic", "Noto Sans SC", "Noto Sans JP", ` +
    `Arial, sans-serif`;

  const measureCtx = document.createElement('canvas').getContext('2d');
  measureCtx.font = fontStack;
  const lines = _wrapCanvasText(measureCtx, text, maxWidthPx);

  const canvas = document.createElement('canvas');
  canvas.width = maxWidthPx;
  canvas.height = lines.length * lineHeightPx + 4;
  const ctx = canvas.getContext('2d');
  ctx.font = fontStack;
  ctx.fillStyle = colorHex;
  ctx.textBaseline = 'top';
  lines.forEach((line, i) => ctx.fillText(line, 0, i * lineHeightPx + 2));

  return {
    dataUrl: canvas.toDataURL('image/png'),
    widthMm: maxWidthMm,
    heightMm: canvas.height / PX_PER_MM
  };
}

// ── Export PDF (jsPDF + screenshots from slide URLs) ─────────────────────────
async function exportPDF() {
  if(!_notesCache.length) { alert("No notes to export."); return; }

  // Load jsPDF dynamically
  if(!window.jspdf) {
    await new Promise((res,rej) => {
      const s = document.createElement("script");
      s.src = "https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js";
      s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
  }

  const { jsPDF } = window.jspdf;
  const PW = 210, PH = 297;  // A4 mm
  const MARGIN = 14;
  const doc = new jsPDF({ unit:"mm", format:"a4", orientation:"portrait" });

  // Progress UI
  const prog      = $("pdf-progress");
  const progFill  = $("pdf-progress-fill");
  const progLabel = $("pdf-progress-label");
  prog.classList.remove("hidden");
  const setProgress = (pct, label) => {
    progFill.style.width = pct + "%";
    progLabel.textContent = label;
  };

  // Pause video & save state so we can restore after screenshots
  const wasPlaying  = !videoEl.paused;
  const savedTime   = videoEl.currentTime;
  videoEl.pause();

  // ── Helper: seek video → rebuild state → wait for slide+annotations → capture canvas ──
  async function captureAtSec(sec) {
    return new Promise(async (resolve) => {
      if(!videoEl || !timeline || !stateManager || !pdfRenderer || !canvasRenderer) {
        resolve(null); return;
      }

      const vidDurMs = (videoEl.duration || 0) * 1000;
      const eventMs  = timeline.videoToEventMs(sec * 1000, vidDurMs);

      // Seek video (so webcam frame matches, and slide engine has correct time reference)
      videoEl.currentTime = sec;
      await new Promise(r => videoEl.addEventListener("seeked", r, {once:true}));

      // Rebuild all annotations at this timestamp
      stateManager.rebuildState(eventMs);

      // Update slide — wait for it to fully render
      const slideInfo = stateManager.getSlideAtTime(eventMs);
      stateManager.updateSlideForTime(eventMs);

      if(slideInfo && slideInfo.url && slideInfo.url !== pdfRenderer.currentUrl) {
        // Slide image needs to load — wait for it
        await new Promise(r => {
          pdfRenderer.renderSlide(slideInfo.url).then(r).catch(r);
        });
        // Small extra wait for render to fully paint
        await sleep(80);
      } else {
        // Same slide or no slide — just re-render
        if(!slideInfo || !slideInfo.url) pdfRenderer.clearToBackground();
        await sleep(40);
      }

      // Force annotation redraw on top of slide (board rect, not image rect)
      canvasRenderer.invalidate();
      canvasRenderer.render(stateManager, pdfRenderer.annoLayout, eventMs);

      // Composite: merge slideCanvas + drawCanvas into one offscreen canvas
      const W = slideCanvas.width;
      const H = slideCanvas.height;
      const composite = document.createElement("canvas");
      composite.width  = W;
      composite.height = H;
      const ctx2d = composite.getContext("2d");
      ctx2d.drawImage(slideCanvas, 0, 0);   // slide / PDF background
      ctx2d.drawImage(drawCanvas,  0, 0);   // annotations on top

      resolve(composite.toDataURL("image/jpeg", 0.88));
    });
  }

  // ── Cover page ────────────────────────────────────────────────────────────
  doc.setFillColor(13,13,15);
  doc.rect(0, 0, PW, PH, "F");
  doc.setTextColor(167,139,250);
  doc.setFontSize(22); doc.setFont(undefined,"bold");
  doc.text("UA Lecture Notes", PW/2, 40, {align:"center"});
  doc.setTextColor(136,136,153);
  doc.setFontSize(10); doc.setFont(undefined,"normal");
  const shortUrl = _currentVideoUrl.length > 70
    ? _currentVideoUrl.slice(0,67)+"..." : _currentVideoUrl;
  doc.text(shortUrl, PW/2, 52, {align:"center", maxWidth: PW - MARGIN*2});
  doc.text(new Date().toLocaleString(), PW/2, 62, {align:"center"});
  doc.setTextColor(232,232,240);
  doc.setFontSize(14); doc.setFont(undefined,"bold");
  doc.text(_notesCache.length + " notes", PW/2, 76, {align:"center"});

  const typeColors = {
    bookmark:  [59,130,246],
    important: [245,158,11],
    note:      [16,185,129]
  };
  const typeLabels = { bookmark:"Bookmark", important:"Important", note:"Note" };
  const typeEmoji  = { bookmark:"🔖", important:"⭐", note:"📝" };

  // ── One page per note ─────────────────────────────────────────────────────
  for(let i = 0; i < _notesCache.length; i++) {
    const note = _notesCache[i];
    setProgress(Math.round(5 + (i / _notesCache.length) * 88),
      `Capturing note ${i+1} of ${_notesCache.length}…`);

    // Capture canvas at this note's timestamp
    const imgData = await captureAtSec(note.videoSec);

    doc.addPage();
    doc.setFillColor(13,13,15);
    doc.rect(0, 0, PW, PH, "F");

    let curY = MARGIN;

    // Type badge
    const [cr,cg,cb] = typeColors[note.type] || typeColors.note;
    doc.setFillColor(cr,cg,cb);
    doc.roundedRect(MARGIN, curY, 40, 7, 2, 2, "F");
    doc.setTextColor(255,255,255);
    doc.setFontSize(8); doc.setFont(undefined,"bold");
    doc.text((typeEmoji[note.type]||"") + " " + (typeLabels[note.type]||note.type),
             MARGIN + 2, curY + 5);

    // Timestamp (right-aligned)
    doc.setTextColor(167,139,250);
    doc.setFontSize(16); doc.setFont(undefined,"bold");
    doc.text(note.ts, PW - MARGIN, curY + 5, {align:"right"});
    curY += 12;

    // Clickable link
    const playerBase = window.location.href.split("?")[0];
    const noteUrl    = playerBase + "?video=" + encodeURIComponent(_currentVideoUrl) + "&t=" + note.videoSec;
    doc.setTextColor(100,160,255);
    doc.setFontSize(8); doc.setFont(undefined,"normal");
    doc.textWithLink("▶ Open in player at " + note.ts, MARGIN, curY, {url: noteUrl});
    curY += 7;

    // Screenshot (slide + annotations composite)
    const IMG_W = PW - MARGIN * 2;
    const IMG_H = Math.round(IMG_W * 9 / 16);

    if(imgData) {
      doc.addImage(imgData, "JPEG", MARGIN, curY, IMG_W, IMG_H, undefined, "FAST");
    } else {
      doc.setFillColor(25,25,32);
      doc.rect(MARGIN, curY, IMG_W, IMG_H, "F");
      doc.setTextColor(70,70,90);
      doc.setFontSize(9); doc.setFont(undefined,"italic");
      doc.text("No slide at this timestamp", PW/2, curY + IMG_H/2, {align:"center"});
    }
    curY += IMG_H + 5;

    // Divider
    doc.setDrawColor(40,40,55);
    doc.line(MARGIN, curY, PW - MARGIN, curY);
    curY += 5;

    // Note text
    if(note.text) {
      doc.setTextColor(220,220,235);
      doc.setFontSize(11); doc.setFont(undefined,"normal");
      const lines = doc.splitTextToSize(note.text, IMG_W);
      doc.text(lines, MARGIN, curY);
      curY += lines.length * 5.2 + 3;
    } else {
      doc.setTextColor(70,70,90);
      doc.setFontSize(9); doc.setFont(undefined,"italic");
      doc.text("(timestamp bookmark — no text)", MARGIN, curY);
    }

    // Saved translations (accepted via the Translate panel). Rendered as an
    // image, not doc.text() — jsPDF's built-in font is Latin-only and would
    // print blank boxes for Devanagari/Telugu/Tamil/Arabic/CJK. Rasterizing
    // through a canvas uses the browser's own font stack instead, which
    // actually has glyphs for those scripts.
    const savedTranslations = note.translations && Object.keys(note.translations).length
      ? Object.entries(note.translations) : [];
    for(const [langCode, translatedText] of savedTranslations) {
      if(!translatedText) continue;
      if(curY > PH - 30) { doc.addPage(); doc.setFillColor(13,13,15); doc.rect(0,0,PW,PH,"F"); curY = MARGIN; }
      const langName = (typeof TRANSLATE_LANG_NAMES !== "undefined" && TRANSLATE_LANG_NAMES[langCode]) || langCode;
      doc.setTextColor(140,140,165);
      doc.setFontSize(8); doc.setFont(undefined,"italic");
      doc.text(`— ${langName} —`, MARGIN, curY);
      curY += 4;
      const img = renderTextToPdfImage(translatedText, IMG_W, 3.6, "#dcdceb");
      if(curY + img.heightMm > PH - 12) { doc.addPage(); doc.setFillColor(13,13,15); doc.rect(0,0,PW,PH,"F"); curY = MARGIN; }
      doc.addImage(img.dataUrl, "PNG", MARGIN, curY, img.widthMm, img.heightMm);
      curY += img.heightMm + 4;
    }

    // Footer
    doc.setTextColor(50,50,70);
    doc.setFontSize(8); doc.setFont(undefined,"normal");
    doc.text(`${i+1} / ${_notesCache.length}`, PW/2, PH - 6, {align:"center"});
  }

  // ── Restore video to original position ───────────────────────────────────
  setProgress(98, "Restoring player…");
  videoEl.currentTime = savedTime;
  await new Promise(r => videoEl.addEventListener("seeked", r, {once:true}));
  const vidDurMs = (videoEl.duration || 0) * 1000;
  const restoreMs = timeline.videoToEventMs(savedTime * 1000, vidDurMs);
  stateManager.rebuildState(restoreMs);
  if(wasPlaying) videoEl.play().catch(()=>{});

  setProgress(100, "Saving PDF…");
  await sleep(80);
  doc.save("ua-notes-" + new Date().toISOString().slice(0,10) + ".pdf");
  prog.classList.add("hidden");
  progFill.style.width = "0%";
}

// ── Import JSON ──────────────────────────────────────────────────────────────
let _importPending = null;   // parsed notes array waiting for user confirmation

function openImportModal() {
  _importPending = null;
  $("import-drop").classList.remove("drag-over");
  $("import-drop-label").textContent = "Drop .json or click to browse";
  $("import-preview").classList.add("hidden");
  $("import-file-input").value = "";
  showModal("import");
}

function handleImportFile(file) {
  if(!file || !file.name.match(/\.json$/i)) { alert("Please select a .json notes file."); return; }
  const fr = new FileReader();
  fr.onload = e => {
    try {
      const parsed = JSON.parse(e.target.result);
      // Accept either {notes:[...]} or a raw array
      const arr = Array.isArray(parsed) ? parsed : (parsed.notes || []);
      if(!arr.length) { alert("No notes found in this file."); return; }
      _importPending = arr;
      $("import-drop-label").textContent = "✓ " + file.name;
      $("import-preview-label").textContent = arr.length + " note(s) found in file";
      $("import-preview").classList.remove("hidden");
    } catch(_) { alert("Invalid JSON file."); }
  };
  fr.readAsText(file);
}

function confirmImport() {
  if(!_importPending) return;
  const mode = document.querySelector('input[name="import-mode"]:checked')?.value || "merge";
  if(mode === "replace") {
    _notesCache = [..._importPending];
  } else {
    // Merge: add only notes not already present (match by videoSec + text)
    const existing = new Set(_notesCache.map(n => n.videoSec+"_"+(n.text||"")));
    const toAdd    = _importPending.filter(n => !existing.has(n.videoSec+"_"+(n.text||"")));
    _notesCache = [..._notesCache, ...toAdd].sort((a,b)=>a.videoSec-b.videoSec);
  }
  saveNotes();
  renderNotesList();
  closeModal();
  alert(`Imported ${_importPending.length} note(s) successfully.`);
  _importPending = null;
}

// ── Modal helpers ─────────────────────────────────────────────────────────────
function showModal(which) {
  $("notes-modal").classList.remove("hidden");
  $("modal-export").classList.add("hidden");
  $("modal-import").classList.add("hidden");
  $("modal-" + which).classList.remove("hidden");
}
function closeModal() {
  $("notes-modal").classList.add("hidden");
  $("pdf-progress").classList.add("hidden");
  $("pdf-progress-fill").style.width = "0%";
}

// ── Auto-seek from ?t= URL param ─────────────────────────────────────────────
function checkAutoSeek() {
  const t = parseInt(new URLSearchParams(window.location.search).get("t") || "0");
  if(t > 0) {
    const doSeek = () => { if(videoEl.readyState >= 1) seekToNoteTime(t); else videoEl.addEventListener("loadedmetadata", ()=>seekToNoteTime(t), {once:true}); };
    setTimeout(doSeek, 600);
  }
}

// ── Wire up all UI ────────────────────────────────────────────────────────────
async function setupNotesUI(videoUrl) {
  _notesKey = notesKey(videoUrl);
  _currentVideoUrl = videoUrl;
  await loadNotes();
  checkAutoSeek();

  // Add Note
  $("add-note-btn").addEventListener("click", () => {
    const composer = $("note-composer");
    if(!composer.classList.contains("open")) {
      const sec = videoEl ? Math.floor(videoEl.currentTime) : 0;
      $("note-time-badge").textContent = "⏱ " + fmtTime(sec);
      $("note-time-badge").dataset.sec = sec;
      $("note-text").value = "";
      composer.classList.add("open");
      $("note-text").focus();
    } else {
      composer.classList.remove("open");
    }
  });

  // Type selector
  document.querySelectorAll(".note-type-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      document.querySelectorAll(".note-type-btn").forEach(b => b.classList.remove("sel"));
      btn.classList.add("sel");
    });
  });

  $("note-cancel-btn").addEventListener("click", () => $("note-composer").classList.remove("open"));
  $("note-save-btn").addEventListener("click", () => {
    const sec  = parseInt($("note-time-badge").dataset.sec || "0");
    const text = $("note-text").value.trim();
    const type = document.querySelector(".note-type-btn.sel")?.dataset?.type || "note";
    addNote(sec, type, text);
    $("note-composer").classList.remove("open");
  });

  // Export button
  $("export-notes-btn").addEventListener("click", () => {
    $("pdf-progress").classList.add("hidden");
    showModal("export");
  });
  $("export-json-btn").addEventListener("click", () => { exportJSON(); closeModal(); });
  $("export-pdf-btn").addEventListener("click",  async () => {
    $("export-pdf-btn").disabled = true;
    await exportPDF();
    $("export-pdf-btn").disabled = false;
  });
  $("export-close-btn").addEventListener("click", closeModal);

  // Import button
  $("import-notes-btn").addEventListener("click", openImportModal);
  $("import-file-input").addEventListener("change", e => handleImportFile(e.target.files[0]));
  $("import-drop").addEventListener("dragover",  e => { e.preventDefault(); $("import-drop").classList.add("drag-over"); });
  $("import-drop").addEventListener("dragleave", ()  => $("import-drop").classList.remove("drag-over"));
  $("import-drop").addEventListener("drop", e => { e.preventDefault(); $("import-drop").classList.remove("drag-over"); handleImportFile(e.dataTransfer.files[0]); });
  $("import-confirm-btn").addEventListener("click", confirmImport);
  $("import-close-btn").addEventListener("click", closeModal);
  $("notes-modal").addEventListener("click", e => { if(e.target===$("notes-modal")) closeModal(); });

  // Keyboard shortcuts
  document.addEventListener("keydown", e => {
    if(e.target.tagName==="INPUT"||e.target.tagName==="TEXTAREA") return;
    if(e.key==="n"||e.key==="N") $("add-note-btn").click();
    if(e.key==="Escape") closeModal();
  });
  $("note-text").addEventListener("keydown", e => {
    if(e.key==="Enter"&&(e.ctrlKey||e.metaKey)) $("note-save-btn").click();
  });
}
