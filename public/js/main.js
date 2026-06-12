/* ============================================================
   MAIN — boot, scaling, clock, market status, wiring
   ============================================================ */
(function () {
  /* ---------- Kiosk scaling: fit 1920x1080 canvas to viewport ---------- */
  function scale() {
    const cw = 1920, ch = 1080;
    // Round to 1/1000 — fractional scales with long mantissas cause blurry
    // text on some GPUs. Snap to exactly 1 near native resolution.
    let s = Math.min(window.innerWidth / cw, window.innerHeight / ch);
    if (!isFinite(s) || s <= 0) return; // zero-size viewport during load — keep last good scale
    s = Math.abs(s - 1) < 0.005 ? 1 : Math.round(s * 1000) / 1000;
    document.getElementById('canvas').style.transform = s === 1 ? 'none' : 'scale(' + s + ')';
  }
  window.addEventListener('resize', scale);
  window.addEventListener('orientationchange', scale);
  window.addEventListener('load', scale);
  scale();

  /* ---------- Clock + date ---------- */
  // Display timezone comes from the admin page (settings table) via window.DASH_TZ.
  // Market status below stays pinned to America/New_York regardless.
  let TZ = window.DASH_TZ || 'America/New_York';
  try { new Intl.DateTimeFormat('en-US', { timeZone: TZ }); }
  catch (e) { TZ = 'America/New_York'; }

  function tzAbbr(now) {
    const p = new Intl.DateTimeFormat('en-US', { timeZone: TZ, timeZoneName: 'short' })
      .formatToParts(now).find(x => x.type === 'timeZoneName');
    return p ? p.value : '';
  }

  function tickClock() {
    const now = new Date();
    const tParts = now.toLocaleString('en-US', {
      timeZone: TZ,
      hour: 'numeric', minute: '2-digit', second: '2-digit',
      hour12: true,
    }).match(/(\d+):(\d+):(\d+)\s*(AM|PM)/i);
    const hh = tParts ? tParts[1] : '—';
    const mm = tParts ? tParts[2] : '00';
    const ss = tParts ? tParts[3] : '00';
    const ap = tParts ? tParts[4] : '';
    const abbr = tzAbbr(now);
    document.getElementById('clock').innerHTML =
      hh + ':' + mm + '<span class="sec">:' + ss + '</span> ' +
      '<span style="font-size:14px;color:var(--tx-3);font-weight:500">' + ap + ' ' + abbr + '</span>';
    document.getElementById('date-val').textContent =
      now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: TZ });
    const tzLabel = document.getElementById('tz-label');
    if (tzLabel) tzLabel.textContent = abbr;
    updateMarket(now);
  }

  function updateMarket(now) {
    // Use formatToParts — avoids the new Date(localeString) re-parse bug
    // where the browser applies its own local timezone to the resulting string.
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone: 'America/New_York',
      weekday: 'short',
      hour: 'numeric',
      minute: '2-digit',
      hour12: false,
    }).formatToParts(now);
    const get = (t) => { const p = parts.find(x => x.type === t); return p ? p.value : ''; };
    const DAY_MAP = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
    const day  = DAY_MAP[get('weekday')] ?? -1;
    const hour = parseInt(get('hour'), 10);   // 0–23 (hour12:false)
    const mins = hour * 60 + parseInt(get('minute'), 10);
    const open = day >= 1 && day <= 5 && mins >= 570 && mins < 960; // 9:30–16:00 ET
    const badge = document.getElementById('mkt-badge');
    if (open) {
      badge.className = 'mkt-badge mkt-open';
      badge.innerHTML = '<span class="dot"></span> Market Open';
    } else {
      badge.className = 'mkt-badge mkt-closed';
      badge.innerHTML = '<span class="dot"></span> Market Closed';
    }
  }
  setInterval(tickClock, 1000);
  tickClock();

  /* ---------- Connection mode indicator ---------- */
  API.onMode((m) => {
    const el = document.getElementById('conn');
    if (m === 'live') { el.className = 'conn live'; el.innerHTML = '<span class="led"></span> Live \u00b7 API'; }
    else if (m === 'mock') { el.className = 'conn mock'; el.innerHTML = '<span class="led"></span> Demo Feed'; }
    else if (m === 'connecting') { el.className = 'conn'; el.innerHTML = '<span class="led"></span> Connecting\u2026'; }
    else { el.className = 'conn down'; el.innerHTML = '<span class="led"></span> Offline'; }
  });

  /* ---------- Progress bars ---------- */
  function prog(id, on) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('active', on);
  }
  // Active until data arrives
  prog('global-progress', true);
  prog('cyber-progress', true);

  /* ---------- AI Carousel ---------- */
  const SLIDES = ['global', 'cyber', 'portfolio'];
  const SLIDE_CFG = {
    global:    { cls: 'slide--global',    dotColor: 'var(--tx-3)' },
    cyber:     { cls: 'slide--cyber',     dotColor: 'var(--cyber)' },
    portfolio: { cls: 'slide--portfolio', dotColor: 'var(--acc)' },
  };
  const _slides = { global: null, cyber: null, portfolio: null };
  let _slideIdx = 0;
  let _carouselTimer = null;

  function _renderSlide(key) {
    const s = _slides[key];
    if (!s) return;
    const card  = document.getElementById('ai-carousel-card');
    const body  = document.getElementById('ai-carousel-body');
    const label = document.getElementById('ai-carousel-label');
    const meta  = document.getElementById('ai-carousel-meta');
    const dot   = document.getElementById('ai-carousel-dot');
    if (!card || !body) return;

    body.classList.add('fading');
    setTimeout(function () {
      // swap content
      label.textContent = s.label;
      meta.textContent  = s.meta || '—';
      body.innerHTML    = s.html || '—';
      // swap card class
      card.className = 'ai-card ' + SLIDE_CFG[key].cls;
      dot.style.background   = SLIDE_CFG[key].dotColor;
      dot.style.boxShadow    = '0 0 6px ' + SLIDE_CFG[key].dotColor;
      // update pip dots
      SLIDES.forEach(function (k, i) {
        const pip = document.querySelector('.cdot-' + i);
        if (pip) pip.classList.toggle('active', k === key);
      });
      body.classList.remove('fading');
    }, 150);
  }

  function _advanceCarousel() {
    // skip slides with no data yet
    for (let i = 0; i < SLIDES.length; i++) {
      const next = ((_slideIdx + 1 + i) % SLIDES.length);
      if (_slides[SLIDES[next]]) { _slideIdx = next; break; }
    }
    _renderSlide(SLIDES[_slideIdx]);
  }

  window.Carousel = {
    update: function (key, label, html, meta) {
      _slides[key] = { label: label, html: html, meta: meta };
      // If this is the active slide or nothing rendered yet, refresh immediately
      if (SLIDES[_slideIdx] === key || !_slides[SLIDES[_slideIdx]]) {
        _slideIdx = SLIDES.indexOf(key);
        _renderSlide(key);
      }
    },
    jumpTo: function (key) {
      var idx = SLIDES.indexOf(key);
      if (idx === -1 || !_slides[key]) return;
      _slideIdx = idx;
      _renderSlide(key);
    },
  };

  // Auto-advance every 7 seconds
  _carouselTimer = setInterval(_advanceCarousel, 7000);

  /* ---------- Wire API channels -> panel renders ---------- */
  const map = {
    'ai:briefing':        () => Panels.briefing(),
    'portfolio:summary':  () => Panels.summary(),
    'portfolio:history':  () => Panels.spark(),
    'portfolio:movers':   () => Panels.mover(),
    'portfolio:holdings': () => { Panels.holdings(); Panels.ticker(); },
    'espp:holdings':      () => Panels.espp(),
    'news:global':        () => { Panels.global(); },
    'ai:global':          () => { prog('global-progress', false); Panels.aiSummaryGlobal(); },
    'news:cyber':         () => { Panels.cyber(); },
    'truth:posts':        () => { Panels.truth(); },
    'ai:cyber':           () => { prog('cyber-progress', false); Panels.cyber(); Panels.aiSummaryCyber(); },
    'weather:current':    () => { Panels.weather(); Panels.aiSummaryWeather(); },
    'ai:portfolio':       () => Panels.aiPortfolio(),
  };
  Object.entries(map).forEach(([k, fn]) => API.on(k, () => { try { fn(); } catch (e) { console.error(k, e); } }));

  /* ---------- Init auto-scroll regions ---------- */
  AutoScroll.initVertical('holdings', '#holdings-scroll', '#holdings-track', 16);
  AutoScroll.initVertical('global',   '#global-scroll',   '#global-track',   20);
  AutoScroll.initVertical('cyber',    '#cyber-scroll',    '#cyber-track',    16);
  AutoScroll.initVertical('truth',    '#truth-scroll',    '#truth-track',    16);
  AutoScroll.initTicker('#ticker-viewport', '#ticker-track', 60);

  // recompute scroll metrics after fonts/layout settle
  function recompute() {
    ['holdings', 'global', 'cyber', 'truth'].forEach(n => AutoScroll.refresh(n));
    AutoScroll.refreshTicker();
  }
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => setTimeout(recompute, 50));
  setTimeout(recompute, 400);
  setTimeout(recompute, 1500);

  /* ---------- Briefing offline note ---------- */
  // AI digests are generated server-side by cron/pollers/ai.php and arrive via
  // the state poll. If none shows up shortly after load, Ollama is off — say so
  // instead of leaving "Generating…" forever.
  setTimeout(function () {
    if (API.get('ai:briefing') || API.mode() === 'mock') return;
    var textEl = document.getElementById('briefing-text');
    var metaEl = document.getElementById('briefing-meta');
    if (textEl) textEl.textContent = 'AI briefing unavailable — Ollama is offline or hasn’t generated yet.';
    if (metaEl) metaEl.textContent = 'ollama · offline';
    if (!API.get('ai:global')) prog('global-progress', false);
  }, 8000);

  /* ---------- Boot the API ---------- */
  API.boot();
})();
