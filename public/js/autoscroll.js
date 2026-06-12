/* ============================================================
   AUTO-SCROLL ENGINE
   Seamless looping scroll for overflowing panels (kiosk, no input).
   Tracks contain their content duplicated 2x (3x for ticker).
   ============================================================ */
(function () {
  const regions = {};   // name -> { viewport, track, speed, offset, half, paused }
  let tickerCfg = null;

  function makeRegion(name, viewportSel, trackSel, speed) {
    regions[name] = {
      viewport: document.querySelector(viewportSel),
      track: document.querySelector(trackSel),
      speed, offset: 0, half: 0, paused: false, pauseUntil: 0,
    };
  }

  const AutoScroll = {
    initVertical(name, viewportSel, trackSel, speed) { makeRegion(name, viewportSel, trackSel, speed); },
    initTicker(viewportSel, trackSel, speed) {
      tickerCfg = { viewport: document.querySelector(viewportSel), track: document.querySelector(trackSel), speed, offset: 0, third: 0 };
    },
    refresh(name) {
      const r = regions[name];
      if (!r || !r.track) return;
      // content duplicated => half height is one full set
      r.half = r.track.scrollHeight / 2;
      // if content fits, no scroll needed
      if (r.half <= r.viewport.clientHeight + 2) { r.offset = 0; r.track.style.transform = 'translateY(0)'; r.disabled = true; }
      else { r.disabled = false; }
    },
    refreshTicker() {
      if (!tickerCfg || !tickerCfg.track) return;
      tickerCfg.third = tickerCfg.track.scrollWidth / 3;
    },
  };

  let last = performance.now();
  function frame(now) {
    const dt = Math.min(50, now - last) / 1000;
    last = now;

    for (const name in regions) {
      const r = regions[name];
      if (!r.track || r.disabled || r.half <= 0) continue;
      if (now < r.pauseUntil) continue;
      r.offset += r.speed * dt;
      // pause briefly at each full loop
      if (r.offset >= r.half) { r.offset = 0; r.pauseUntil = now + 900; }
      r.track.style.transform = 'translateY(' + (-r.offset).toFixed(2) + 'px)';
    }

    if (tickerCfg && tickerCfg.track && tickerCfg.third > 0) {
      tickerCfg.offset += tickerCfg.speed * dt;
      if (tickerCfg.offset >= tickerCfg.third) tickerCfg.offset -= tickerCfg.third;
      tickerCfg.track.style.transform = 'translateX(' + (-tickerCfg.offset).toFixed(2) + 'px)';
    }

    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);

  window.AutoScroll = AutoScroll;
})();
