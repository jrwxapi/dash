<?php
/**
 * POTUS Truth Social poller — port of workers/truth_poller.py.
 * Scrapes the trumpstruth.org RSS mirror (Roll Call's archive). Truth Social's
 * own API is Cloudflare-fronted and blocks server-side TLS fingerprints, so
 * the mirror is the only reliable source — the Python original had the same
 * limitation. Writes kv key: truth:posts
 *
 * Source URL, post cap, max age and refresh interval are admin-configurable
 * (feed_truth_* / poll_interval_truth settings); defaults live in config.php.
 */
require_once __DIR__ . '/_feedlib.php';

function truth_strip_html(string $s): string {
    // Paragraph breaks become double spaces so multi-paragraph posts stay readable
    $s = preg_replace('~</p>\s*<p>~i', '  ', $s);
    return trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5));
}

/**
 * Scrape a trumpstruth.org status page for attachment URLs.
 * Returns [['type' => 'image'|'video', 'url' => ...], ...]
 */
function truth_fetch_media(string $status_url): array {
    $body = http_get($status_url, 15);
    if ($body === null) return [];
    if (!preg_match('/status__attachments(.*?)status__footer/s', $body, $m)) return [];
    $block = $m[1];
    $media = [];
    if (preg_match_all('/<video[^>]+src="([^"]+)"/', $block, $vids)) {
        foreach ($vids[1] as $u) $media[] = ['type' => 'video', 'url' => $u];
    }
    if (preg_match_all('/<img[^>]+src="([^"]+)"/', $block, $imgs)) {
        foreach ($imgs[1] as $u) $media[] = ['type' => 'image', 'url' => $u];
    }
    return $media;
}

function poll_truth(): string {
    $url      = trim(setting('feed_truth_source', '')) ?: TRUTH_RSS_DEFAULT;
    $maxPosts = max(1, (int) setting_or('feed_truth_max',       (string)TRUTH_MAX_POSTS));
    $maxAgeH  = max(1, (int) setting_or('feed_truth_max_age_h', (string)TRUTH_MAX_AGE_HOURS));

    $body = http_get($url, 20);
    if ($body === null) {
        throw new RuntimeException('Truth mirror RSS unreachable: ' . $url);
    }

    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($xml === false || !isset($xml->channel->item)) {
        throw new RuntimeException('could not parse mirror RSS');
    }

    // Media lookups are scraped pages — cache them across polls in kv so each
    // media post is only fetched once.
    $mediaCache = kv_get('truth:media_cache') ?? [];
    if (count($mediaCache) > 200) $mediaCache = [];

    $posts = [];
    $count = 0;
    foreach ($xml->channel->item as $item) {
        if (++$count > $maxPosts) break;
        $text = truth_strip_html((string)$item->title);
        $link = trim((string)$item->link);
        if ($text === '' || $link === '') continue;

        $postId = basename(rtrim($link, '/'));
        $isRetruth = str_starts_with($text, 'RT @');
        $hasMedia = false;
        $images = [];

        if (str_starts_with($text, '[No Title]')) {   // mirror's placeholder for media-only posts
            $hasMedia = true;
            if (!isset($mediaCache[$postId])) {
                $mediaCache[$postId] = truth_fetch_media($link);
            }
            $media = $mediaCache[$postId];
            $images = array_slice(
                array_column(array_filter($media, fn($m) => $m['type'] === 'image'), 'url'), 0, 4);
            if ($images) {
                $text = '';
            } elseif (array_filter($media, fn($m) => $m['type'] === 'video')) {
                $text = '[Video post]';
            } else {
                $text = '[Media post]';
            }
        }

        $ts = strtotime((string)$item->pubDate);
        $posts[] = [
            'id'           => $postId,
            'text'         => mb_substr($text, 0, 600),
            'url'          => $link,
            'published_at' => gmdate('c', $ts !== false ? $ts : time()),
            'is_retruth'   => $isRetruth,
            'has_media'    => $hasMedia,
            'images'       => array_values($images),
        ];
    }

    kv_set('truth:media_cache', $mediaCache, 86400);

    $posts = array_values(array_filter($posts, function ($p) use ($maxAgeH) {
        $ts = strtotime($p['published_at']);
        return $ts === false || (time() - $ts) < $maxAgeH * 3600;
    }));

    if (!$posts) {
        return 'No fresh posts — keeping previous cache';
    }

    // TTL 6× poll interval so posts survive transient mirror outages
    kv_set('truth:posts', $posts, poller_interval('truth', POLL_INTERVALS['truth']) * 6);
    return 'Truth posts updated: ' . count($posts);
}
