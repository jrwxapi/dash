<?php
// Database connection
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'dashboard');
define('DB_USER', 'dashboard');
define('DB_PASS', 'dashboard_pass');

// App
define('APP_NAME', 'Personal Intelligence Dashboard');

// Default poller intervals (seconds). cron/poll.php runs every minute and fires
// each poller whose interval has elapsed since its last successful run. These are
// defaults only — the admin page can override any of them via the
// 'poll_interval_<name>' setting (see cron/poll.php).
define('POLL_INTERVALS', [
    'quotes'  => 300,    // Yahoo Finance quotes — 5 min
    'truth'   => 600,    // Truth Social mirror — 10 min
    'weather' => 900,    // Open-Meteo — 15 min
    'global'  => 1800,   // Global RSS feeds — 30 min
    'cyber'   => 1800,   // Cyber/DIB RSS feeds — 30 min
    'ai'      => 900,    // Ollama digest check — 15 min (skips fresh digests)
]);

// How old an article/post can be before it's dropped from the feed panels.
// Defaults only — overridable per feed via the 'feed_*_max_age_h' settings.
define('NEWS_MAX_AGE_HOURS', 48);
define('TRUTH_MAX_AGE_HOURS', 24);

// How long (minutes) a freshly published item stays highlighted in the feed
// panels. Default only — overridable per feed via 'feed_*_highlight_min'.
define('FEED_HIGHLIGHT_MIN', 30);

// Ticker tape scroll speed (px/sec). Overridable via the 'ticker_speed' setting.
// Extra symbols to scroll alongside holdings live in 'ticker_extra_symbols'.
define('TICKER_SPEED', 60);

// Default cap on items kept per feed (overridable via 'feed_*_max').
define('GLOBAL_MAX_ITEMS', 60);
define('CYBER_MAX_ITEMS', 40);
define('TRUTH_MAX_POSTS', 20);

// Default feed sources. Editable per feed on the admin page (one "Name | URL"
// per line for the RSS lists). The pollers fall back to these when the
// matching setting is blank.
define('GLOBAL_FEEDS_DEFAULT', [
    ['NPR Top News',  'https://feeds.npr.org/1001/rss.xml'],
    ['The Guardian',  'https://www.theguardian.com/world/rss'],
    ['BBC World',     'http://feeds.bbci.co.uk/news/world/rss.xml'],
    ['White House',   'https://www.whitehouse.gov/feed/'],
    ['Politico',      'https://rss.politico.com/politics-news.xml'],
    ['MarketWatch',   'https://feeds.marketwatch.com/marketwatch/topstories/'],
    ['Yahoo Finance', 'https://finance.yahoo.com/news/rssindex'],
    ['WSJ Markets',   'https://feeds.content.dowjones.io/public/rss/mw_marketpulse'],
]);
define('CYBER_FEEDS_DEFAULT', [
    ['Krebs on Security', 'https://krebsonsecurity.com/feed/'],
    ['Bleeping Computer', 'https://www.bleepingcomputer.com/feed/'],
    ['Dark Reading',      'https://www.darkreading.com/rss.xml'],
    ['SANS ISC',          'https://isc.sans.edu/rssfeed_full.xml'],
    ['The Hacker News',   'https://feeds.feedburner.com/TheHackersNews'],
    ['Defense One',       'https://www.defenseone.com/rss/all/'],
    ['Breaking Defense',  'https://breakingdefense.com/feed/'],
    ['CISA Alerts',       'https://www.cisa.gov/uscert/ncas/alerts.xml'],
]);
define('TRUTH_RSS_DEFAULT', 'https://trumpstruth.org/feed');

// ── Local AI (Ollama) defaults ──────────────────────────────────────────────
// All overridable from the admin AI section; the poller (cron/pollers/ai.php)
// falls back to these when the matching setting is blank.
// Don't regenerate an AI digest younger than this (seconds).
define('AI_DIGEST_MIN_INTERVAL', 1800);
// Sampling temperature and max response tokens for every Ollama call.
define('AI_TEMPERATURE', 0.15);
define('AI_NUM_PREDICT', 1024);

// System prompts — these define what each AI card does and the exact output
// shape it must produce. Editable per card on the admin page (ai_prompt_*).
define('AI_SYSTEM_PROMPTS', [
    'daily_briefing' => <<<'PROMPT'
You are a personal financial briefing writer. You receive market and news data. Write a short plain-text briefing of at most 3 sentences, using ONLY facts present in the data.

Sentence 1 — Portfolio: whether the portfolio is up or down today, the leading and lagging tickers with their day percentages, and the total equity. Example shape: "Portfolio is up today — XYZ leads at 2.1%, ABC lags at -1.4%, with overall equity at $12,345.67."
Sentence 2 — Markets: paraphrase the most important stock or market headline provided.
Sentence 3 — World: paraphrase the most significant remaining headline provided.

Hard rules:
- Exactly 3 sentences. Stop immediately after the period of the third sentence.
- NEVER invent tickers, prices, percentages, or news that are not in the data.
- If the data for a sentence is missing, write fewer sentences. Do not explain why.
- Output ONLY the briefing prose. Never mention these instructions, the data format, field names, JSON, or anything you skipped or omitted. No notes, no parentheticals about your process, no markdown.
PROMPT,

    'portfolio_analyst' => 'You are a concise portfolio analyst. Using only the data provided, write exactly 3 plain-text sentences separated by spaces. Do not use markdown, bullet points, headers, or JSON. Do not invent any numbers or tickers not present in the data. Sentence 1: state whether the portfolio is up or down today and the total equity value. Sentence 2: name the top gaining and top losing ticker with their day percentages. Sentence 3: state one risk or notable item from the news headlines. Output only the 3 sentences and nothing else.',

    'global_digest' => 'You output ONLY this JSON structure, nothing else:
{"digest":"string"}
The "digest" is 1-2 sentences summarizing the single most important story from the provided headlines. Be specific — name the event, country, or company. Use plain text only. No markdown. Just the JSON.',

    'cyber_digest' => 'You output ONLY this JSON structure, nothing else:
{"digest":"string","items":[{"headline":"string","tags":["string"],"priority":"string"}]}
The "digest" field is a 2-sentence summary of the most critical cybersecurity and defense news.
The "items" array has up to 5 entries. Each tag is one of: cmmc, ai, dib, threat, policy, breach.
Each priority is one of: high, medium, low. No markdown. No explanation. Just the JSON.',
]);

// All timestamps stored/served as UTC; the frontend renders them in the
// time zone configured on the admin page ('timezone' setting, default ET).
date_default_timezone_set('UTC');
