/* ============================================================
   FORMATTERS + small helpers
   ============================================================ */
(function () {
  const F = {
    usd(n, dp = 2) {
      if (n == null || isNaN(n)) return '\u2014';
      return '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: dp, maximumFractionDigits: dp });
    },
    usdSigned(n, dp = 2) {
      if (n == null || isNaN(n)) return '\u2014';
      const s = n >= 0 ? '+' : '\u2212';
      return s + '$' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: dp, maximumFractionDigits: dp });
    },
    pct(n) {
      if (n == null || isNaN(n)) return '\u2014';
      const s = n >= 0 ? '+' : '\u2212';
      return s + Math.abs(n).toFixed(2) + '%';
    },
    compact(n) {
      if (n == null) return '\u2014';
      return '$' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 });
    },
    ago(iso) {
      if (!iso) return '';
      const diff = (Date.now() - new Date(iso).getTime()) / 60000;
      if (diff < 1) return 'now';
      if (diff < 60) return Math.floor(diff) + 'm';
      if (diff < 1440) return Math.floor(diff / 60) + 'h';
      return Math.floor(diff / 1440) + 'd';
    },
    sign(n) { return n >= 0 ? 'pos' : 'neg'; },
    updir(n) { return n >= 0 ? 'up' : 'down'; },
  };

  const WX_ICON = {
    'Sunny': '\u2600\uFE0F', 'Mostly Sunny': '\uD83C\uDF24\uFE0F', 'Partly Cloudy': '\u26C5',
    'Cloudy': '\u2601\uFE0F', 'Mostly Cloudy': '\u2601\uFE0F', 'Overcast': '\u2601\uFE0F',
    'Rain Showers': '\uD83C\uDF26\uFE0F', 'Rain': '\uD83C\uDF27\uFE0F', 'Light Rain': '\uD83C\uDF26\uFE0F',
    'Scattered Storms': '\u26C8\uFE0F', 'Thunderstorm': '\u26C8\uFE0F', 'Snow': '\uD83C\uDF28\uFE0F',
    'Fog': '\uD83C\uDF2B\uFE0F', 'Clear': '\uD83C\uDF19',
  };
  F.wxIcon = (c) => WX_ICON[c] || '\u26C5';

  // count-up animation on a numeric element
  F.countUp = (el, to, render, dur = 600) => {
    const firstRender = el.dataset.val === undefined || el.dataset.val === '';
    const from = firstRender ? to : parseFloat(el.dataset.val);
    el.dataset.val = to;
    if (firstRender || from === to) { el.innerHTML = render(to); return; }
    const start = performance.now();
    function step(now) {
      const p = Math.min(1, (now - start) / dur);
      const e = 1 - Math.pow(1 - p, 3);
      const v = from + (to - from) * e;
      el.innerHTML = render(v);
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  };

  F.flash = (el, dir) => {
    el.classList.remove('flash-up', 'flash-down');
    void el.offsetWidth;
    el.classList.add(dir >= 0 ? 'flash-up' : 'flash-down');
  };

  F.esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  // Format any Date or ISO string as h:mm AM/PM ET
  F.etTime = function (src) {
    if (!src) return null;
    const d = (src instanceof Date) ? src : new Date(src);
    if (isNaN(d)) return null;
    return d.toLocaleTimeString('en-US', {
      hour: 'numeric', minute: '2-digit',
      timeZone: 'America/New_York',
      hour12: true,
    }) + ' ET';
  };

  window.F = F;
})();
