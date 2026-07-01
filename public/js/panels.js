/* ============================================================
   PANEL RENDERERS
   Each reads from API.get(key) and paints into its container.
   ============================================================ */
(function () {
  const $ = (id) => document.getElementById(id);
  const P = {};

  /* ---------------- MANUAL-SCROLL FEEDS + NEW-ITEM HIGHLIGHT ----------------
     Global / Cyber / POTUS are user-scrolled (scrollbars in styles.css) rather
     than auto-scrolled. Each item carries data-ts (published_at); items newer
     than the per-feed highlight window get .is-new, which fades on a timer
     (main.js) and re-applies on every render. Windows come from the admin page
     via window.DASH_FEED_HL (minutes). */
  const FEED_HL = window.DASH_FEED_HL || { global: 30, cyber: 30, truth: 30 };
  const FEED_REGIONS = [
    { scroll: 'global-scroll', track: 'global-track', key: 'global' },
    { scroll: 'cyber-scroll',  track: 'cyber-track',  key: 'cyber' },
    { scroll: 'truth-scroll',  track: 'truth-track',  key: 'truth' },
  ];

  function _applyHighlight(trackId, key) {
    const track = $(trackId);
    if (!track) return;
    const winMs = (FEED_HL[key] || 0) * 60000;
    const now = Date.now();
    track.querySelectorAll('[data-ts]').forEach((el) => {
      const ts = Date.parse(el.getAttribute('data-ts'));
      const isNew = winMs > 0 && !isNaN(ts) && (now - ts) < winMs;
      el.classList.toggle('is-new', isNew);
    });
  }

  // Swap a feed's content while preserving the reader's manual scroll position,
  // then (re)apply the new-item highlight.
  function _renderFeed(scrollId, trackId, key, html) {
    const sc = $(scrollId);
    const top = sc ? sc.scrollTop : 0;
    $(trackId).innerHTML = html;
    if (sc) sc.scrollTop = top;
    _applyHighlight(trackId, key);
  }

  // Re-evaluate highlights without a data change, so they fade as items age out.
  P.reHighlight = function () {
    FEED_REGIONS.forEach((r) => _applyHighlight(r.track, r.key));
  };

  // Open/close a feed card. Renders as an <a> link to the source when the item
  // has a url (opens in a new tab), otherwise a plain <div>. cls = extra
  // classes; ts = published_at, used by the new-item highlight.
  function _cardOpen(cls, url, ts) {
    return (url ? '<a' : '<div') + ' class="' + cls + '"' +
      (url ? ' href="' + F.esc(url) + '" target="_blank" rel="noopener noreferrer"' : '') +
      ' data-ts="' + F.esc(ts) + '">';
  }
  function _cardClose(url) { return url ? '</a>' : '</div>'; }

  /* ---------------- SHARED: rich text parser ---------------- */
  function richText(text) {
    let s = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    s = s.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
    s = s.replace(/\b([A-Z]{2,5})\s*(\+[\d.]+%)/g, '<b>$1</b> <span class="up">$2</span>');
    s = s.replace(/\b([A-Z]{2,5})\s*(-[\d.]+%)/g,  '<b>$1</b> <span class="down">$2</span>');
    s = s.replace(/(\+[\d.]+%)/g, '<span class="up">$1</span>');
    s = s.replace(/(?<![A-Z\d])(-[\d.]+%)/g, '<span class="down">$1</span>');
    return s;
  }

  /* ---------------- AI SUMMARY BAR ---------------- */

  P.briefing = function () {
    const b = API.get('ai:briefing');
    const textEl = $('briefing-text');
    const metaEl = $('briefing-meta');
    if (!b) { textEl.textContent = 'Generating…'; return; }

    // Handle nested {briefing:{briefing:"...", model, generated_at}} or flat
    const payload = (b.briefing && typeof b.briefing === 'object') ? b.briefing : b;
    const raw   = typeof payload === 'string' ? payload : (payload.briefing || payload.raw || '');
    const model = payload.model || 'ollama';
    const genAt = payload.generated_at ? new Date(payload.generated_at) : null;

    if (!raw) { textEl.textContent = 'Generating…'; return; }
    textEl.innerHTML = richText(raw);

    if (metaEl && genAt) {
      metaEl.textContent = model + ' · ' + F.etTime(genAt);
    }
  };

  function _aiMeta(digest, metaId) {
    const metaEl = $(metaId);
    if (!metaEl || !digest) return;
    const model = digest.model || '';
    const genAt = digest.generated_at ? new Date(digest.generated_at) : null;
    if (genAt) {
      metaEl.textContent = (model ? model + ' · ' : '') + F.etTime(genAt);
    } else if (model) {
      metaEl.textContent = model;
    }
  }

  P.aiSummaryGlobal = function () {
    const digest = API.get('ai:global');
    if (!digest) return;
    const text = richText(digest.digest || digest.summary || '');
    const meta = (digest.model ? digest.model + ' · ' : '') + (F.etTime(digest.generated_at) || '');
    Carousel.update('global', 'Global Feed · AI', text, meta);
  };

  P.aiSummaryCyber = function () {
    const digest = API.get('ai:cyber');
    if (!digest) return;
    const text = richText(digest.digest || digest.summary || '');
    const meta = (digest.model ? digest.model + ' · ' : '') + (F.etTime(digest.generated_at) || '');
    Carousel.update('cyber', 'Cyber · DIB · AI', text, meta);
  };

  P.aiSummaryWeather = function () {
    const wx = API.get('weather:current');
    const el = $('ai-sum-weather');
    const locEl = $('ai-sum-wx-loc');
    if (!el || !wx || !wx.current) return;
    const c = wx.current;
    if (locEl) locEl.textContent = c.location || 'New York, NY';
    const forecast = (wx.forecast || []).slice(0, 2);
    let txt = '<span class="wx-val">' + c.temperature_f + '°F</span> · ' + c.condition;
    txt += ' · Humidity ' + c.humidity + '% · Wind ' + c.wind_mph + ' mph ' + (c.wind_direction || '');
    if (forecast.length) {
      txt += '  —  ' + forecast.map(d => d.date + ' ' + d.high_f + '°/' + d.low_f + '° ' + d.condition).join('  ·  ');
    }
    el.innerHTML = txt;
  };

  /* ---------------- MARKET INDICES (DOW / NASDAQ / S&P) ---------------- */
  P.indices = function () {
    const idx = API.get('market:indices');
    const wrap = $('mkt-indices');
    if (!wrap || !idx || !idx.length) return;
    const num = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const chg = (n) => (n >= 0 ? '+' : '−') + num(Math.abs(n));
    wrap.innerHTML = idx.map((i) => {
      const dir = i.direction === 'up' ? 'up' : 'down';
      return '<div class="mkt-idx ' + dir + '">' +
        '<div class="mi-label">' + F.esc(i.label) + '</div>' +
        '<div class="mi-price">' + num(i.price) + '</div>' +
        '<div class="mi-chg">' + chg(i.change) + ' (' + F.pct(i.percent_change) + ')</div>' +
        '</div>';
    }).join('');
  };

  /* ---------------- PORTFOLIO SUMMARY ---------------- */
  P.summary = function () {
    const s = API.get('portfolio:summary');
    if (!s) return;
    const eqEl = $('pf-equity-val');
    F.countUp(eqEl, s.total_equity, (v) => F.compact(v));

    const day = $('pf-day-chip');
    day.className = 'chip ' + F.updir(s.daily_change_dollar);
    day.innerHTML = F.usdSigned(s.daily_change_dollar) + '  ' + F.pct(s.daily_change_percent);

    const tot = $('pf-tot-chip');
    tot.className = 'chip ' + F.updir(s.total_return_dollar);
    tot.innerHTML = F.usdSigned(s.total_return_dollar) + '  ' + F.pct(s.total_return_percent);

    const syncEl = $('pf-updated');
    const etSync = F.etTime(s.updated_at);
    syncEl.textContent = etSync ? 'SYNC ' + F.ago(s.updated_at) + ' · ' + etSync : 'SYNC —';
  };

  /* ---------------- SPARKLINE ---------------- */
  P.spark = function () {
    const h = API.get('portfolio:history');
    if (!h || !h.equity_historicals) return;
    const pts = h.equity_historicals.map(p => +p.adjusted_close_equity).filter(v => !isNaN(v));
    if (pts.length < 2) return;
    const W = 560, H = 64, pad = 2;
    const min = Math.min(...pts), max = Math.max(...pts);
    const rng = (max - min) || 1;
    const xs = (i) => (i / (pts.length - 1)) * W;
    const ys = (v) => H - pad - ((v - min) / rng) * (H - pad * 2);
    let d = '';
    pts.forEach((v, i) => { d += (i ? 'L' : 'M') + xs(i).toFixed(1) + ' ' + ys(v).toFixed(1) + ' '; });
    const area = d + 'L' + W + ' ' + H + ' L0 ' + H + ' Z';
    const up = pts[pts.length - 1] >= pts[0];
    const col = up ? 'var(--gain)' : 'var(--loss)';
    $('pf-spark-svg').innerHTML =
      '<defs><linearGradient id="sparkg" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0%" stop-color="' + col + '" stop-opacity="0.28"/>' +
      '<stop offset="100%" stop-color="' + col + '" stop-opacity="0"/></linearGradient></defs>' +
      '<path d="' + area + '" fill="url(#sparkg)"/>' +
      '<path d="' + d + '" fill="none" stroke="' + col + '" stroke-width="1.6" stroke-linejoin="round"/>' +
      '<circle cx="' + xs(pts.length - 1).toFixed(1) + '" cy="' + ys(pts[pts.length - 1]).toFixed(1) + '" r="2.6" fill="' + col + '"/>';
    $('pf-spark-lo').textContent = F.compact(pts[0]);
    $('pf-spark-hi').textContent = F.compact(pts[pts.length - 1]);
  };

  /* ---------------- HOLDINGS LIST ---------------- */
  // Per-section collapse state, remembered per browser and keyed by section name.
  var _COLLAPSE_KEY = 'dash:holdCollapsed';
  function _collapsedMap() {
    try { return JSON.parse(localStorage.getItem(_COLLAPSE_KEY)) || {}; }
    catch (e) { return {}; }
  }
  function _isCollapsed(name) { return _collapsedMap()[name] === true; }
  function _toggleCollapsed(name) {
    var m = _collapsedMap();
    if (m[name]) delete m[name]; else m[name] = true;
    try { localStorage.setItem(_COLLAPSE_KEY, JSON.stringify(m)); } catch (e) {}
  }

  var _holdClickWired = false;

  P.holdings = function () {
    const hs = API.get('portfolio:holdings');
    if (!hs) return;

    const rowHTML = (h) => {
      const dc = F.sign(h.percent_change_today);
      // No cost basis → neutral (not red) and a — lifetime cell.
      const tc = h.total_return_dollar == null ? '' : F.sign(h.total_return_dollar);
      return '<div class="hrow" data-tk="' + h.ticker + '">' +
        '<div class="sym"><span class="t">' + F.esc(h.ticker) + '</span>' +
        '<span class="n">' + F.esc(h.name) + '</span></div>' +
        '<div class="num eq">' + F.usd(h.equity, 0) +
        '<span class="sub">' + h.quantity + ' sh</span></div>' +
        '<div class="num day ' + dc + '">' + F.pct(h.percent_change_today) +
        '<span class="sub ' + dc + '">' + F.usdSigned(h.dollar_change_today, 0) + '</span></div>' +
        '<div class="num tot ' + tc + '">' + F.pct(h.total_return_percent) +
        '<span class="sub ' + tc + '">' + F.usdSigned(h.total_return_dollar, 0) + '</span></div>' +
        '</div>';
    };

    // Group by section, preserving the global equity-desc order within a group
    // and ordering groups by section_order (Ungrouped sentinel sorts last).
    const groups = [];
    const byName = {};
    hs.forEach((h) => {
      const name = h.section || 'Ungrouped';
      let g = byName[name];
      if (!g) {
        g = byName[name] = { name: name, order: h.section_order == null ? 9999 : h.section_order, rows: [] };
        groups.push(g);
      }
      g.rows.push(h);
    });
    groups.sort((a, b) => (a.order - b.order) || a.name.localeCompare(b.name));

    const groupHTML = (g) => {
      let eq = 0, day = 0, prev = 0, knownEq = 0, knownCost = 0, hasKnown = false;
      g.rows.forEach((h) => {
        eq   += h.equity || 0;
        day  += h.dollar_change_today || 0;
        prev += (h.equity || 0) - (h.dollar_change_today || 0);
        if (h.total_return_dollar != null) {
          hasKnown = true;
          knownEq   += h.equity || 0;
          knownCost += (h.equity || 0) - h.total_return_dollar;
        }
      });
      const dayPct = prev > 0 ? (day / prev) * 100 : 0;
      const totRet = knownEq - knownCost;
      const totPct = knownCost > 0 ? (totRet / knownCost) * 100 : null;
      const dc = F.sign(day);
      const tc = hasKnown ? F.sign(totRet) : '';
      const collapsed = _isCollapsed(g.name);
      return '<div class="hgroup' + (collapsed ? ' collapsed' : '') + '">' +
        '<div class="hgroup-head" data-sec="' + F.esc(g.name) + '">' +
          '<span class="glabel"><span class="caret">▾</span>' +
          '<span class="gname">' + F.esc(g.name) + '</span>' +
          '<span class="gcount">' + g.rows.length + '</span></span>' +
          '<span class="num eq">' + F.usd(eq, 0) + '</span>' +
          '<span class="num day ' + dc + '">' + F.pct(dayPct) + '</span>' +
          '<span class="num tot ' + tc + '">' + (totPct == null ? '—' : F.pct(totPct)) + '</span>' +
        '</div>' +
        '<div class="hgroup-rows">' + g.rows.map(rowHTML).join('') + '</div>' +
      '</div>';
    };

    // User-scrolled list — render once, preserving the reader's scroll position
    // across data refreshes.
    const sc = $('holdings-scroll');
    const top = sc ? sc.scrollTop : 0;
    const track = $('holdings-track');
    track.innerHTML = groups.map(groupHTML).join('');
    if (sc) sc.scrollTop = top;

    // Delegate collapse toggling once; the listener survives innerHTML refreshes.
    if (!_holdClickWired && track) {
      _holdClickWired = true;
      track.addEventListener('click', (ev) => {
        const head = ev.target.closest('.hgroup-head');
        if (!head) return;
        _toggleCollapsed(head.getAttribute('data-sec'));
        head.parentElement.classList.toggle('collapsed');
      });
    }
  };

  /* ---------------- GLOBAL NEWS ---------------- */
  P.global = function () {
    const arts = API.get('news:global');
    if (!arts) return;
    const artHTML = (a) => {
      const hasTickers = a.tickers && a.tickers.length;
      const fin = hasTickers;
      const tk = (a.tickers || []).map(t => {
        const s = a.sentiment === 'positive' ? 'up' : a.sentiment === 'negative' ? 'down' : '';
        return '<span class="tickerchip ' + s + '">' + F.esc(t) + '</span>';
      }).join('');
      const senti = a.sentiment ? '<span class="senti ' + a.sentiment + '">' +
        (a.sentiment === 'positive' ? '\u25B2' : a.sentiment === 'negative' ? '\u25BC' : '\u25CF') +
        ' ' + (a.sentiment_score != null ? (a.sentiment_score > 0 ? '+' : '') + a.sentiment_score.toFixed(2) : a.sentiment) + '</span>' : '';
      const url = a.url || '';
      return _cardOpen('nart' + (fin ? ' fin' : ''), url, a.published_at) +
        (fin ? '<div class="ribbon"></div>' : '') +
        '<div class="top"><span class="src">' + F.esc(a.source) + '</span>' +
        (tk || senti ? '<span style="display:inline-flex;gap:6px;align-items:center">' + tk + senti + '</span>' : '') +
        '<span class="time">' + F.ago(a.published_at) + '</span></div>' +
        '<div class="ttl">' + F.esc(a.title) + '</div>' +
        (a.summary ? '<div class="sum">' + F.esc(a.summary) + '</div>' : '') +
        _cardClose(url);
    };
    _renderFeed('global-scroll', 'global-track', 'global', arts.map(artHTML).join(''));
  };

  /* ---------------- CYBER / DIB DIGEST ---------------- */
  P.cyber = function () {
    const digest = API.get('ai:cyber');
    // Always use full news:cyber list for scrolling (20 articles).
    // ai:cyber.items (5 AI-prioritized) are shown only in the digest note and ai-sum-cyber card.
    const arts = API.get('news:cyber') || [];

    // digest note intentionally hidden \u2014 summary shown in ai-sum-cyber card below panel
    $('cyber-digest-note').style.display = 'none';

    // Build a tag lookup from AI digest items keyed by lowercased headline fragment
    const aiTagMap = {};
    const VALID_TAGS = new Set(['cmmc','ai','dib','threat','policy','breach']);
    if (digest && digest.items) {
      digest.items.forEach(item => {
        const key = (item.headline || '').toLowerCase().slice(0, 40);
        const tags = (item.tags || []).filter(t => VALID_TAGS.has(t));
        if (key) aiTagMap[key] = tags;
      });
    }

    const tagHTML = (tags) => (tags || [])
      .filter(t => VALID_TAGS.has(t))
      .map(t => '<span class="ctag ' + t + '">' + F.esc(t.toUpperCase()) + '</span>').join('');

    const artHTML = (a) => {
      const title = a.title || '';
      // Try to match AI tags by headline prefix
      const key = title.toLowerCase().slice(0, 40);
      const aiTags = aiTagMap[key] || a.tags || [];
      const url = a.url || '';
      return _cardOpen('cyber-art', url, a.published_at) +
        '<div class="c-body"><div class="c-ttl">' + F.esc(title) + '</div>' +
        '<div class="c-meta"><span class="c-src">' + F.esc(a.source || '') + '</span>' +
        '<span class="c-src" style="color:var(--tx-4)">\u00b7 ' + F.ago(a.published_at) + '</span>' +
        tagHTML(aiTags) + '</div></div>' + _cardClose(url);
    };

    _renderFeed('cyber-scroll', 'cyber-track', 'cyber', arts.map(artHTML).join(''));
  };

  /* ---------------- INTERESTING PEOPLE (X + Truth Social) ---------------- */
  P.truth = function () {
    const track = $('truth-track');
    if (!track) return;
    // Merge X posts and Truth Social mirror posts into one newest-first stream.
    const posts = []
      .concat(API.get('x:posts') || [])
      .concat(API.get('truth:posts') || [])
      .sort((a, b) => String(b.published_at).localeCompare(String(a.published_at)));
    if (!posts.length) return;
    const srcLabel = (p) => {
      if (p.author) return F.esc(p.author);               // X handle, e.g. @handle
      return p.is_retruth ? 'ReTruth' : 'Truth';
    };
    const postHTML = (p) => {
      const imgs = (p.images || []).slice(0, 2).map(u =>
        '<img src="' + F.esc(u) + '" loading="lazy" alt="" onerror="this.style.display=\'none\'">').join('');
      const url = p.url || '';
      const rt = p.is_retruth ? '<span class="rt">RT</span>' : '';
      return _cardOpen('nart', url, p.published_at) +
        '<div class="top"><span class="src">' + srcLabel(p) + '</span>' + rt +
        '<span class="time">' + F.ago(p.published_at) + '</span></div>' +
        (p.text ? '<div class="truth-txt">' + F.esc(p.text) + '</div>' : '') +
        (imgs ? '<div class="truth-imgs">' + imgs + '</div>' : '') +
        _cardClose(url);
    };
    _renderFeed('truth-scroll', 'truth-track', 'truth', posts.map(postHTML).join(''));
  };

  /* ---------------- WEATHER ---------------- */
  P.weather = function () {
    const w = API.get('weather:current');
    if (!w || !w.current) return;
    const c = w.current;
    $('wx-loc').innerHTML = '<span class="pin">\u25C9</span> ' + F.esc(c.location);
    $('wx-temp').innerHTML = Math.round(c.temperature_f) + '<span class="deg">\u00b0</span>';
    $('wx-ico').textContent = F.wxIcon(c.condition);
    $('wx-cond-t').textContent = c.condition;
    $('wx-feels').textContent = 'Feels ' + Math.round(c.feels_like_f) + '\u00b0';
    $('wx-stats').innerHTML =
      stat('Humidity', c.humidity + '%') +
      stat('Wind', Math.round(c.wind_mph) + ' mph ' + c.wind_direction) +
      stat('Visibility', c.visibility_miles + ' mi') +
      stat('Updated', F.ago(c.updated_at) + ' ago');

    $('wx-forecast').innerHTML = (w.forecast || []).map(f =>
      '<div class="wx-fc-row">' +
      '<span class="wx-fc-day">' + F.esc(f.date) + '</span>' +
      '<span class="wx-fc-ico">' + F.wxIcon(f.condition) + '</span>' +
      '<span class="wx-fc-cond">' + F.esc(f.condition) + '</span>' +
      '<span class="wx-fc-pop">' + (f.precipitation_chance > 5 ? '\uD83D\uDCA7' + f.precipitation_chance + '%' : '') + '</span>' +
      '<span class="wx-fc-temp"><span class="hi">' + Math.round(f.high_f) + '\u00b0</span> ' +
      '<span class="lo">' + Math.round(f.low_f) + '\u00b0</span></span>' +
      '</div>').join('');
    function stat(k, v) { return '<div class="wx-stat"><div class="k">' + k + '</div><div class="v">' + v + '</div></div>'; }
  };

  /* ---------------- ESPP ---------------- */
  P.espp = function () {
    const e = API.get('espp:holdings');
    if (!e) return;
    const dir = F.updir(e.total_return_dollar), sgn = F.sign(e.total_return_dollar);
    $('espp-body').innerHTML =
      '<div class="espp-top"><div class="espp-sym"><span class="t">' + F.esc(e.ticker) + '</span>' +
      '<span class="n">' + F.esc(e.name) + '</span></div>' +
      '<span class="espp-badge">ESPP</span></div>' +
      '<div class="espp-val"><span class="v">' + F.usd(e.current_value, 2) + '</span>' +
      '<span class="chip ' + dir + '">' + F.pct(e.total_return_percent) + '</span></div>' +
      '<div class="espp-grid">' +
      cell('Shares', e.shares.toFixed(2)) +
      cell('Cost Basis', F.usd(e.cost_basis)) +
      cell('Price', F.usd(e.current_price)) +
      '</div>' +
      '<div style="display:flex;justify-content:space-between;margin-top:10px;font-family:\'IBM Plex Mono\';font-size:12px">' +
      '<span style="color:var(--tx-4)">Unrealized P&amp;L</span>' +
      '<span class="' + sgn + '" style="font-weight:600">' + F.usdSigned(e.total_return_dollar) + '</span></div>';
    function cell(k, v) { return '<div class="espp-cell"><div class="k">' + k + '</div><div class="v">' + v + '</div></div>'; }
  };

  /* ---------------- AI PORTFOLIO ANALYSIS ---------------- */
  P.aiPortfolio = function () {
    const d = API.get('ai:portfolio');
    if (!d) return;

    // Build a lookup from live holdings for inline metrics
    const holdings = API.get('portfolio:holdings') || [];
    const hMap = {};
    holdings.forEach(function (h) { hMap[h.ticker] = h; });

    const signals = d.signals || [];
    const ACTION_COLOR = { buy: 'var(--gain)', trim: 'var(--loss)', hold: 'var(--tx-4)' };

    const sigHTML = signals.map(function (s) {
      const action = (s.action || 'hold').toLowerCase();
      const col = ACTION_COLOR[action] || 'var(--tx-4)';
      const reason = s.reason || '';
      const h = hMap[s.ticker] || {};
      const eq  = h.equity != null        ? F.usd(h.equity, 0)                   : '';
      const day = h.percent_change_today != null ? F.pct(h.percent_change_today)  : '';
      const tot = h.total_return_percent  != null ? F.pct(h.total_return_percent) : '';
      const dayCol = h.percent_change_today >= 0 ? 'var(--gain)' : 'var(--loss)';
      const totCol = h.total_return_percent >= 0 ? 'var(--gain)' : 'var(--loss)';

      const metrics = (eq || day || tot) ?
        '<span style="font-family:\'IBM Plex Mono\';font-size:10px;color:var(--tx-4);flex-shrink:0;white-space:nowrap">' +
        (eq  ? eq + ' ' : '') +
        (day ? '<span style="color:' + dayCol + '">' + day + '</span> ' : '') +
        (tot ? '<span style="color:' + totCol + '">(' + tot + ' total)</span>' : '') +
        '</span>' : '';

      return '<div style="margin-bottom:6px;font-size:11px;line-height:1.45">' +
        '<div style="display:flex;gap:6px;align-items:baseline;flex-wrap:wrap">' +
        '<span style="font-family:\'IBM Plex Mono\';font-weight:700;color:var(--tx);flex-shrink:0">' + F.esc(s.ticker) + '</span>' +
        '<span style="color:' + col + ';font-weight:700;text-transform:uppercase;font-size:10px;flex-shrink:0">' + F.esc(action) + '</span>' +
        metrics +
        '</div>' +
        (reason ? '<div style="color:var(--tx-3);margin-top:1px">' + F.esc(reason) + '</div>' : '') +
        '</div>';
    }).join('');

    let html = '';
    if (sigHTML) html += '<div style="margin-bottom:8px">' + sigHTML + '</div>';
    if (d.top_risk) html += '<div style="font-size:11px;color:var(--loss);margin-bottom:6px;line-height:1.45;border-left:2px solid var(--loss);padding-left:7px">&#9888; ' + F.esc(d.top_risk) + '</div>';
    if (d.summary)  html += '<div style="font-size:11px;color:var(--tx-2);line-height:1.5' + (sigHTML || d.top_risk ? ';border-top:1px solid var(--border);padding-top:6px' : '') + '">' + richText(d.summary) + '</div>';
    const metaStr = (d.model ? d.model + ' · ' : '') + (F.etTime(d.generated_at) || '');
    Carousel.update('portfolio', 'Portfolio · AI', html || '—', metaStr);
  };

  /* ---------------- TICKER TAPE ---------------- */
  P.ticker = function () {
    // Holdings plus any admin-configured extra symbols (ticker:extra).
    const items = (API.get('portfolio:holdings') || []).concat(API.get('ticker:extra') || []);
    if (!items.length) return;
    const itemHTML = (h) => {
      const dir = h.percent_change_today >= 0 ? 'up' : 'down';
      return '<div class="tk-item"><span class="tk-sym">' + F.esc(h.ticker) + '</span>' +
        '<span class="tk-px">' + F.usd(h.current_price) + '</span>' +
        '<span class="tk-arrow ' + dir + '">' + (dir === 'up' ? '\u25B2' : '\u25BC') + '</span>' +
        '<span class="tk-chg ' + dir + '">' + F.pct(h.percent_change_today) + '</span></div>';
    };
    const html = items.map(itemHTML).join('');
    $('ticker-track').innerHTML = html + html + html;
    window.AutoScroll && window.AutoScroll.refreshTicker();
  };

  window.Panels = P;
})();
