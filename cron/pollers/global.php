<?php
/**
 * Global news poller — aggregates the Global Feed RSS sources into news:global.
 * Sources, item cap, max age and refresh interval are all admin-configurable
 * (feed_global_* / poll_interval_global settings); defaults live in config.php.
 */
require_once __DIR__ . '/_feedlib.php';

function poll_global(): string {
    $sources  = feed_parse_sources(setting('feed_global_sources', ''), GLOBAL_FEEDS_DEFAULT);
    $maxItems = max(1, (int) setting_or('feed_global_max',       (string)GLOBAL_MAX_ITEMS));
    $maxAge   = max(1, (int) setting_or('feed_global_max_age_h', (string)NEWS_MAX_AGE_HOURS));

    // Holdings + ESPP tickers drive the "finance" ribbon / ticker chips.
    $tickers = array_map(fn($h) => strtoupper($h['ticker']), holdings_rows());
    $espp = strtoupper(trim(setting('espp_ticker')));
    if ($espp !== '' && !in_array($espp, $tickers, true)) $tickers[] = $espp;

    $global = [];
    foreach ($sources as [$name, $url]) {
        $body = http_get($url, 10);
        if ($body !== null) $global = array_merge($global, parse_feed_xml($body, $name, 'global'));
    }

    foreach ($global as &$a) {
        $text = strtolower($a['title'] . ' ' . ($a['summary'] ?? ''));
        foreach (FINANCE_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) { $a['tags'][] = 'finance'; break; }
        }
        $a['tickers'] = news_match_tickers($a['title'] . ' ' . ($a['summary'] ?? ''), $tickers);
    }
    unset($a);

    $out = news_fresh_filter(news_dedup_sort($global), $maxAge);

    // TTL 3× the refresh interval so articles survive until the next cycle + buffer.
    $ttl = poller_interval('global', POLL_INTERVALS['global']) * 3;
    kv_set('news:global', array_slice($out, 0, $maxItems), $ttl);

    return 'Global updated: ' . count($out) . ' articles from ' . count($sources) . ' feeds';
}
