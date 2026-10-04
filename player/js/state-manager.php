<?php header("Content-Type: application/javascript"); ?>
"use strict";

// ════════════════════════════════════════════════════════════════════════════
// STATE MANAGER  (s7m2.js) - WITH UA_DEBUG INSTRUMENTATION
// ════════════════════════════════════════════════════════════════════════════
window.UA_DEBUG = {
    isVerbose: false,
    eventHistory: [],
    quiet: function() { this.isVerbose = false; console.log("[UA_DEBUG] Quiet mode active."); },
    verbose: function() { this.isVerbose = true; console.log("[UA_DEBUG] Verbose mode active. Console may be flooded."); },
    state: function() {
        if(!window.stateManager) return console.warn("stateManager not ready");
        const sm = window.stateManager;
        const sid = sm.currentSid;
        const st = sm.getSlideState(sid);
        // Actual current playhead time, not the video's total duration.
        let curTime = 0;
        if (sm.timeline) {
            curTime = (window.videoEl && window.videoEl.duration)
                ? sm.timeline.videoToEventMs(window.videoEl.currentTime * 1000, window.videoEl.duration * 1000)
                : sm.timeline.getDurationMs();
        }
        console.group(`[UA STATE] slide=${sid} | time=${sm.timeline ? sm.timeline.formatTime(curTime) : 0} | count=${st.strokes.size}`);
        for(let [k, v] of st.strokes.entries()) {
            console.log(`[ANNOTATION] oid=${v.oid} aId=${v.aId} type=${v.tool} color=${v.color} slide=${sid} pts=${v.points?.length} visibility=${v.isActive?'active':'complete'}`);
        }
        console.groupEnd();
    },
    find: function(id) {
        if(!window.stateManager) return;
        console.group(`[UA_DEBUG] Finding annotation: ${id}`);
        for (let [sid, st] of window.stateManager.strokesBySid.entries()) {
            for(let [k, v] of st.strokes.entries()) {
                if(v.oid === id || v.aId === id || k.includes(id)) {
                    console.log(`FOUND in slide=${sid}:`, v);
                }
            }
        }
        console.groupEnd();
    },
    // ── window dump: pull every raw event in a time range and print it ────────
    // UA_DEBUG.window(10)              -> last 10 min ending at current playhead
    // UA_DEBUG.window(10, 0, "59:40")  -> 10 min ending at 59:40 explicitly
    // Returns the filtered raw event array (assign it: `let w = UA_DEBUG.window(10)`)
    // so you can inspect further, e.g. w.filter(e => e.plugin==="cw").
    window: function(minutesBack, minutesForward, atTime) {
        if(!window.timeline) { console.warn("[UA_DEBUG] timeline not ready — load a lecture first"); return []; }
        minutesBack = minutesBack == null ? 10 : minutesBack;
        minutesForward = minutesForward == null ? 0 : minutesForward;
        const tl = window.timeline;
        const parseAt = t => {
            if (t == null) {
                if (window.videoEl && window.videoEl.duration) {
                    return tl.videoToEventMs(window.videoEl.currentTime * 1000, window.videoEl.duration * 1000);
                }
                return tl.getDurationMs();
            }
            if (typeof t === "number") return t;
            const parts = String(t).split(":").map(Number).reverse();
            const s = parts[0] || 0, m = parts[1] || 0, h = parts[2] || 0;
            return (h * 3600 + m * 60 + s) * 1000;
        };
        const centerMs = parseAt(atTime);
        const loMs = Math.max(0, centerMs - minutesBack * 60000);
        const hiMs = centerMs + minutesForward * 60000;
        const evs = tl.events.filter(e => e.timeMs >= loMs && e.timeMs <= hiMs);

        console.group(`[UA_DEBUG] window ${tl.formatTime(loMs)} → ${tl.formatTime(hiMs)}  (${evs.length} events)`);
        const counts = {};
        for (const ev of evs) {
            const inner = ev.plugin === "cw" ? (ev.data.data || ev.data) : ev.data;
            const key = ev.plugin + ":" + (inner ? inner.e : "?");
            counts[key] = (counts[key] || 0) + 1;
        }
        console.table(counts);

        const rows = evs.map(ev => {
            const inner = ev.plugin === "cw" ? (ev.data.data || ev.data) : ev.data;
            const pt = inner && (inner.p || inner.point);
            return {
                time: tl.formatTime(ev.timeMs),
                seq: ev.seq,
                plugin: ev.plugin,
                e: inner ? inner.e : "?",
                panelId: ev.data && ev.data.id != null ? ev.data.id : (inner && inner.id),
                x: pt ? pt.x : undefined,
                y: pt ? pt.y : undefined,
                color: (inner && (inner.c || inner.color)) || undefined,
                raw: ev.data
            };
        });
        console.table(rows.map(r => ({time:r.time, seq:r.seq, plugin:r.plugin, e:r.e, panelId:r.panelId, x:r.x, y:r.y, color:r.color})));
        console.log("Full rows (with raw payload) returned — inspect via the returned array.");
        console.groupEnd();
        return rows;
    }
};

const INIT_SID="init", DEFAULT_BC="#202022";
const pageNumFromUrl=u=>{ const m=(u||"").match(/[?&]page=(\d+)/); return m?parseInt(m[1],10):null; };
const asSid=(d,t)=>d.uid||((d.u||d.url||"")+"_"+t);

function buildUrlsBySid(events) {
  const map={[INIT_SID]:{url:"",bc:DEFAULT_BC,bg:""}};
  const seen=new Set();
  for (const ev of events) {
    const t=ev.p_time,d=ev.data; if(!d) continue;
    const add=slide=>{const sid=asSid(slide,t);if(seen.has(sid))return;seen.add(sid);map[sid]={url:slide.u||slide.url||"",bc:slide.bc||slide.backgroundColor||DEFAULT_BC,bg:slide.bg||""};};
    if (ev.plugin==="dcn"&&d.e==="as") add(d);
    if (ev.plugin==="dcn"&&d.e==="sc"&&d.s&&typeof d.s==="object"&&(d.s.e==="as"||d.s.u||d.s.url)) add(d.s);
  }
  return map;
}

function buildSlideDeck(events) {
  const deck=[{url:"",bc:DEFAULT_BC,bg:"",sid:INIT_SID,pg:null}];
  const batches=[];
  for (const ev of events) {
    const d=ev.data; if(!d) continue;
    const push=(items,idx)=>{if(!items.length)return;const last=batches[batches.length-1];if(last&&last.i===idx)last.items.push(...items);else batches.push({i:idx,items});};
    if (ev.plugin==="dcn"&&(d.e==="as"||d.e==="add-slide")) {
      const i=d.i!=null?Number(d.i):-1,t=ev.p_time;
      push([{url:d.u||d.url||"",bc:d.bc||DEFAULT_BC,bg:d.bg||"",sid:asSid(d,t),pg:pageNumFromUrl(d.u||d.url||""),t}],i);
    }
    if (ev.plugin==="dcn"&&d.e==="sc"&&d.s&&typeof d.s==="object"&&(d.s.e==="as"||d.s.u||d.s.url)) {
      const s=d.s,i=s.i!=null?Number(s.i):-1,t=ev.p_time;
      push([{url:s.u||s.url||"",bc:s.bc||DEFAULT_BC,bg:s.bg||"",sid:asSid(s,t),pg:pageNumFromUrl(s.u||s.url||""),t}],i);
    }
  }
  for (const batch of batches) {
    batch.items.sort((a,b)=>{if(a.pg==null&&b.pg==null)return a.t-b.t;if(a.pg==null)return 1;if(b.pg==null)return -1;return a.pg-b.pg;});
    let idx=batch.i<0?deck.length:batch.i;
    while(deck.length<idx) deck.push({url:"",bc:DEFAULT_BC,bg:"",sid:"gap_"+deck.length,pg:null});
    deck.splice(idx,0,...batch.items);
  }
  const deckBySid={},deckIndexBySid={};
  deck.forEach((e,i)=>{if(e.sid){deckBySid[e.sid]=e;deckIndexBySid[e.sid]=i;}});
  return {deck,deckBySid,deckIndexBySid};
}

function buildEventSidMap(events) {
  const ptrTL=[{sid:INIT_SID}];
  const map=new Map(); let ptr=0;
  for (let i=0;i<events.length;i++) {
    const ev=events[i],d=ev.data||{},t=ev.p_time;
    if (ev.plugin==="dcn"&&d.e==="as") {
      const batch=[];let j=i;
      while(j<events.length){const e2=events[j],d2=e2.data||{};if(e2.plugin!=="dcn"||d2.e!=="as"||e2.p_time!==t)break;batch.push({sid:asSid(d2,t)});j++;}
      const idx=d.i!=null?parseInt(d.i,10):ptrTL.length;
      while(ptrTL.length<idx) ptrTL.push({sid:"gap_"+ptrTL.length});
      ptrTL.splice(idx,0,...batch);
      if(idx<=ptr) ptr+=batch.length;
      i=j-1;
    } else if (ev.plugin==="dcn"&&d.e==="sc") {
      const s=d.s??d.slide;
      if(s&&typeof s==="object"){const sid=asSid(s,t),fi=ptrTL.findIndex(x=>x.sid===sid);if(fi>=0)ptr=fi;else{ptr=ptrTL.length;ptrTL.push({sid});}}
      else if(typeof s==="number"){ptr=s;while(ptrTL.length<=ptr)ptrTL.push({sid:"jump_"+ptrTL.length});}
    }
    map.set(ev,ptrTL[ptr]?.sid||INIT_SID);
  }
  return {map,ptrTL};
}

class StateManager {
  constructor(tl,cw=1280,ch=720){this.timeline=tl;this.canvasWidth=cw;this.canvasHeight=ch;this._reset();}
  updateCanvasDimensions(w,h){this.canvasWidth=Math.max(1,w);this.canvasHeight=Math.max(1,h);}
  _reset(){
    this.deck=[];this.deckBySid={};this.deckIndexBySid={};this.urlsBySid={};
    this.ptrTimeline=[{sid:INIT_SID}];this.slideTimeline=[];this.eventSidMap=new Map();
    this.currentSlideIndex=0;this.currentSid=INIT_SID;this.currentSlideUrl=null;
    this.strokesBySid=new Map();
    this.penColor="#FFFFFF";this.penSize=1;this.eraserSize=5;this.activeTool="marker";
    this.zoom=1;this.panX=0;this.panY=0;this.pointerX=null;this.pointerY=null;this.pointerVisible=false;
    this.rev=0;   // bumped on any geometry change; CanvasRenderer watches it
  }
  getSlideState(sid){
    if(!this.strokesBySid.has(sid))this.strokesBySid.set(sid,{strokes:new Map(),activeByPanel:new Map()});
    return this.strokesBySid.get(sid);
  }
  _rawEvents(){return this.timeline.events.map(ev=>({plugin:ev.plugin,data:ev.data,p_time:ev.raw?parseInt(ev.raw.p_time):ev.timeMs,_ev:ev}));}
  buildSlideIndex(){
    const raw=this._rawEvents().sort((a,b)=>a.p_time-b.p_time);
    this.urlsBySid=buildUrlsBySid(raw);
    const dr=buildSlideDeck(raw);this.deck=dr.deck;this.deckBySid=dr.deckBySid;this.deckIndexBySid=dr.deckIndexBySid;
    const sr=buildEventSidMap(raw);this.ptrTimeline=sr.ptrTL;
    this.eventSidMap=new Map();for(const item of raw){const sid=sr.map.get(item);if(sid!=null)this.eventSidMap.set(item._ev,sid);}
    this.slideTimeline=this._buildSlideTimeline(raw);
    this.screenShareTimeline=this._buildScreenShareTimeline(raw);
  }
  // mcn/{e:"ss",v:true|false} marks the instructor toggling their screen
  // share on/off. While it's on, the recorded video itself shows the shared
  // screen (not the instructor's camera), so the whiteboard replay is not
  // meaningful for that span — the player should show the raw video instead.
  _buildScreenShareTimeline(raw){
    const tl=[{timeMs:0,active:false}];
    for(const item of raw){
      const ev=item._ev,d=ev.data||{};
      if(ev.plugin==="mcn"&&d.e==="ss")tl.push({timeMs:ev.timeMs,active:!!d.v});
    }
    return tl;
  }
  isScreenShareActive(timeMs){
    let active=false;
    for(const e of this.screenShareTimeline){if(e.timeMs<=timeMs)active=e.active;else break;}
    return active;
  }
  _buildSlideTimeline(raw){
    const tl=[{timeMs:0,ptr:0,sid:INIT_SID,url:""}];let ptr=0;const ps=[{sid:INIT_SID}];
    for(let i=0;i<raw.length;i++){
      const item=raw[i],ev=item._ev,d=ev.data||{},timeMs=ev.timeMs,t=item.p_time;
      if(ev.plugin==="dcn"&&d.e==="as"){
        const batch=[];let j=i;
        while(j<raw.length){const i2=raw[j],e2=i2._ev,d2=e2.data||{};if(e2.plugin!=="dcn"||d2.e!=="as"||i2.p_time!==t)break;batch.push({sid:asSid(d2,t)});j++;}
        const idx=d.i!=null?parseInt(d.i,10):ps.length;
        while(ps.length<idx)ps.push({sid:"gap_"+ps.length});
        ps.splice(idx,0,...batch);if(idx<=ptr)ptr+=batch.length;i=j-1;
      } else if(ev.plugin==="dcn"&&d.e==="sc"){
        const s=d.s??d.slide;
        if(s&&typeof s==="object"){const sid=asSid(s,t),fi=ps.findIndex(x=>x.sid===sid);if(fi>=0)ptr=fi;else{ptr=ps.length;ps.push({sid});}}
        else if(typeof s==="number"){ptr=s;while(ps.length<=ptr)ps.push({sid:"jump_"+ps.length});}
        const sid=ps[ptr]?.sid||INIT_SID;
        const de=this.deck[ptr]||this.deckBySid[sid]||{url:""};
        const ui=this.urlsBySid[sid]||{};
        tl.push({timeMs,ptr,sid,url:ui.url||de.url||""});
      }
    }
    return tl;
  }
  getSlideAtTime(timeMs){
    let entry=this.slideTimeline[0];
    for(const sl of this.slideTimeline){if(sl.timeMs<=timeMs)entry=sl;else break;}
    const de=this.deck[entry.ptr]||this.deckBySid[entry.sid]||{url:"",bc:DEFAULT_BC};
    return {index:entry.ptr,sid:entry.sid,url:entry.url||de.url||"",bc:de.bc||DEFAULT_BC};
  }
  updateSlideForTime(timeMs){
    const r=this.getSlideAtTime(timeMs);if(!r)return false;
    const ch=r.sid!==this.currentSid||r.url!==this.currentSlideUrl;
    if(ch){this.currentSlideIndex=r.index;this.currentSid=r.sid;this.currentSlideUrl=r.url||null;}
    return ch;
  }
  rebuildState(targetMs){
    if(window.UA_DEBUG && window.UA_DEBUG.isVerbose) {
        console.log(`[RESTORE] Rebuilding state to targetMs=${targetMs}`);
    }
    // Rebuild must be a true transaction: discard every mutable drawing/object
    // index and replay events in the exact original order up to targetMs.
    this.strokesBySid=new Map();
    this.penColor="#FFFFFF";this.penSize=1;this.eraserSize=5;this.activeTool="marker";
    this.zoom=1;this.panX=0;this.panY=0;this.pointerX=null;this.pointerY=null;this.pointerVisible=false;
    this.currentSlideIndex=0;this.currentSid=INIT_SID;this.currentSlideUrl=null;
    this.rev=(this.rev|0)+1;

    const evs=this.timeline.getAllEventsUpTo(targetMs);
    let stateCountBefore = 0;
    for(const ev of evs){
      let applied = false;
      if(ev.plugin==="dcn") { this._applyDcnDraw(ev.data); applied = true; }
      else if(ev.plugin==="cw"){
        const sid=this.eventSidMap.get(ev);
        if(sid!=null) { this._applyCw(ev.data,ev.timeMs,sid); applied = true; }
      }
      
      if(window.UA_DEBUG && window.UA_DEBUG.isVerbose) {
          console.log(`[REPLAY EVENT] index=${ev.seq} eventTime=${ev.timeMs} type=${ev.data?.e || ev.data?.data?.e} applied=${applied}`);
      }
    }
    const r=this.getSlideAtTime(targetMs);
    if(r){this.currentSlideIndex=r.index;this.currentSid=r.sid;this.currentSlideUrl=r.url||null;}
    this.timeline.resetCursor(targetMs);
    this.rev=(this.rev|0)+1;
  }
  applyEvent(ev){
    const{plugin,data,timeMs}=ev;
    if(window.UA_DEBUG && window.UA_DEBUG.isVerbose) {
         console.log(`[REPLAY EVENT LIVE] index=${ev.seq} eventTime=${ev.timeMs} type=${data?.e || data?.data?.e}`);
    }
    if(plugin==="dcn")this._applyDcnDraw(data);
    else if(plugin==="cw"){const sid=this.eventSidMap.get(ev);if(sid!=null)this._applyCw(data,timeMs,sid);}
  }
  _applyDcnDraw(data){
    const e=data.e;
    if(e==="cc"||e==="color_change"){const c=data.c||data.color||data.col||data.hex||data.rgb||this.penColor;if(typeof c==="string"&&c.length)this.penColor=c;}
    else if(e==="pstc"||e==="pen_size"){const s=parseFloat(data.s??data.size??data.sz??this.penSize);if(!isNaN(s)&&s>0)this.penSize=s;}
    else if(e==="estc"||e==="eraser_size"){const s=parseFloat(data.s??data.size??data.sz??this.eraserSize);if(!isNaN(s)&&s>0)this.eraserSize=s;}
    else if(e==="mc"||e==="brush_change"||e==="mode"){let t=data.m||data.tool||data.mode||data.type||data.b||this.activeTool;if(typeof t==="string"||typeof t==="number")this.activeTool=this._normalizeTool(t);}
    else if(["ea","clear","clear_draw","cl","c","erase_all"].includes(e)){
        if(window.UA_DEBUG && window.UA_DEBUG.isVerbose) console.log(`[DELETE] Erase All triggered`);
        for(const[,st]of this.strokesBySid){st.strokes.clear();st.activeByPanel.clear();}
    }
  }

  // ── SUPERSEDED by copy-fix(2).php (kept for reference) ────────────────────
  _applyCw(data,timeMs,eventSid){ /* Overridden */ }
  _applyPdo2(inner,strokes,timeMs){ /* Overridden */ }

  getAllStrokes(){
    if(!this.currentSid)return[];
    const st=this.getSlideState(this.currentSid),res=[...st.strokes.values()];
    for(const a of st.activeByPanel.values())if(a.points.length>0)res.push(a);
    return res;
  }
  _normalizeTool(m){
    if(!m)return"marker";const s=String(m).toLowerCase().replace(/[-_]/g,"");
    if(s.includes("eraser"))return"eraser";
    if(s.includes("markerh")||s.includes("highlighter"))return"highlighter";
    if(s.includes("circle")||s.includes("ellipse")||s.includes("oval")||s==="c")return"circle";
    if(s.includes("rectangle")||s.includes("rect")||s.includes("square")||s==="r")return"rectangle";
    if(s.includes("arrow")||s==="a")return"arrow";
    if(s.includes("dashedline")||s.includes("dashed"))return"dashed-line";
    if(s.includes("line")||s==="l")return"line";
    return String(m).toLowerCase();
  }
}