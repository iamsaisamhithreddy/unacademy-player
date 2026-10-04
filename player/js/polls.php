<?php header("Content-Type: application/javascript"); header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0"); ?>
/* ============================================================================
 * UA Lecture Player — live-class POLLS in replay
 * ----------------------------------------------------------------------------
 * data.json records polls on the "pl" plugin:
 *   opl  (open)  data: { id, a:<correct answer text>, t:<seconds>,
 *                        data:[{answer, order}, …] }
 *   cpl  (close) data: { id, pid, data:[{answer, order, count, percentage,
 *                        isCorrect, hasCorrectAnswer, showPercentage}, …] }
 * The QUESTION TEXT is never recorded — the educator asks it out loud / on the
 * board — so the card tells the viewer to listen to the video.
 *
 * What this adds (self-contained; loads after player.php):
 *   • a poll card on the board while a poll is open (options + countdown);
 *     the viewer can pick an answer (saved in this browser only)
 *   • class results when the poll closes (bars, votes, correct answer, and
 *     ✓/✗ against the viewer's own pick), shown for RESULT_SHOW_MS
 *   • a "Polls" sidebar tab listing every poll; click = jump a few seconds
 *     before it opens so the question can be heard
 *   • poll markers on the seek bar
 * Times use timeline.eventToVideoMs(), i.e. exactly when UA shows the poll.
 * ========================================================================== */
(function () {
  "use strict";

  var RESULT_SHOW_MS = 12000;   // how long results stay up after a poll closes
  var LEAD_IN_SEC    = 8;       // "jump to poll" lands this many seconds early
  var LS_PREFIX      = "ua_poll_ans_";

  var polls = [];               // [{id, n, openMs, closeMs, durSec, correct, options:[…], results:[…]|null}]
  var builtFor = null;          // timeline object the list was built from
  var dismissedId = null;       // poll whose card the viewer closed
  var lastRenderKey = "";

  // ── storage (per-viewer convenience only; safe if blocked) ────────────────
  function getAns(id) { try { return localStorage.getItem(LS_PREFIX + id); } catch (_) { return null; } }
  function setAns(id, v) { try { localStorage.setItem(LS_PREFIX + id, v); } catch (_) {} }

  // util.js declares `const videoEl` - visible by name, but it is not a window property
  function V() { try { return typeof videoEl !== "undefined" ? videoEl : null; } catch (_) { return null; } }

  function fmt(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    return (h ? h + ":" + String(m).padStart(2, "0") : m) + ":" + String(s).padStart(2, "0");
  }
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }

  // ── styles ─────────────────────────────────────────────────────────────────
  var css = document.createElement("style");
  css.textContent = [
    "#poll-card{position:absolute;right:18px;top:18px;z-index:40;width:min(340px,calc(100% - 36px));",
    " background:var(--surface,#16161a);border:1px solid var(--border,#2e2e38);border-radius:12px;",
    " box-shadow:0 10px 30px rgba(0,0,0,.45);color:var(--text,#e8e8f0);font:13px/1.4 system-ui,sans-serif;",
    " padding:14px 14px 12px;pointer-events:auto;animation:pollIn .18s ease-out}",
    "#poll-card.hidden{display:none}",
    "@keyframes pollIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}",
    ".poll-head{display:flex;align-items:center;gap:8px;margin-bottom:8px}",
    ".poll-tag{font-size:10px;font-weight:800;letter-spacing:.08em;padding:3px 7px;border-radius:5px;background:#dc2626;color:#fff}",
    ".poll-tag.done{background:var(--hi,#a78bfa)}",
    ".poll-title{font-weight:700;flex:1}",
    ".poll-x{background:none;border:none;color:var(--text-dim,#888899);font-size:16px;cursor:pointer;line-height:1;padding:2px 4px}",
    ".poll-x:hover{color:var(--text,#e8e8f0)}",
    ".poll-q{font-size:12px;color:var(--text-dim,#888899);margin-bottom:10px}",
    ".poll-timer{height:4px;border-radius:2px;background:var(--surface2,#1e1e24);overflow:hidden;margin-bottom:10px}",
    ".poll-timer>i{display:block;height:100%;background:#dc2626;transition:width .25s linear}",
    ".poll-opt{display:block;width:100%;text-align:left;margin:6px 0;padding:9px 11px;border-radius:8px;cursor:pointer;",
    " background:var(--surface2,#1e1e24);border:1px solid var(--border,#2e2e38);color:inherit;font:inherit;position:relative;overflow:hidden}",
    ".poll-opt:hover{border-color:var(--hi,#a78bfa)}",
    ".poll-opt.picked{border-color:var(--hi,#a78bfa);box-shadow:inset 0 0 0 1px var(--hi,#a78bfa)}",
    ".poll-opt[disabled]{cursor:default}",
    ".poll-opt .bar{position:absolute;left:0;top:0;bottom:0;background:rgba(167,139,250,.18);z-index:0}",
    ".poll-opt.correct .bar{background:rgba(52,211,153,.25)}",
    ".poll-opt.correct{border-color:#34d399}",
    ".poll-opt .row{position:relative;z-index:1;display:flex;justify-content:space-between;gap:8px}",
    ".poll-opt .meta{color:var(--text-dim,#888899);font-size:12px;white-space:nowrap}",
    ".poll-foot{margin-top:8px;font-size:12px;color:var(--text-dim,#888899)}",
    ".poll-foot b.ok{color:#34d399}.poll-foot b.bad{color:#f87171}",
    "#poll-markers-layer{position:absolute;left:0;top:0;width:100%;height:100%;pointer-events:none;z-index:2}",
    ".poll-marker{position:absolute;top:50%;width:9px;height:9px;transform:translate(-50%,-50%) rotate(45deg);",
    " background:#f97316;border:1.5px solid rgba(0,0,0,.45);pointer-events:all;cursor:pointer}",
    ".poll-marker:hover{transform:translate(-50%,-50%) rotate(45deg) scale(1.5)}",
    "#poll-list{padding:10px 12px;overflow:auto;height:100%}",
    ".poll-item{border:1px solid var(--border,#2e2e38);border-radius:8px;padding:9px 10px;margin-bottom:8px;cursor:pointer;background:var(--surface2,#1e1e24)}",
    ".poll-item:hover{border-color:var(--hi,#a78bfa)}",
    ".poll-item .top{display:flex;justify-content:space-between;font-weight:700;margin-bottom:4px}",
    ".poll-item .top span{color:var(--hi,#a78bfa);font-weight:600}",
    ".poll-item .sub{font-size:12px;color:var(--text-dim,#888899)}",
    ".poll-item .sub b.ok{color:#34d399}.poll-item .sub b.bad{color:#f87171}",
    ".poll-empty{color:var(--text-dim,#888899);font-size:12px;padding:6px 2px}"
  ].join("\n");
  document.head.appendChild(css);

  // ── markup: card on the board, seek-bar layer, sidebar tab ────────────────
  var card = document.createElement("div");
  card.id = "poll-card"; card.className = "hidden";
  var sc = document.getElementById("slide-container");
  if (sc) sc.appendChild(card);

  var seekCont = document.getElementById("seek-bar-container");
  var markers = document.createElement("div");
  markers.id = "poll-markers-layer";
  if (seekCont) seekCont.appendChild(markers);

  var tabs = document.getElementById("sidebar-tabs");
  var chaptersPane = document.getElementById("stab-chapters");
  var listEl = null;
  if (tabs && chaptersPane) {
    var btn = document.createElement("button");
    btn.className = "stab-btn"; btn.dataset.stab = "stab-polls";
    btn.innerHTML = '📊 Polls <span id="poll-count" style="opacity:.7"></span>';
    tabs.appendChild(btn);                     // features.js wires every .stab-btn
    var pane = document.createElement("div");
    pane.id = "stab-polls"; pane.className = "stab-pane";
    pane.innerHTML = '<div class="panel-label">LIVE-CLASS POLLS</div><div id="poll-list"><div class="poll-empty">Load a lecture to see its polls.</div></div>';
    chaptersPane.parentNode.insertBefore(pane, chaptersPane.nextSibling);
    listEl = pane.querySelector("#poll-list");
  }

  // ── build the poll list from the loaded timeline ──────────────────────────
  function build(tl) {
    polls = []; builtFor = tl; dismissedId = null; lastRenderKey = "";
    var byId = {};
    for (var i = 0; i < tl.events.length; i++) {
      var ev = tl.events[i];
      if (ev.plugin !== "pl" || !ev.data) continue;
      var e = ev.data.e, d = ev.data.data || {};
      var vMs = tl.eventToVideoMs ? tl.eventToVideoMs(ev.timeMs) : ev.timeMs;
      if (e === "opl" && d.id) {
        var opts = (d.data || []).slice().sort(function (a, b) { return (a.order || 0) - (b.order || 0); })
          .map(function (o) { return String(o.answer); });
        var p = { id: String(d.id), openMs: vMs, closeMs: null, durSec: +d.t || 0,
                  correct: d.a != null && d.a !== "" ? String(d.a) : null, options: opts, results: null };
        byId[p.id] = p; polls.push(p);
      } else if (e === "cpl" && d.id && byId[String(d.id)]) {
        var q = byId[String(d.id)];
        q.closeMs = vMs;
        q.results = (d.data || []).slice().sort(function (a, b) { return (a.order || 0) - (b.order || 0); })
          .map(function (r) { return { answer: String(r.answer), count: +r.count || 0,
                                       pct: parseFloat(r.percentage) || 0, isCorrect: !!r.isCorrect }; });
        var c = q.results.filter(function (r) { return r.isCorrect; })[0];
        if (c && !q.correct) q.correct = c.answer;
      }
    }
    polls.forEach(function (p, i) {
      p.n = i + 1;
      if (p.closeMs == null) p.closeMs = p.openMs + Math.max(10, p.durSec) * 1000;   // never closed: use its timer
    });
    var cnt = document.getElementById("poll-count");
    if (cnt) cnt.textContent = polls.length ? "(" + polls.length + ")" : "";
    renderList(); renderMarkers();
  }

  function seekTo(sec) {
    var v = V(); if (!v) return;
    v.currentTime = Math.max(0, sec);
  }

  function renderList() {
    if (!listEl) return;
    if (!polls.length) { listEl.innerHTML = '<div class="poll-empty">No polls in this lecture.</div>'; return; }
    listEl.innerHTML = "";
    polls.forEach(function (p) {
      var votes = p.results ? p.results.reduce(function (s, r) { return s + r.count; }, 0) : 0;
      var corr = p.results && p.correct ? p.results.filter(function (r) { return r.answer === p.correct; })[0] : null;
      var mine = getAns(p.id);
      var el = document.createElement("div");
      el.className = "poll-item";
      el.innerHTML =
        '<div class="top">Poll ' + p.n + ' <span>' + fmt(p.openMs / 1000) + '</span></div>' +
        '<div class="sub">' + esc(p.options.join(" / ")) + '</div>' +
        '<div class="sub">' + (p.correct ? 'Correct: <b class="ok">' + esc(p.correct) + '</b>' : 'No correct answer set') +
        (p.results ? ' · ' + votes + ' vote' + (votes === 1 ? '' : 's') + (corr ? ' · ' + Math.round(corr.pct) + '% right' : '') : '') + '</div>' +
        (mine ? '<div class="sub">You picked <b class="' + (p.correct ? (mine === p.correct ? 'ok' : 'bad') : '') + '">' + esc(mine) + '</b></div>' : '');
      el.title = "Jump to a few seconds before this poll";
      el.addEventListener("click", function () { dismissedId = null; seekTo(p.openMs / 1000 - LEAD_IN_SEC); });
      listEl.appendChild(el);
    });
  }

  function renderMarkers() {
    if (!markers) return;
    markers.innerHTML = "";
    var v = V(), dur = (v && v.duration) || 0;
    if (!dur || !polls.length) return;
    polls.forEach(function (p) {
      var m = document.createElement("div");
      m.className = "poll-marker";
      m.style.left = Math.max(0, Math.min(100, (p.openMs / 1000 / dur) * 100)) + "%";
      m.title = "Poll " + p.n + " · " + fmt(p.openMs / 1000);
      m.addEventListener("click", function (ev) { ev.stopPropagation(); dismissedId = null; seekTo(p.openMs / 1000 - LEAD_IN_SEC); });
      markers.appendChild(m);
    });
  }

  // ── the card ───────────────────────────────────────────────────────────────
  function renderCard(nowMs) {
    var active = null, phase = null;
    for (var i = 0; i < polls.length; i++) {
      var p = polls[i];
      if (nowMs >= p.openMs && nowMs < p.closeMs) { active = p; phase = "open"; break; }
      if (nowMs >= p.closeMs && nowMs < p.closeMs + RESULT_SHOW_MS) { active = p; phase = "result"; break; }
    }
    if (!active || active.id === dismissedId) {
      if (!card.classList.contains("hidden")) card.classList.add("hidden");
      lastRenderKey = ""; return;
    }
    var mine = getAns(active.id);
    var left = Math.max(0, Math.ceil((active.openMs + active.durSec * 1000 - nowMs) / 1000));
    var key = active.id + "|" + phase + "|" + (mine || "") + "|" + (phase === "open" ? left : "");
    if (key === lastRenderKey) return;
    lastRenderKey = key;

    var html = '<div class="poll-head"><span class="poll-tag' + (phase === "result" ? " done" : "") + '">' +
      (phase === "open" ? "LIVE POLL" : "RESULTS") + '</span><span class="poll-title">Poll ' + active.n + ' of ' + polls.length +
      '</span><button class="poll-x" title="Hide">✕</button></div>';

    if (phase === "open") {
      var frac = active.durSec ? Math.max(0, Math.min(1, left / active.durSec)) : 0;
      html += '<div class="poll-q">🎙 The question is being asked in the video — listen, then pick an answer.' +
              (active.durSec ? ' <b>' + left + 's</b> left' : '') + '</div>';
      if (active.durSec) html += '<div class="poll-timer"><i style="width:' + (frac * 100).toFixed(1) + '%"></i></div>';
      active.options.forEach(function (o) {
        html += '<button class="poll-opt' + (mine === o ? " picked" : "") + '" data-ans="' + esc(o) + '"><div class="row"><span>' + esc(o) + '</span>' +
                (mine === o ? '<span class="meta">your answer</span>' : '') + '</div></button>';
      });
      html += '<div class="poll-foot">Results appear when the teacher closes the poll.</div>';
    } else {
      var res = active.results || active.options.map(function (o) { return { answer: o, count: 0, pct: 0, isCorrect: o === active.correct }; });
      var votes = res.reduce(function (s, r) { return s + r.count; }, 0);
      res.forEach(function (r) {
        var isC = r.isCorrect || (active.correct && r.answer === active.correct);
        html += '<button class="poll-opt' + (isC ? " correct" : "") + (mine === r.answer ? " picked" : "") + '" disabled>' +
                '<i class="bar" style="width:' + Math.max(0, Math.min(100, r.pct)).toFixed(0) + '%"></i>' +
                '<div class="row"><span>' + (isC ? "✓ " : "") + esc(r.answer) + (mine === r.answer ? ' <span class="meta">(you)</span>' : '') + '</span>' +
                '<span class="meta">' + Math.round(r.pct) + '% · ' + r.count + '</span></div></button>';
      });
      var verdict = "";
      if (mine && active.correct) verdict = mine === active.correct ? ' You got it <b class="ok">right ✓</b>' : ' You picked <b class="bad">' + esc(mine) + ' ✗</b>';
      else if (!mine) verdict = " You didn't answer.";
      html += '<div class="poll-foot">' + votes + ' student' + (votes === 1 ? '' : 's') + ' voted.' + verdict + '</div>';
    }
    card.innerHTML = html;
    card.classList.remove("hidden");

    card.querySelector(".poll-x").onclick = function () { dismissedId = active.id; card.classList.add("hidden"); };
    if (phase === "open") {
      Array.prototype.forEach.call(card.querySelectorAll(".poll-opt"), function (b) {
        b.onclick = function () { setAns(active.id, b.dataset.ans); lastRenderKey = ""; renderCard(currentVideoMs()); renderList(); };
      });
    }
  }

  function currentVideoMs() { var v = V(); return v ? v.currentTime * 1000 : 0; }

  // ── drive it from the video clock ─────────────────────────────────────────
  function tick() {
    var tl = window.timeline;
    if (!tl || !tl.events) return;
    if (tl !== builtFor) build(tl);
    renderCard(currentVideoMs());
  }
  function hook() {
    var v = V(); if (!v) { console.warn("[polls] video element not found"); return; }
    v.addEventListener("timeupdate", tick);
    v.addEventListener("seeked", function () { dismissedId = null; tick(); });
    v.addEventListener("loadedmetadata", function () { renderMarkers(); tick(); });
    v.addEventListener("durationchange", renderMarkers);
    setInterval(tick, 500);   // smooth countdown even while paused/seeking
  }
  hook();

  window.UA_POLLS = { list: function () { return polls; }, rebuild: function () { builtFor = null; tick(); } };
  console.info("[polls] live-class poll replay active");
})();
