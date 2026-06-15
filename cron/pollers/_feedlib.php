<?php
/**
 * Shared RSS/Atom helpers for the global + cyber feed pollers.
 * (Previously inlined in pollers/news.php, which was split into global.php and
 * cyber.php so each panel can have its own source list and refresh interval.)
 */

const FINANCE_KEYWORDS = [
    'stock', 'market', 'earnings', 'fed', 'interest rate', 'inflation',
    'recession', 'gdp', 'jobs', 'unemployment', 's&p', 'nasdaq', 'dow',
    'treasury', 'bond', 'yield', 'ipo', 'merger', 'acquisition',
];

/**
 * Parse an admin-editable sources blob into [[name, url], ...].
 * One source per line, "Name | URL" (a bare URL is allowed — it becomes its own
 * label). Blank lines and lines starting with # are ignored. Falls back to the
 * provided defaults when nothing valid is found, so a botched edit never empties
 * a panel.
 */
function feed_parse_sources(string $raw, array $default): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_contains($line, '|')) {
            [$name, $url] = array_map('trim', explode('|', $line, 2));
        } else {
            $name = ''; $url = $line;
        }
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $label = $name !== '' ? $name : ((parse_url($url, PHP_URL_HOST) ?: $url));
            $out[] = [$label, $url];
        }
    }
    return $out ?: $default;
}

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

/** Drop articles older than $maxAgeHours (keeps ones with unparseable dates). */
function news_fresh_filter(array $articles, int $maxAgeHours): array {
    return array_values(array_filter($articles, function ($a) use ($maxAgeHours) {
        $ts = strtotime($a['published_at'] ?? '');
        return $ts === false || (time() - $ts) < $maxAgeHours * 3600;
    }));
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

/** Effective refresh interval (seconds) for a poller, honoring the admin override. */
function poller_interval(string $name, int $default): int {
    $v = (int) setting("poll_interval_$name", (string)$default);
    return $v >= 30 ? $v : $default;
}
