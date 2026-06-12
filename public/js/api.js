/* ============================================================
   API CLIENT — HTTP polling against api.php (LAPP port).
   The original used a WebSocket push; here cron-driven pollers write state
   into Postgres and we fetch the full snapshot every POLL_MS, emitting only
   channels whose payload actually changed (so panels don't re-render and
   reset their auto-scroll position every poll).
   Falls back to mock data if the API is unreachable or empty; recovers to
   live automatically when real data appears.
   ============================================================ */
(function () {
  var POLL_MS = 60000;
  var STATE_URL = 'api.php?action=state';

  var state = {};
  var listeners = {};
  var mode = 'connecting';

  function emit(channel, data) {
    (listeners[channel] || []).forEach(function (fn) {
      try { fn(data); } catch (e) { console.error(e); }
    });
  }
  function setState(key, data) { state[key] = data; }

  var API = {
    on: function (channel, fn) { (listeners[channel] = listeners[channel] || []).push(fn); return API; },
    get: function (key) { return state[key]; },
    mode: function () { return mode; },
    onMode: function (fn) { API._modeFn = fn; if (fn) fn(mode); },
    inject: function (channel, data) { setState(channel, data); emit(channel, data); },
    http: window.location.origin,
  };
  function setMode(m) { if (m === mode) return; mode = m; if (API._modeFn) API._modeFn(m); }

  // ---------------- LIVE MODE — HTTP polling ----------------
  var _liveConfirmed = false;
  var _lastJson = {};

  function fetchState() {
    fetch(STATE_URL, { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
      .then(function (d) {
        var ch = (d && d.channels) || {};
        var any = false;
        Object.keys(ch).forEach(function (k) {
          if (ch[k] === null || ch[k] === undefined) return;
          any = true;
          var s = JSON.stringify(ch[k]);
          if (_lastJson[k] === s) return;
          _lastJson[k] = s;
          setState(k, ch[k]);
          emit(k, ch[k]);
        });
        if (any) {
          if (!_liveConfirmed) { _liveConfirmed = true; stopMock(); }
          setMode('live');
        }
        // Reachable but empty (fresh install, pollers haven't run yet):
        // stay in 'connecting' and let the 5s mock fallback kick in.
      })
      .catch(function () {
        if (_liveConfirmed) setMode('down');   // keep stale data on screen
        else fallbackMock();
      });
  }

  // Refresh promptly when the kiosk/tab wakes up or the network returns
  document.addEventListener('visibilitychange', function () { if (!document.hidden) fetchState(); });
  window.addEventListener('online', fetchState);
  window.addEventListener('pageshow', fetchState);

  // ---------------- MOCK MODE ----------------
  var _mockActive = false;
  var _mockTimers = [];

  function fallbackMock() {
    if (_liveConfirmed || _mockActive) return;
    _mockActive = true;
    setMode('mock');
    seedMock();
    _mockTimers = [
      setInterval(simulateTick, 3200),
      setInterval(simulateSlow, 30000),
    ];
  }

  function stopMock() {
    if (!_mockActive) return;
    _mockActive = false;
    _mockTimers.forEach(clearInterval);
    _mockTimers = [];
  }

  function seedMock() {
    setState('portfolio:summary',  MOCK.summary());
    setState('portfolio:holdings', MOCK.holdings());
    setState('portfolio:movers',   MOCK.movers(10));
    setState('portfolio:history',  MOCK.history('week'));
    setState('espp:holdings',      MOCK.espp());
    setState('news:global',        MOCK.global());
    setState('news:cyber',         MOCK.cyber());
    setState('truth:posts',        MOCK.truth());
    setState('weather:current',    MOCK.weather());
    setState('ai:briefing',        { briefing: MOCK.briefing() });
    setState('ai:portfolio',       MOCK.portfolioAnalysis());
    setState('ai:cyber',           MOCK.cyberDigest());
    Object.keys(state).forEach(function (k) { emit(k, state[k]); });
    emit('refresh', { mode: 'mock' });
  }

  function simulateTick() {
    if (!_mockActive) return;
    var holdings = state['portfolio:holdings'];
    if (!holdings) return;
    var dayDollarTotal = 0, equityTotal = 0, costTotal = 0;
    holdings.forEach(function (h) {
      var wiggle = (Math.random() - 0.48) * (h.current_price * 0.0016);
      h.current_price = +(h.current_price + wiggle).toFixed(2);
      h.equity = +(h.current_price * h.quantity).toFixed(2);
      var prevClose = h.current_price / (1 + h.percent_change_today / 100);
      h.percent_change_today = +(((h.current_price - prevClose) / prevClose) * 100 + (Math.random() - 0.48) * 0.05).toFixed(2);
      h.dollar_change_today = +((h.current_price - prevClose) * h.quantity).toFixed(2);
      var cost = h.average_buy_price * h.quantity;
      h.total_return_dollar = +(h.equity - cost).toFixed(2);
      h.total_return_percent = +((h.total_return_dollar / cost) * 100).toFixed(2);
      h.updated_at = new Date().toISOString();
      dayDollarTotal += h.dollar_change_today; equityTotal += h.equity; costTotal += cost;
    });
    holdings.sort(function (a, b) { return b.equity - a.equity; });

    var summary = state['portfolio:summary'];
    if (summary) {
      summary.total_equity = +equityTotal.toFixed(2);
      summary.total_return_dollar = +(equityTotal - costTotal).toFixed(2);
      summary.total_return_percent = +(((equityTotal - costTotal) / costTotal) * 100).toFixed(2);
      summary.daily_change_dollar = +dayDollarTotal.toFixed(2);
      summary.daily_change_percent = +((dayDollarTotal / (equityTotal - dayDollarTotal)) * 100).toFixed(2);
      summary.updated_at = new Date().toISOString();
    }
    var movers = holdings.map(function (h) {
      return { ticker: h.ticker, name: h.name, percent_change: h.percent_change_today, dollar_change: h.dollar_change_today, direction: h.percent_change_today >= 0 ? 'up' : 'down' };
    }).sort(function (a, b) { return Math.abs(b.percent_change) - Math.abs(a.percent_change); });
    setState('portfolio:movers', movers);

    var hist = state['portfolio:history'];
    if (hist && hist.equity_historicals) {
      hist.equity_historicals.push({ adjusted_close_equity: summary.total_equity, begins_at: new Date().toISOString() });
      if (hist.equity_historicals.length > 60) hist.equity_historicals.shift();
    }

    var espp = state['espp:holdings'];
    if (espp) {
      espp.current_price = +(espp.current_price + (Math.random() - 0.48) * 0.25).toFixed(2);
      espp.current_value = +(espp.current_price * espp.shares).toFixed(2);
      var ecost = espp.cost_basis * espp.shares;
      espp.total_return_dollar = +(espp.current_value - ecost).toFixed(2);
      espp.total_return_percent = +((espp.total_return_dollar / ecost) * 100).toFixed(2);
    }

    emit('portfolio:holdings', holdings);
    emit('portfolio:summary', summary);
    emit('portfolio:movers', movers);
    emit('portfolio:history', hist);
    emit('espp:holdings', espp);
  }

  function simulateSlow() {
    if (!_mockActive) return;
    var wx = state['weather:current'];
    if (wx) {
      wx.current.temperature_f = +(wx.current.temperature_f + (Math.random() - 0.5) * 0.6).toFixed(0);
      wx.current.wind_mph = Math.max(2, +(wx.current.wind_mph + (Math.random() - 0.5)).toFixed(0));
      emit('weather:current', wx);
    }
  }

  // ---------------- BOOT ----------------
  function boot() {
    fetchState();
    setInterval(fetchState, POLL_MS);
    // If nothing arrives shortly after load, show demo data while polling continues
    setTimeout(function () { if (!_liveConfirmed) fallbackMock(); }, 5000);
  }

  API.boot = boot;
  window.API = API;
})();
