/* ============================================================
   MOCK DATA — shapes match api/models/portfolio.py & news.py
   Used as fallback when the API at localhost:8000 is unreachable.
   ============================================================ */
(function () {
  const now = () => new Date().toISOString();

  // ---- Holdings (matches Holding model) ----
  const HOLDINGS = [
    { ticker: 'NVDA', name: 'NVIDIA Corp',        quantity: 46,  average_buy_price: 78.40,  current_price: 171.22, pe_ratio: 52.3 },
    { ticker: 'PLTR', name: 'Palantir Tech',      quantity: 240, average_buy_price: 24.10,  current_price: 41.86,  pe_ratio: 218.4 },
    { ticker: 'MSFT', name: 'Microsoft Corp',     quantity: 22,  average_buy_price: 312.55, current_price: 468.90, pe_ratio: 37.1 },
    { ticker: 'AAPL', name: 'Apple Inc',          quantity: 54,  average_buy_price: 168.20, current_price: 224.15, pe_ratio: 34.6 },
    { ticker: 'LMT',  name: 'Lockheed Martin',    quantity: 14,  average_buy_price: 432.10, current_price: 489.55, pe_ratio: 19.2 },
    { ticker: 'AMD',  name: 'Advanced Micro Dev', quantity: 70,  average_buy_price: 132.80, current_price: 119.04, pe_ratio: 41.8 },
    { ticker: 'RTX',  name: 'RTX Corporation',    quantity: 60,  average_buy_price: 96.40,  current_price: 128.72, pe_ratio: 27.5 },
    { ticker: 'RKLB', name: 'Rocket Lab USA',     quantity: 300, average_buy_price: 6.85,   current_price: 24.18,  pe_ratio: null },
    { ticker: 'AMZN', name: 'Amazon.com Inc',     quantity: 28,  average_buy_price: 142.30, current_price: 219.40, pe_ratio: 44.9 },
    { ticker: 'GOOGL',name: 'Alphabet Inc',       quantity: 30,  average_buy_price: 138.90, current_price: 184.66, pe_ratio: 26.3 },
    { ticker: 'TSLA', name: 'Tesla Inc',          quantity: 24,  average_buy_price: 248.60, current_price: 198.30, pe_ratio: 61.7 },
    { ticker: 'CRWD', name: 'CrowdStrike',        quantity: 12,  average_buy_price: 268.40, current_price: 421.10, pe_ratio: 98.2 },
  ];

  // seed today's % move per holding (deterministic-ish baseline)
  const DAY_MOVE = { NVDA: 3.42, PLTR: 6.18, MSFT: 0.84, AAPL: -0.62, LMT: 1.27, AMD: -2.84, RTX: 0.95, RKLB: 4.71, AMZN: 1.43, GOOGL: -0.38, TSLA: -3.21, CRWD: 2.06 };

  function buildHolding(h) {
    const dayPct = DAY_MOVE[h.ticker] ?? 0;
    const equity = h.current_price * h.quantity;
    const prevClose = h.current_price / (1 + dayPct / 100);
    const dayDollar = (h.current_price - prevClose) * h.quantity;
    const cost = h.average_buy_price * h.quantity;
    const totRet = equity - cost;
    const totPct = cost ? (totRet / cost) * 100 : 0;
    return {
      ticker: h.ticker, name: h.name, quantity: h.quantity,
      average_buy_price: h.average_buy_price, current_price: h.current_price,
      equity: +equity.toFixed(2),
      percent_change_today: +dayPct.toFixed(2),
      dollar_change_today: +dayDollar.toFixed(2),
      total_return_percent: +totPct.toFixed(2),
      total_return_dollar: +totRet.toFixed(2),
      pe_ratio: h.pe_ratio, updated_at: now(),
    };
  }

  function holdings() {
    return HOLDINGS.map(buildHolding).sort((a, b) => b.equity - a.equity);
  }

  function summary() {
    const hs = holdings();
    const totalEquity = hs.reduce((s, h) => s + h.equity, 0);
    const totalCost = hs.reduce((s, h) => s + h.average_buy_price * h.quantity, 0);
    const dayDollar = hs.reduce((s, h) => s + h.dollar_change_today, 0);
    const totRet = totalEquity - totalCost;
    return {
      total_equity: +totalEquity.toFixed(2),
      total_cost: +totalCost.toFixed(2),
      total_return_dollar: +totRet.toFixed(2),
      total_return_percent: +((totRet / totalCost) * 100).toFixed(2),
      daily_change_dollar: +dayDollar.toFixed(2),
      daily_change_percent: +((dayDollar / (totalEquity - dayDollar)) * 100).toFixed(2),
      buying_power: 4218.55,
      updated_at: now(),
    };
  }

  function movers(limit = 10) {
    const hs = holdings();
    return hs
      .map(h => ({ ticker: h.ticker, name: h.name, percent_change: h.percent_change_today, dollar_change: h.dollar_change_today, direction: h.percent_change_today >= 0 ? 'up' : 'down' }))
      .sort((a, b) => Math.abs(b.percent_change) - Math.abs(a.percent_change))
      .slice(0, limit);
  }

  // ---- portfolio history (sparkline) — array of {adjusted_close_equity} like robin-stocks ----
  function history(span = 'week') {
    const points = span === 'day' ? 78 : span === 'week' ? 40 : 60;
    const end = summary().total_equity;
    const start = end * 0.955;
    const arr = [];
    let v = start;
    for (let i = 0; i < points; i++) {
      const drift = (end - start) / points;
      v += drift + (Math.sin(i * 0.7) * end * 0.004) + (Math.random() - 0.5) * end * 0.006;
      arr.push({ adjusted_close_equity: +v.toFixed(2), begins_at: new Date(Date.now() - (points - i) * 36e5).toISOString() });
    }
    arr[arr.length - 1].adjusted_close_equity = end;
    return { span, equity_historicals: arr };
  }

  // ---- ESPP ----
  function espp() {
    const shares = 42.18, current = 187.65, costBasis = 152.30; // ~15% ESPP discount baseline
    const value = shares * current;
    const cost = shares * costBasis;
    return {
      ticker: 'ACME', name: 'Acme Industries', shares,
      cost_basis: costBasis, current_price: current,
      current_value: +value.toFixed(2),
      total_return_dollar: +(value - cost).toFixed(2),
      total_return_percent: +(((value - cost) / cost) * 100).toFixed(2),
      updated_at: now(),
    };
  }

  // ---- News (NewsArticle model) ----
  const t = (min) => new Date(Date.now() - min * 60000).toISOString();

  const GLOBAL = [
    { id: 'g1', title: 'NVIDIA unveils next-gen Rubin accelerators, raises full-year data-center guidance', summary: 'The chipmaker said Rubin-class GPUs will ship to hyperscalers in Q4, with demand outpacing supply into 2027.', source: 'Reuters', published_at: t(8), category: 'finance', tickers: ['NVDA'], sentiment: 'positive', sentiment_score: 0.81, tags: [] },
    { id: 'g2', title: 'Fed holds rates steady, signals one cut before year-end as inflation cools to 2.4%', summary: 'Chair Powell cited resilient labor markets; futures markets now price a September cut at 68% probability.', source: 'Bloomberg', published_at: t(22), category: 'finance', tickers: [], sentiment: 'neutral', sentiment_score: 0.12, tags: [] },
    { id: 'g3', title: 'White House issues executive order tightening export controls on advanced AI chips', summary: 'New rules expand licensing requirements for shipments to additional jurisdictions, effective in 90 days.', source: 'AP', published_at: t(31), category: 'global', tickers: ['NVDA', 'AMD'], sentiment: 'negative', sentiment_score: -0.34, tags: [] },
    { id: 'g4', title: 'Palantir wins $480M Army contract for battlefield AI targeting platform', summary: 'The multi-year award extends the company\u2019s Maven Smart System deployment across two combatant commands.', source: 'Defense News', published_at: t(44), category: 'finance', tickers: ['PLTR'], sentiment: 'positive', sentiment_score: 0.88, tags: [] },
    { id: 'g5', title: 'EU and US reach framework on cross-border data flows after months of talks', summary: 'The agreement aims to restore legal certainty for transatlantic cloud and AI services providers.', source: 'Financial Times', published_at: t(58), category: 'global', tickers: [], sentiment: 'positive', sentiment_score: 0.41, tags: [] },
    { id: 'g6', title: 'Lockheed posts record F-35 backlog as allied orders surge in Europe and Pacific', summary: 'Management raised free-cash-flow outlook on accelerating international deliveries.', source: 'WSJ', published_at: t(73), category: 'finance', tickers: ['LMT'], sentiment: 'positive', sentiment_score: 0.67, tags: [] },
    { id: 'g7', title: 'Oil slips below $68 as OPEC+ weighs output increase for third quarter', summary: 'Brent crude fell 1.9% amid demand concerns and rising US inventories.', source: 'Reuters', published_at: t(86), category: 'finance', tickers: [], sentiment: 'neutral', sentiment_score: -0.08, tags: [] },
    { id: 'g8', title: 'AMD warns of China revenue headwind as new chip restrictions take hold', summary: 'The company flagged a potential mid-single-digit hit to data-center sales this quarter.', source: 'CNBC', published_at: t(95), category: 'finance', tickers: ['AMD'], sentiment: 'negative', sentiment_score: -0.58, tags: [] },
    { id: 'g9', title: 'Senate advances bipartisan bill to fund domestic semiconductor packaging', summary: 'The $12B measure targets advanced packaging capacity to reduce reliance on overseas assembly.', source: 'Politico', published_at: t(112), category: 'global', tickers: [], sentiment: 'positive', sentiment_score: 0.36, tags: [] },
    { id: 'g10', title: 'Rocket Lab Neutron passes static fire test, first orbital launch slated for August', summary: 'A successful campaign would position the company as a medium-lift competitor to Falcon 9.', source: 'SpaceNews', published_at: t(128), category: 'finance', tickers: ['RKLB'], sentiment: 'positive', sentiment_score: 0.74, tags: [] },
    { id: 'g11', title: 'Tesla deliveries miss estimates as EV demand softens across key markets', summary: 'Q2 figures came in below consensus, pressuring shares in pre-market trading.', source: 'Bloomberg', published_at: t(140), category: 'finance', tickers: ['TSLA'], sentiment: 'negative', sentiment_score: -0.62, tags: [] },
    { id: 'g12', title: 'Treasury yields ease as soft jobs data revives rate-cut hopes', summary: 'The 10-year fell to 4.08%, its lowest level in six weeks.', source: 'MarketWatch', published_at: t(155), category: 'finance', tickers: [], sentiment: 'positive', sentiment_score: 0.28, tags: [] },
  ];

  const CYBER = [
    { id: 'c1', title: 'CISA orders federal agencies to patch actively exploited zero-day in VPN appliance', summary: 'Emergency directive sets a 72-hour remediation deadline after evidence of nation-state exploitation.', source: 'CISA', published_at: t(12), category: 'cyber', tickers: [], sentiment: 'negative', sentiment_score: -0.7, tags: ['threat'], priority: 1 },
    { id: 'c2', title: 'CMMC 2.0 final rule takes effect: DIB contractors face Level 2 assessment deadlines', summary: 'Prime contractors must verify third-party assessor certification before new DoD awards this fall.', source: 'DoD CIO', published_at: t(26), category: 'dib', tickers: [], sentiment: 'neutral', sentiment_score: 0.05, tags: ['cmmc', 'dib', 'policy'], priority: 2 },
    { id: 'c3', title: 'Researchers disclose prompt-injection flaw affecting major enterprise AI agents', summary: 'The technique can exfiltrate data via crafted documents; vendors are rolling out mitigations.', source: 'The Record', published_at: t(38), category: 'cyber', tickers: [], sentiment: 'negative', sentiment_score: -0.55, tags: ['ai', 'threat'], priority: 3 },
    { id: 'c4', title: 'NIST releases updated guidance on securing generative AI in regulated environments', summary: 'Framework addresses model supply chain, data governance, and adversarial testing for federal use.', source: 'NIST', published_at: t(51), category: 'cyber', tickers: [], sentiment: 'positive', sentiment_score: 0.34, tags: ['ai', 'policy'], priority: 4 },
    { id: 'c5', title: 'Defense contractor discloses breach exposing CUI from subcontractor network', summary: 'Investigation points to compromised credentials; affected programs under DoD review.', source: 'BleepingComputer', published_at: t(64), category: 'dib', tickers: [], sentiment: 'negative', sentiment_score: -0.78, tags: ['breach', 'dib', 'threat'], priority: 5 },
    { id: 'c6', title: 'Ransomware group claims attack on aerospace supplier, threatens to leak schematics', summary: 'The gang listed the firm on its leak site; no confirmation of CUI involvement yet.', source: 'SecurityWeek', published_at: t(79), category: 'cyber', tickers: [], sentiment: 'negative', sentiment_score: -0.66, tags: ['threat', 'dib'], priority: 6 },
    { id: 'c7', title: 'Pentagon expands zero-trust mandate to all DIB cloud service providers by FY27', summary: 'New memo accelerates implementation timelines and adds continuous monitoring requirements.', source: 'FedScoop', published_at: t(92), category: 'dib', tickers: [], sentiment: 'neutral', sentiment_score: 0.1, tags: ['dib', 'policy'], priority: 7 },
    { id: 'c8', title: 'Open-source AI model weights found leaking API keys in training data', summary: 'Audit of popular checkpoints reveals embedded secrets; cleanup tooling released.', source: 'Hacker News', published_at: t(108), category: 'cyber', tickers: [], sentiment: 'negative', sentiment_score: -0.4, tags: ['ai', 'breach'], priority: 8 },
  ];

  // ---- Weather (Open-Meteo shape via Weather model) ----
  function weather() {
    return {
      current: {
        location: 'New York, NY', temperature_f: 71, feels_like_f: 69,
        condition: 'Partly Cloudy', humidity: 58, wind_mph: 9, wind_direction: 'WSW',
        visibility_miles: 10, updated_at: now(),
      },
      forecast: [
        { date: 'Sat', high_f: 74, low_f: 56, condition: 'Partly Cloudy', precipitation_chance: 10 },
        { date: 'Sun', high_f: 79, low_f: 61, condition: 'Sunny', precipitation_chance: 5 },
        { date: 'Mon', high_f: 81, low_f: 64, condition: 'Mostly Sunny', precipitation_chance: 15 },
        { date: 'Tue', high_f: 77, low_f: 62, condition: 'Scattered Storms', precipitation_chance: 55 },
        { date: 'Wed', high_f: 72, low_f: 58, condition: 'Rain Showers', precipitation_chance: 70 },
        { date: 'Thu', high_f: 75, low_f: 57, condition: 'Cloudy', precipitation_chance: 30 },
        { date: 'Fri', high_f: 78, low_f: 60, condition: 'Partly Cloudy', precipitation_chance: 20 },
      ],
    };
  }

  // ---- AI outputs ----
  function briefing() {
    // Plain text \u2014 the briefing renderer escapes HTML and highlights
    // tickers/percentages itself (see richText in panels.js).
    return "Good morning. Your portfolio opens up +1.18% premarket, led by PLTR +6.18% on a new $480M Army AI contract and NVDA +3.42% after raising data-center guidance. Watch AMD -2.84% and TSLA -3.21% \u2014 fresh China chip restrictions and soft EV deliveries are weighing on both. The Fed held rates steady and signaled one cut before year-end. Locally, it's 71\u00b0F and partly cloudy, with scattered storms moving in Tuesday.";
  }

  function portfolioAnalysis() {
    return {
      signals: [
        { ticker: 'NVDA', signal: 'hold', reason: 'Strong guidance, but valuation stretched at 52x \u2014 trim only on further parabolic moves.' },
        { ticker: 'PLTR', signal: 'hold', reason: 'Contract momentum intact; 218x P/E leaves little margin for execution misses.' },
        { ticker: 'AMD',  signal: 'hold', reason: 'China headwind is a near-term risk; thesis intact on AI accelerator roadmap.' },
        { ticker: 'TSLA', signal: 'sell', reason: 'Delivery miss and demand softness; position is underwater \u2014 consider tax-loss harvest.' },
      ],
      top_risk: 'Semiconductor concentration: NVDA + AMD + NVDA-adjacent names are 41% of equity, exposed to export-control policy.',
      summary: 'Portfolio is well-positioned on AI/defense secular themes but carries elevated single-sector risk. No action urgently required.',
    };
  }

  // ---- Truth Social posts (matches workers/truth_poller.py shape) ----
  const TRUTH = [
    { id: 't1', text: 'Just had a GREAT meeting on Trade. Tremendous progress being made, like never before. Details to follow!', url: '#', published_at: new Date(Date.now() - 22 * 60000).toISOString(), is_retruth: false, has_media: false },
    { id: 't2', text: 'The Fake News Media will never report it, but our Economy is the STRONGEST it has ever been. Jobs are BOOMING!', url: '#', published_at: new Date(Date.now() - 95 * 60000).toISOString(), is_retruth: false, has_media: false },
    { id: 't3', text: 'Big announcement coming soon. Stay tuned!!!', url: '#', published_at: new Date(Date.now() - 4 * 3600000).toISOString(), is_retruth: false, has_media: false },
    { id: 't4', text: '', url: '#', published_at: new Date(Date.now() - 6 * 3600000).toISOString(), is_retruth: false, has_media: true,
      images: ['data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="#1d2735"/><text x="160" y="95" fill="#5a6b85" font-family="monospace" font-size="14" text-anchor="middle">MOCK IMAGE</text></svg>')] },
  ];

  function cyberDigest() {
    return {
      digest: 'Active VPN zero-day exploitation tops today\u2019s priorities \u2014 patch within 72 hours per CISA. CMMC 2.0 Level 2 deadlines now firm for DIB primes. A subcontractor breach exposing CUI warrants supply-chain review.',
      articles: CYBER,
    };
  }

  window.MOCK = {
    holdings, summary, movers, history, espp, weather, briefing,
    portfolioAnalysis, cyberDigest,
    global: () => GLOBAL.slice(), cyber: () => CYBER.slice(), truth: () => TRUTH.slice(),
  };
})();
