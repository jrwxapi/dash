<?php
/**
 * Cyber / DIB news poller — aggregates the Cyber Feed RSS sources into news:cyber.
 * Sources, item cap, max age and refresh interval are all admin-configurable
 * (feed_cyber_* / poll_interval_cyber settings); defaults live in config.php.
 */
require_once __DIR__ . '/_feedlib.php';

function poll_cyber(): string {
    $sources  = feed_parse_sources(setting('feed_cyber_sources', ''), CYBER_FEEDS_DEFAULT);
    $maxItems = max(1, (int) setting_or('feed_cyber_max',       (string)CYBER_MAX_ITEMS));
    $maxAge   = max(1, (int) setting_or('feed_cyber_max_age_h', (string)NEWS_MAX_AGE_HOURS));

    $cyber = [];
    foreach ($sources as [$name, $url]) {
        $body = http_get($url, 10);
        if ($body !== null) $cyber = array_merge($cyber, parse_feed_xml($body, $name, 'cyber'));
    }

    $out = news_fresh_filter(news_dedup_sort($cyber), $maxAge);

    $ttl = poller_interval('cyber', POLL_INTERVALS['cyber']) * 3;
    kv_set('news:cyber', array_slice($out, 0, $maxItems), $ttl);

    return 'Cyber updated: ' . count($out) . ' articles from ' . count($sources) . ' feeds';
}
