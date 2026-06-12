<?php
/**
 * News poller — aggregates RSS feeds into the global and cyber panels.
 * Port of workers/news_poller.py (minus yfinance per-ticker stock news).
 * Writes kv keys: news:global, news:cyber
 */

const GLOBAL_FEEDS = [
    ['NPR Top News',  'https://feeds.npr.org/1001/rss.xml'],
    ['The Guardian',  'https://www.theguardian.com/world/rss'],
    ['BBC World',     'http://feeds.bbci.co.uk/news/world/rss.xml'],
    ['White House',   'https://www.whitehouse.gov/feed/'],
    ['Politico',      'https://rss.politico.com/politics-news.xml'],
    ['MarketWatch',   'https://feeds.marketwatch.com/marketwatch/topstories/'],
    ['Yahoo Finance', 'https://finance.yahoo.com/news/rssindex'],
    ['WSJ Markets',   'https://feeds.content.dowjones.io/public/rss/mw_marketpulse'],
];

const CYBER_FEEDS = [
    ['Krebs on Security', 'https://krebsonsecurity.com/feed/'],
    ['Bleeping Computer', 'https://www.bleepingcomputer.com/feed/'],
    ['Dark Reading',      'https://www.darkreading.com/rss.xml'],
    ['SANS ISC',          'https://isc.sans.edu/rssfeed_full.xml'],
    ['The Hacker News',   'https://feeds.feedburner.com/TheHackersNews'],
    ['Defense One',       'https://www.defenseone.com/rss/all/'],
    ['Breaking Defense',  'https://breakingdefense.com/feed/'],
    ['CISA Alerts',       'https://www.cisa.gov/uscert/ncas/alerts.xml'],
];

const FINANCE_KEYWORDS = [
    'stock', 'market', 'earnings', 'fed', 'interest rate', 'inflation',
    'recession', 'gdp', 'jobs', 'unemployment', 's&p', 'nasdaq', 'dow',
    'treasury', 'bond', 'yield', 'ipo', 'merger', 'acquisition',
];

/** Parse an RSS 2.0 or Atom feed body into normalized article arrays. */
function parse_feed_xml(string $body, string $source, string $category): array {
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($xml === false) return [];

    $entries = [];
    if (isset($xml->channel->item)) {                 // RSS 2.0
        foreach ($xml->channel->item as $item) {
            $entries[] = [
                'title'   => (string)$item->title,
                'summary' => (string)($item->description ?? ''),
                'link'    => trim((string)$item->link),
                'date'    => (string)($item->pubDate ?? ''),
            ];
        }
    } elseif (isset($xml->entry)) {                   // Atom
        foreach ($xml->entry as $entry) {
            $link = '';
            foreach ($entry->link as $l) {
                $rel = (string)$l['rel'];
                if ($rel === '' || $rel === 'alternate') { $link = (string)$l['href']; break; }
            }
            $entries[] = [
                'title'   => (string)$entry->title,
                'summary' => (string)($entry->summary ?? $entry->content ?? ''),
                'link'    => $link,
                'date'    => (string)($entry->published ?? $entry->updated ?? ''),
            ];
        }
    }

    $articles = [];
    foreach (array_slice($entries, 0, 15) as $e) {
        if ($e['title'] === '' || $e['link'] === '') continue;
        $summary = trim(html_entity_decode(strip_tags($e['summary']), ENT_QUOTES | ENT_HTML5));
        $ts = $e['date'] !== '' ? strtotime($e['date']) : false;
        $articles[] = [
            'id'              => substr(md5($e['link']), 0, 12),
            'title'           => trim(html_entity_decode($e['title'], ENT_QUOTES | ENT_HTML5)),
            'summary'         => $summary !== '' ? mb_substr($summary, 0, 400) : null,
            'url'             => $e['link'],
            'source'          => $source,
            'published_at'    => gmdate('c', $ts !== false ? $ts : time()),
            'category'        => $category,
            'tickers'         => [],
            'sentiment'       => null,
            'sentiment_score' => null,
            'tags'            => [],
        ];
    }
    return $articles;
}

function news_is_fresh(array $a): bool {
    $ts = strtotime($a['published_at'] ?? '');
    if ($ts === false) return true;   // keep if date can't be parsed
    return (time() - $ts) < NEWS_MAX_AGE_HOURS * 3600;
}

function news_dedup_sort(array $articles): array {
    $seen = [];
    foreach ($articles as $a) {
        if (!isset($seen[$a['id']])) $seen[$a['id']] = $a;
    }
    $out = array_values($seen);
    usort($out, fn($x, $y) => strcmp($y['published_at'], $x['published_at']));
    return $out;
}

function news_match_tickers(string $text, array $tickers): array {
    $matched = [];
    $upper = strtoupper($text);
    foreach ($tickers as $t) {
        // Word-boundary match so "AMD" doesn't hit "AMENDED"
        if ($t !== '' && preg_match('/\b' . preg_quote($t, '/') . '\b/', $upper)) {
            $matched[] = $t;
        }
    }
    return $matched;
}

function poll_news(): string {
    $tickers = array_map(fn($h) => strtoupper($h['ticker']), holdings_rows());
    $espp = strtoupper(trim(setting('espp_ticker')));
    if ($espp !== '' && !in_array($espp, $tickers, true)) $tickers[] = $espp;

    $global = [];
    foreach (GLOBAL_FEEDS as [$name, $url]) {
        $body = http_get($url, 10);
        if ($body !== null) $global = array_merge($global, parse_feed_xml($body, $name, 'global'));
    }

    $cyber = [];
    foreach (CYBER_FEEDS as [$name, $url]) {
        $body = http_get($url, 10);
        if ($body !== null) $cyber = array_merge($cyber, parse_feed_xml($body, $name, 'cyber'));
    }

    foreach ($global as &$a) {
        $text = strtolower($a['title'] . ' ' . ($a['summary'] ?? ''));
        foreach (FINANCE_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) { $a['tags'][] = 'finance'; break; }
        }
        $a['tickers'] = news_match_tickers($a['title'] . ' ' . ($a['summary'] ?? ''), $tickers);
    }
    unset($a);

    $globalOut = array_values(array_filter(news_dedup_sort($global), 'news_is_fresh'));
    $cyberOut  = array_values(array_filter(news_dedup_sort($cyber),  'news_is_fresh'));

    // TTL 3× poll interval so articles survive until next cycle + buffer
    $ttl = POLL_INTERVALS['news'] * 3;
    kv_set('news:global', array_slice($globalOut, 0, 60), $ttl);
    kv_set('news:cyber',  array_slice($cyberOut,  0, 40), $ttl);

    return 'News updated: ' . count($globalOut) . ' global, ' . count($cyberOut) . ' cyber';
}
