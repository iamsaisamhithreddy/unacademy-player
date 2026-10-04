<?php header("Content-Type: application/javascript"); ?>
"use strict";

// ════════════════════════════════════════════════════════════════════════════
// TIMELINE ENGINE  (t3k1.js)
// ════════════════════════════════════════════════════════════════════════════
// Unacademy's replay (player.uacdn.net app chunk, frameLoop) applies an event
// as soon as  p_time <= video.currentTime * 1e6  — an absolute mapping with no
// stretching and no delay. Default extra lag is therefore 0.
const DEFAULT_SYNC_LAG_MS = 0;

class TimelineEngine {
  constructor() {
    this.events=[]; this.minTime=0; this.maxTime=0;
    this.t0=0; this.eventSpanUs=1; this.divider=1;
    this.syncLagMs=DEFAULT_SYNC_LAG_MS; this.lastEventIndex=-1;
  }
  _lessonTimeOrigin(flat) {
    for (const ev of flat) {
      if (ev.plugin!=="mcn") continue;
      const ed=ev.data||{};
      const inner=(ed.data&&typeof ed.data==="object")?ed.data:ed;
      if (inner?.e==="cp"&&ev.p_time!=null) return Number(ev.p_time);
    }
    return flat.length ? Number(flat[0].p_time||0) : 0;
  }
  // Tunable from the console:  localStorage.setItem('ua_sync_lag_ms_v2', 500)
  // (new key: the old 'ua_sync_lag_ms' belonged to the previous stretched mapping)
  _loadSyncLag() {
    try {
      const l=parseInt(localStorage.getItem("ua_sync_lag_ms_v2")||"",10);
      if (Number.isFinite(l)&&l>=0&&l<=15000) { this.syncLagMs=l; return; }
    } catch(_){}
    this.syncLagMs=DEFAULT_SYNC_LAG_MS;
  }
videoToEventMs(videoMs, videoDurMs) {
    // UA: event visible when raw p_time <= videoTime. Our timeMs is
    // (raw - minTime)/divider, and raw/divider is in ms, so:
    //   timeMs <= videoMs - minTime/divider
    // videoDurMs is accepted for API compatibility but no longer used —
    // stretching events over the video length drifted by up to ~11 s.
    return Math.max(0, videoMs - this.syncLagMs) - this.minTime / this.divider;
  }
  // Inverse of videoToEventMs: the video time at which an event appears.
  eventToVideoMs(eventMs) {
    return Math.max(0, eventMs + this.minTime / this.divider + this.syncLagMs);
  }
  load(rawData) {
    const flat=[];
    this._extract(rawData,flat);
    if (!flat.length) throw new Error("No events found");
    const times=flat.map(e=>parseInt(e.p_time||0)).filter(t=>t>0);
    this.minTime=times.reduce((a,b)=>Math.min(a,b));
    this.maxTime=times.reduce((a,b)=>Math.max(a,b));
    this.t0=this._lessonTimeOrigin(flat);
    this.eventSpanUs = Math.max(1, this.maxTime - this.minTime);
    this._loadSyncLag();
    const dur=this.maxTime-this.minTime;
    this.divider=dur>10_000_000?1000:1;
    this.events=flat
      .filter(e=>e.p_time!==undefined)
      .map((e,seq)=>{ const pt=parseInt(e.p_time||0); return {seq,rawTime:pt,timeMs:(pt-this.minTime)/this.divider,plugin:e.plugin||"",data:e.data||{},raw:e}; })
      .sort((a,b)=>a.timeMs-b.timeMs || a.rawTime-b.rawTime || a.seq-b.seq);
    this.lastEventIndex=-1;
    return this;
  }
  _extract(obj,out) {
    if (Array.isArray(obj)) {
      for (const item of obj) {
        if (item&&typeof item==="object") {
          if (item.p_time!==undefined&&item.plugin!==undefined) out.push(item);
          else this._extract(item,out);
        }
      }
    } else if (obj&&typeof obj==="object") {
      for (const k of Object.keys(obj)) {
        const v=obj[k];
        if (Array.isArray(v)||(v&&typeof v==="object"&&!v.p_time)) this._extract(v,out);
      }
      if (obj.p_time!==undefined&&obj.plugin!==undefined) out.push(obj);
    }
  }
  getDurationMs() { return this.events.length?this.events[this.events.length-1].timeMs:0; }
  binarySearch(targetMs) {
    let lo=0,hi=this.events.length-1,res=-1;
    while(lo<=hi){const mid=(lo+hi)>>1;if(this.events[mid].timeMs<=targetMs){res=mid;lo=mid+1;}else hi=mid-1;}
    return res;
  }
  getNewEvents(currentMs) {
    const upTo=this.binarySearch(currentMs);
    if (upTo<=this.lastEventIndex) return [];
    const ev=this.events.slice(this.lastEventIndex+1,upTo+1);
    this.lastEventIndex=upTo;
    return ev;
  }
  getAllEventsUpTo(targetMs) { const upTo=this.binarySearch(targetMs); return upTo<0?[]:this.events.slice(0,upTo+1); }
  resetCursor(targetMs)     { this.lastEventIndex=this.binarySearch(targetMs); }
  formatTime(ms) {
    const h=Math.floor(ms/3600000),m=Math.floor((ms%3600000)/60000),s=Math.floor((ms%60000)/1000);
    return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
  }
}
