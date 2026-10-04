<?php header("Content-Type: application/javascript"); ?>
"use strict";

// ════════════════════════════════════════════════════════════════════════════
// PLAYER CONTROLLER  (reconstructed from pk05.js usage)
// ════════════════════════════════════════════════════════════════════════════
// Every canvasRenderer.render() call passes pdf.annoLayout (the 16:9 board
// rect), never pdf.slideLayout (the slide-image rect). See renderers.js.
// ════════════════════════════════════════════════════════════════════════════
class PlayerController {
  constructor({video,timeline,stateManager,pdfRenderer,canvasRenderer}){
    this.video=video;this.tl=timeline;this.sm=stateManager;this.pdf=pdfRenderer;this.cr=canvasRenderer;
    this._rafId=null;this._running=false;this._lastVideoMs=-1;this._seeking=false;this._seekToken=0;
    this._ssActive=false;
    video.addEventListener("seeking",()=>{this._seeking=true;this._seekToken++;});
    video.addEventListener("seeked",()=>{this._seeking=false;this._rebuildAtVideoPosition();});
  }
  start(){if(this._running)return;this._running=true;this._loop();}
  stop(){this._running=false;if(this._rafId){cancelAnimationFrame(this._rafId);this._rafId=null;}}
  _loop(){if(!this._running)return;this._tick();this._rafId=requestAnimationFrame(()=>this._loop());}
  _updateScreenShareMode(eventMs){
    const active=this.sm.isScreenShareActive(eventMs);
    if(active===this._ssActive)return;
    this._ssActive=active;
    setScreenShareMode(active);
  }
  _rebuildAtVideoPosition(){
    if(!this.video||!this.video.duration)return;
    const token=this._seekToken, ms=this.tl.videoToEventMs(this.video.currentTime*1000,this.video.duration*1000);
    this.sm.rebuildState(ms); const sid=this.sm.currentSlideUrl; const url=sid;
    this._updateScreenShareMode(ms);
    if(url){this.pdf.renderSlide(url).then(()=>{if(token===this._seekToken)this.cr.invalidate(),this.cr.render(this.sm,this.pdf.annoLayout,ms);}).catch(()=>{});}
    else {this.pdf.clearToBackground();this.cr.invalidate();this.cr.render(this.sm,this.pdf.annoLayout,ms);}
    this._lastVideoMs=this.video.currentTime*1000;
  }
  _tick(){
    const vid=this.video;if(!vid||vid.readyState<2||this._seeking)return;
    const videoMs=vid.currentTime*1000, durMs=(vid.duration||0)*1000;
    const eventMs=this.tl.videoToEventMs(videoMs,durMs);
    this._updateScreenShareMode(eventMs);
    if(this._lastVideoMs<0){this.sm.rebuildState(eventMs);this._updateSlide(eventMs);this._lastVideoMs=videoMs;return;}
    const lastEventMs=this.tl.videoToEventMs(this._lastVideoMs,durMs);
    if(videoMs<this._lastVideoMs-200 || eventMs<lastEventMs-500){this._rebuildAtVideoPosition();return;}
    this._lastVideoMs=videoMs;
    const newEvs=this.tl.getNewEvents(eventMs); let slideChanged=false;
    for(const ev of newEvs){const prev=this.sm.currentSid;this.sm.applyEvent(ev);if(this.sm.currentSid!==prev)slideChanged=true;}
    const changed=this.sm.updateSlideForTime(eventMs);
    if(changed||slideChanged)this._updateSlide(eventMs);else this.cr.render(this.sm,this.pdf.annoLayout,eventMs);
  }
  _updateSlide(eventMs){
    const token=this._seekToken,url=this.sm.currentSlideUrl;
    if(url)this.pdf.renderSlide(url).then(()=>{if(token===this._seekToken){this.cr.invalidate();this.cr.render(this.sm,this.pdf.annoLayout,eventMs);}}).catch(()=>{});
    else{this.pdf.clearToBackground();this.cr.invalidate();this.cr.render(this.sm,this.pdf.annoLayout,eventMs);}
  }
}

// Move the webcam <video> element between its normal sidebar slot and the
// main slide area. While the instructor is screen-sharing, the recording
// itself shows their shared screen, so that's what should fill the main
// viewport — the whiteboard canvases underneath aren't meaningful for that
// span. Reparenting (not cloning) the same playing <video> node keeps
// playback/audio uninterrupted.
function setScreenShareMode(active){
  const sc=$("slide-container"), wrap=$("webcam-wrap");
  if(!sc||!wrap||!videoEl)return;
  sc.classList.toggle("ss-mode",active);
  if(active){ if(videoEl.parentNode!==sc)sc.appendChild(videoEl); }
  else{ if(videoEl.parentNode!==wrap)wrap.appendChild(videoEl); }
}

// ════════════════════════════════════════════════════════════════════════════
// APP LOGIC  — ?video=...&pdf=... mode
// ════════════════════════════════════════════════════════════════════════════
let timeline=null,stateManager=null,pdfRenderer=null,canvasRenderer=null,playerCtrl=null;

function resizeCanvases(){
  const container=$("slide-container");if(!container)return;
  const rect=container.getBoundingClientRect();
  const dpr=window.devicePixelRatio||1;
  const w=Math.max(1,Math.round(rect.width*dpr));
  const h=Math.max(1,Math.round(rect.height*dpr));
  const udc=$("user-draw-canvas");
  if(slideCanvas.width===w&&slideCanvas.height===h&&udc&&udc.width===w)return;
  slideCanvas.width=w;slideCanvas.height=h;drawCanvas.width=w;drawCanvas.height=h;
  if(udc){udc.width=w;udc.height=h;}
  if(stateManager){const lw=Math.max(1,Math.floor(rect.width)),lh=Math.max(1,Math.floor(rect.height));stateManager.updateCanvasDimensions(lw,lh);}
  if(pdfRenderer&&stateManager){
    if(stateManager.currentSlideUrl)pdfRenderer.renderSlide(stateManager.currentSlideUrl).then(()=>{if(canvasRenderer)canvasRenderer.invalidate();}).catch(()=>{});
    else{pdfRenderer.clearToBackground();if(canvasRenderer)canvasRenderer.invalidate();}
  }
}
window.addEventListener("resize",resizeCanvases);

// ── Online entry point (fetches data.json from network) ──────────────────────
async function loadSession(videoUrl, pdfUrl) {
  if(!videoUrl){ alert("Please provide a video URL."); return; }
  showLoading("Fetching event data…");
  try {
    const jsonUrl = videoUrl.substring(0, videoUrl.lastIndexOf("/")+1) + "data.json";
    const r = await fetch(jsonUrl);
    if(!r.ok) throw new Error("Could not load data.json ("+r.status+"). Check CORS or URL.");
    const raw = await r.json();
    await loadLesson(raw, videoUrl, pdfUrl || "");
  } catch(err) {
    hideLoading();
    setupSection.classList.remove("hidden");
    playerSection.classList.add("hidden");
    alert("Error: "+err.message);
  }
}

// ── Shared core: given parsed JSON + video URL (or blob URL) + optional PDF ──
async function loadLesson(raw, videoUrl, pdfUrl) {
  try {
    showLoading("Parsing events…"); await sleep(10);

    // Stop any existing player
    if(playerCtrl){ playerCtrl.stop(); playerCtrl=null; }
    // Revoke previous blob URLs to free memory
    if(videoEl.src && videoEl.src.startsWith("blob:")) URL.revokeObjectURL(videoEl.src);

    timeline = new TimelineEngine();
    timeline.load(raw);

    showLoading("Setting up player…"); await sleep(10);
    hideLoading();
    setupSection.classList.add("hidden");
    playerSection.classList.remove("hidden");
    await sleep(50);
    resizeCanvases();

    const cont = $("slide-container");
    const rect = cont ? cont.getBoundingClientRect() : {width:1280,height:720};

    pdfRenderer    = new PDFRenderer(slideCanvas);
    canvasRenderer = new CanvasRenderer(drawCanvas);
    stateManager   = new StateManager(timeline, Math.max(1,Math.floor(rect.width)), Math.max(1,Math.floor(rect.height)));
    stateManager.buildSlideIndex();

    // Expose on window: these were plain top-level `let` bindings, which
    // never reach `window.*`. UA_DEBUG (state-manager.js) reads window.stateManager
    // and window.timeline — without this it silently no-ops forever.
    window.timeline = timeline;
    window.stateManager = stateManager;
    window.pdfRenderer = pdfRenderer;
    window.canvasRenderer = canvasRenderer;

    // Apply current board color
    pdfRenderer.bgColor = boardColor;

    // Load optional PDF as background fallback
    if(pdfUrl){
      try { await pdfRenderer.loadPdf(pdfUrl); } catch(_) {}
    }

    stateManager.updateSlideForTime(0);
    if(stateManager.currentSlideUrl){
      try{ await pdfRenderer.renderSlide(stateManager.currentSlideUrl); }catch(_){}
    } else if(pdfRenderer.pdfDoc){
      await pdfRenderer.renderPdfPage(1);
    } else {
      pdfRenderer.clearToBackground();
    }

    // Set video source (works for both http URLs and blob: URLs)
    videoEl.autoplay = true;
    videoEl.src = videoUrl;
    videoEl.load();
    videoEl.addEventListener("canplay", () => {
      const pp = videoEl.play();
      if(pp !== undefined) pp.catch(() => {
        videoEl.muted = true; videoEl.play().catch(()=>{});
        const uh = () => { videoEl.muted=false; document.removeEventListener("click",uh,true); };
        document.addEventListener("click", uh, true);
      });
    }, {once:true});
    videoEl.addEventListener("loadedmetadata", () => {
      const eMs = timeline.videoToEventMs(0, (videoEl.duration||0)*1000);
      stateManager.rebuildState(eMs);
      canvasRenderer.render(stateManager, pdfRenderer.annoLayout);
    }, {once:true});

    playerCtrl = new PlayerController({video:videoEl, timeline, stateManager, pdfRenderer, canvasRenderer});
    window.playerCtrl = playerCtrl;
    setupControls();
    setupColorToolbar();
    setupNotesUI(videoUrl);
    playerCtrl.start();
    initNoteSync();
    // Build chapter nav (small delay to let slideTimeline settle)
    setTimeout(() => { buildChapterNav(); setTimeout(captureChapterThumbs, 2000); }, 800);
    // Re-render note markers once video duration known
    setTimeout(renderNoteMarkers, 1200);

    statsBar.textContent = `${timeline.events.length.toLocaleString()} events · ${timeline.formatTime(timeline.getDurationMs())}`;
    statsBar.classList.remove("hidden");

  } catch(err) {
    hideLoading();
    setupSection.classList.remove("hidden");
    playerSection.classList.add("hidden");
    alert("Error loading lesson: "+err.message);
  }
}

function setupControls(){
  const durMs=timeline.getDurationMs();
  seekBarEl.max=Math.ceil(durMs/1000);
  videoEl.addEventListener("loadedmetadata",()=>{const d=Math.max(videoEl.duration,durMs/1000);seekBarEl.max=Math.ceil(d);updateTimeDisplay(videoEl.currentTime,d);});
  videoEl.addEventListener("play",()=>{$("play-icon").classList.add("hidden");$("pause-icon").classList.remove("hidden");});
  videoEl.addEventListener("pause",()=>{$("pause-icon").classList.add("hidden");$("play-icon").classList.remove("hidden");});

  // Buffering
  let isSlide=false,isVideo=false,wasPlaying=false;
  window.showBuffering=e=>{if(e&&e.type==="waiting")isVideo=true;else isSlide=true;bufferingOverlay.classList.remove("hidden");if(isSlide&&!videoEl.paused){wasPlaying=true;videoEl.pause();}};
  window.hideBuffering=e=>{if(e&&(e.type==="playing"||e.type==="canplay"))isVideo=false;else isSlide=false;if(!isSlide&&!isVideo){bufferingOverlay.classList.add("hidden");if(wasPlaying&&videoEl.paused){wasPlaying=false;videoEl.play().catch(()=>{});}}};
  videoEl.addEventListener("waiting",window.showBuffering);
  videoEl.addEventListener("playing",window.hideBuffering);
  videoEl.addEventListener("canplay",window.hideBuffering);

  // Idle controls
  let idleTimer=null;
  const resetIdle=()=>{playerSection.classList.remove("idle");if(idleTimer)clearTimeout(idleTimer);if(!videoEl.paused)idleTimer=setTimeout(()=>playerSection.classList.add("idle"),3000);};
  playerSection.addEventListener("mousemove",resetIdle);
  playerSection.addEventListener("mousedown",resetIdle);
  playerSection.addEventListener("touchstart",resetIdle);
  const cb=$("controls-bar");
  if(cb){cb.addEventListener("mouseenter",()=>{if(idleTimer)clearTimeout(idleTimer);playerSection.classList.remove("idle");});cb.addEventListener("mouseleave",resetIdle);}
  videoEl.addEventListener("pause",()=>{playerSection.classList.remove("idle");if(idleTimer)clearTimeout(idleTimer);});
  videoEl.addEventListener("play",resetIdle);

  // Seek bar fill helper
  const getBuffEnd=()=>{if(!videoEl.buffered||!videoEl.duration)return 0;const ct=videoEl.currentTime;let mx=0;for(let i=0;i<videoEl.buffered.length;i++)if(videoEl.buffered.start(i)<=ct+.5)mx=Math.max(mx,videoEl.buffered.end(i));return mx;};
  const seeFill=()=>{
    const mn=parseFloat(seekBarEl.min)||0,mx=parseFloat(seekBarEl.max)||100,v=parseFloat(seekBarEl.value)||0;
    const p=mx>mn?((v-mn)/(mx-mn))*100:0;
    const be=getBuffEnd(),buf=videoEl.duration?Math.min(100,(be/videoEl.duration)*100):0;
    seekBarEl.style.background=`linear-gradient(to right,#a78bfa ${p}%,rgba(255,255,255,.35) ${p}%,rgba(255,255,255,.35) ${buf}%,#2e2e38 ${buf}%)`;
    const bb=$("buffer-bar");if(bb&&videoEl.duration)bb.style.width=(Math.min(100,(be/videoEl.duration)*100))+"%";
  };
  videoEl.addEventListener("progress",seeFill);
  videoEl.addEventListener("loadedmetadata",seeFill);
  videoEl.addEventListener("canplay",seeFill);
  videoEl.addEventListener("timeupdate",()=>{if(!seekBarEl._drag){seekBarEl.value=Math.floor(videoEl.currentTime);seeFill();}updateTimeDisplay(videoEl.currentTime,videoEl.duration||durMs/1000);});

  // Seek bar
  seekBarEl.addEventListener("mousedown",()=>seekBarEl._drag=true);
  seekBarEl.addEventListener("touchstart",()=>seekBarEl._drag=true);
  seekBarEl.addEventListener("input",()=>{videoEl.currentTime=parseFloat(seekBarEl.value);seeFill();});
  seekBarEl.addEventListener("mouseup",()=>seekBarEl._drag=false);
  seekBarEl.addEventListener("touchend",()=>seekBarEl._drag=false);

  // Seek tooltip
  const st=$("seek-tooltip"),stt=$("seek-tooltip-time"),shd=$("seek-hover-dot");
  seekBarEl.addEventListener("mousemove",e=>{const r=seekBarEl.getBoundingClientRect();const pos=Math.max(0,Math.min(1,(e.clientX-r.left)/r.width));/* thumbnail preview handles time display now — only show dot */if(shd){shd.classList.remove("hidden");shd.style.left=(pos*100)+"%";}if(st)st.classList.add("hidden");/* sync seek-tooltip-time for compatibility */if(stt)stt.textContent=fmtTime(pos*parseFloat(seekBarEl.max));});
  seekBarEl.addEventListener("mouseenter",()=>{/* thumbnail handles preview */if(shd)shd.classList.remove("hidden");});
  seekBarEl.addEventListener("mouseleave",()=>{if(st)st.classList.add("hidden");if(shd)shd.classList.add("hidden");});

  // Prefetch slides
  let pft=null;
  const triggerPrefetch=()=>{if(pft)return;pft=setTimeout(()=>{pft=null;if(!stateManager||!pdfRenderer||!timeline||!videoEl.duration)return;const cs=videoEl.currentTime,vdm=(videoEl.duration||0)*1000;const ce=timeline.videoToEventMs(cs*1000,vdm),ae=timeline.videoToEventMs(Math.min((cs+120)*1000,vdm),vdm);const inf=new Set();for(const e of stateManager.slideTimeline){if(e.timeMs>ae)break;if(e.timeMs<ce||!e.url||pdfRenderer.imgCache.has(e.url)||inf.has(e.url))continue;inf.add(e.url);pdfRenderer.prefetch(e.url);}},3000);};
  videoEl.addEventListener("seeked",()=>{seeFill();if(pft){clearTimeout(pft);pft=null;}triggerPrefetch();});
  videoEl.addEventListener("progress",triggerPrefetch);

  // Speed
  $("speed-select").addEventListener("change",()=>videoEl.playbackRate=parseFloat($("speed-select").value));

  // Volume
  const setVol=v=>{videoEl.volume=parseFloat(v);if(volSlider)volSlider.value=v;const pct=parseFloat(v)*100;if(volSlider)volSlider.style.background=`linear-gradient(to right,#a78bfa ${pct}%,#2e2e38 ${pct}%)`;if(v==0){$("vol-on-icon").classList.add("hidden");$("vol-off-icon").classList.remove("hidden");}else{$("vol-off-icon").classList.add("hidden");$("vol-on-icon").classList.remove("hidden");}};
  if(volSlider)volSlider.addEventListener("input",()=>setVol(volSlider.value));
  $("volume-btn").addEventListener("click",()=>{if(videoEl.volume>0){videoEl.dataset.lastVol=videoEl.volume;setVol(0);}else setVol(videoEl.dataset.lastVol||1);});
  setVol(1);

  // Buttons
  $("play-btn").addEventListener("click",togglePlay);
  $("rewind-btn").addEventListener("click",()=>videoEl.currentTime=Math.max(0,videoEl.currentTime-10));
  $("forward-btn").addEventListener("click",()=>videoEl.currentTime=Math.min(videoEl.duration||Infinity,videoEl.currentTime+10));

  // Settings
  $("settings-btn").addEventListener("click",e=>{e.stopPropagation();$("settings-popup").classList.toggle("hidden");});
  document.addEventListener("click",e=>{const sp=$("settings-popup");if(sp&&!sp.classList.contains("hidden")&&!sp.contains(e.target)&&e.target!==$("settings-btn"))sp.classList.add("hidden");});

  // Draw mode toggle in controls bar
  const drawCtrlBtn=$("draw-mode-ctrl-btn");
  if(drawCtrlBtn){
    drawCtrlBtn.addEventListener("click",()=>{
      toggleUserDraw(!userDrawState.active);
      drawCtrlBtn.classList.toggle("active",userDrawState.active);
    });
  }

  // Fullscreen
  const fsBtn=$("fullscreen-btn");
  if(fsBtn){
    fsBtn.addEventListener("click",()=>{const fs=!!(document.fullscreenElement||document.webkitFullscreenElement);if(!fs){(playerSection.requestFullscreen||playerSection.webkitRequestFullscreen||function(){}).call(playerSection);}else{(document.exitFullscreen||document.webkitExitFullscreen||function(){}).call(document);}});
    const onFS=()=>{const fs=!!(document.fullscreenElement||document.webkitFullscreenElement);$("fs-enter-icon").classList.toggle("hidden",fs);$("fs-exit-icon").classList.toggle("hidden",!fs);playerSection.classList.toggle("is-fullscreen",fs);setTimeout(resizeCanvases,100);};
    document.addEventListener("fullscreenchange",onFS);document.addEventListener("webkitfullscreenchange",onFS);
  }

  // Keyboard
  document.addEventListener("keydown",e=>{
    const tag=e.target.tagName;
    if(tag==="INPUT"||tag==="TEXTAREA"||tag==="SELECT"||e.target.isContentEditable)return;
    if(e.code==="Space"){e.preventDefault();togglePlay();}
    if(e.code==="ArrowRight")videoEl.currentTime+=5;
    if(e.code==="ArrowLeft")videoEl.currentTime-=5;
    if(e.code==="ArrowUp")setVol(Math.min(1,videoEl.volume+.1));
    if(e.code==="ArrowDown")setVol(Math.max(0,videoEl.volume-.1));
  });

  // Mobile double-tap
  let lastTap=0,tapTO=null;
  const sp2=$("slide-panel");
  const showGF=(t,pos)=>{const gf=$("gesture-feedback");if(!gf)return;gf.textContent=t;gf.className="";void gf.offsetWidth;gf.className="show "+pos;};
  if(sp2)sp2.addEventListener("click",e=>{
    if(e.target.closest("#controls-bar")||e.target.closest("button"))return;
    const now=Date.now(),diff=now-lastTap;
    if(diff<300&&diff>0){clearTimeout(tapTO);lastTap=0;const r=sp2.getBoundingClientRect();if(e.clientX-r.left>r.width/2){videoEl.currentTime+=10;showGF("+10s","right");}else{videoEl.currentTime-=10;showGF("-10s","left");};}
    else{lastTap=now;tapTO=setTimeout(()=>{togglePlay();showGF(videoEl.paused?"Play":"Pause","center");},300);}
  });
}

function togglePlay(){if(videoEl.paused)videoEl.play().catch(()=>{});else videoEl.pause();}
function updateTimeDisplay(cur,dur){const c=$("curr-time"),t=$("tot-time");if(c)c.textContent=fmtTime(cur);if(t)t.textContent=" / "+fmtTime(dur||0);}

// ── Color Toolbar ─────────────────────────────────────────────────────────────
let penOverrideMode = false;   // false = use lecture colors, true = override with penOverrideColor
let penOverrideColor = "#FFEA00";
let boardColor = "#111111";

function lum(hex){
  const h=hex.replace("#","");
  return 0.299*parseInt(h.substr(0,2),16)+0.587*parseInt(h.substr(2,2),16)+0.114*parseInt(h.substr(4,2),16);
}

function setupColorToolbar(){
  // ── Board color ──
  const setBoardColor = hex => {
    boardColor = hex;
    if(pdfRenderer) pdfRenderer.bgColor = hex;
    const inp = $("bg-custom"); if(inp) inp.value = hex;
    document.querySelectorAll(".bg-swatch").forEach(s => s.classList.toggle("sel", s.dataset.bg && s.dataset.bg.toLowerCase()===hex.toLowerCase()));
    // Re-render current slide to apply new board color
    if(pdfRenderer && stateManager){
      if(stateManager.currentSlideUrl) pdfRenderer.renderSlide(stateManager.currentSlideUrl).then(()=>{if(canvasRenderer)canvasRenderer.invalidate();}).catch(()=>{});
      else pdfRenderer.clearToBackground();
    }
    refreshPenWarn();
  };
  document.querySelectorAll(".bg-swatch").forEach(s =>
    s.addEventListener("click", () => setBoardColor(s.dataset.bg))
  );
  const bgInp = $("bg-custom");
  if(bgInp) bgInp.addEventListener("input", e => setBoardColor(e.target.value));

  // ── Pen color override ──
  const refreshPenUI = () => {
    const btn = $("pen-mode-btn");
    if(btn){ btn.textContent = penOverrideMode ? "OVERRIDE" : "LECTURE"; btn.classList.toggle("custom", penOverrideMode); }
    const inp = $("pen-custom"); if(inp) inp.value = penOverrideColor;
    document.querySelectorAll(".pen-swatch").forEach(s =>
      s.classList.toggle("sel", penOverrideMode && s.dataset.pen && s.dataset.pen.toLowerCase()===penOverrideColor.toLowerCase())
    );
    refreshPenWarn();
    if(canvasRenderer) canvasRenderer.invalidate();
  };

  const setPenColor = hex => {
    penOverrideColor = hex;
    penOverrideMode = true;
    refreshPenUI();
  };

  document.querySelectorAll(".pen-swatch").forEach(s =>
    s.addEventListener("click", () => setPenColor(s.dataset.pen))
  );
  const penInp = $("pen-custom");
  if(penInp) penInp.addEventListener("input", e => setPenColor(e.target.value));

  const penModeBtn = $("pen-mode-btn");
  if(penModeBtn) penModeBtn.addEventListener("click", () => {
    penOverrideMode = !penOverrideMode;
    refreshPenUI();
  });

  refreshPenUI();
}

function refreshPenWarn(){
  const warn = $("pen-warn"); if(!warn) return;
  const show = penOverrideMode && Math.abs(lum(penOverrideColor) - lum(boardColor)) < 60;
  warn.classList.toggle("hidden", !show);
}

// Pen-override is applied at render time by wrapping getAllStrokes.
// This loads AFTER copy-fix.js, so it wraps the patched implementation.
const _origGetAllStrokes = StateManager.prototype.getAllStrokes;
StateManager.prototype.getAllStrokes = function(){
  const strokes = _origGetAllStrokes.call(this);
  if(!penOverrideMode) return strokes;
  // Return shallow copies with overridden color (don't mutate originals)
  return strokes.map(s => {
    if(s.isE || s.tool==="eraser") return s; // never recolor eraser
    return Object.assign({}, s, {color: penOverrideColor});
  });
};
