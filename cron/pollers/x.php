<?php
/**
 * X / Twitter poller — pulls the latest posts from a configurable list of
 * handles via the official X API v2 and writes kv key: x:posts. The
 * "Interesting People Posts" panel merges these with truth:posts (the Truth
 * Social mirror), so both sources can coexist.
 *
 * Auth: OAuth2 App-Only Bearer Token (admin setting x_bearer_token). Reading
 * other users' timelines requires at least X's paid Basic tier; with no token
 * or no handles configured the poller no-ops cleanly instead of erroring.
 *
 * Handles, post cap, max age and refresh interval are admin-configurable
 * (x_* / poll_interval_x settings); defaults live in config.php.
 */
require_once __DIR__ . '/_feedlib.php';

/** Authenticated X API v2 GET → decoded JSON array, or null on any failure. */
function x_api_get(string $path, string $bearer, array $query = []): ?array {
    $url = X_API_BASE . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $body = http_get($url, 15, [
        'Authorization: Bearer ' . $bearer,
        'Accept: application/json',
    ]);
    if ($body === null) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Is "now" inside the admin-configured active window? The window is given as
 * two "HH:MM" times (x_active_start / x_active_end) interpreted in the
 * dashboard's display timezone, so the API is only hit during the hours the
 * user actually watches the panel (keeps X API usage / cost down). Blank or
 * invalid bounds mean always-active; start > end is treated as an overnight
 * window that wraps past midnight.
 */
function x_within_active_hours(): bool {
    $start = trim(setting('x_active_start', ''));
    $end   = trim(setting('x_active_end', ''));
    if (!preg_match('/^\d{1,2}:\d{2}$/', $start) || !preg_match('/^\d{1,2}:\d{2}$/', $end)) {
        return true;
    }
    $tz = setting('timezone', 'America/New_York');
    try {
        $now = new DateTime('now', new DateTimeZone($tz));
    } catch (Exception $e) {
        $now = new DateTime('now');
    }
    $cur = (int)$now->format('G') * 60 + (int)$now->format('i');
    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    $s = $sh * 60 + $sm;
    $e = $eh * 60 + $em;
    if ($s === $e) return true;                  // equal bounds = always active
    return $s < $e ? ($cur >= $s && $cur < $e)   // same-day window
                   : ($cur >= $s || $cur < $e);  // overnight wrap
}

/** Parse the admin handle list into clean usernames (no @, validated). */
function x_parse_handles(string $raw): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n|,/', $raw) as $line) {
        $h = ltrim(trim($line), '@');
        if ($h === '' || $h[0] === '#') continue;
        if (preg_match('/^[A-Za-z0-9_]{1,15}$/', $h)) $out[strtolower($h)] = $h;
    }
    return array_values($out);
}

/**
 * Resolve handles → user IDs, caching the map in kv so we don't spend the
 * (tightly rate-limited) user-lookup endpoint on every poll. Returns
 * [handle => ['id'=>..., 'username'=>...]]; handles that can't be resolved are
 * simply omitted.
 */
function x_resolve_ids(array $handles, string $bearer): array {
    $cache = kv_get('x:userids') ?? [];
    $resolved = [];
    foreach ($handles as $h) {
        $key = strtolower($h);
        if (isset($cache[$key]['id'])) { $resolved[$h] = $cache[$key]; continue; }
        $r = x_api_get('/users/by/username/' . rawurlencode($h), $bearer);
        if ($r && isset($r['data']['id'])) {
            $cache[$key] = ['id' => $r['data']['id'], 'username' => $r['data']['username'] ?? $h];
            $resolved[$h] = $cache[$key];
        }
    }
    // Cache IDs for 30 days; they rarely change and lookups are expensive.
    kv_set('x:userids', $cache, 30 * 86400);
    return $resolved;
}

/** Pull recent tweets for one user ID → normalized post arrays. */
function x_fetch_timeline(string $userId, string $username, string $bearer, int $count): array {
    $r = x_api_get('/users/' . $userId . '/tweets', $bearer, [
        'max_results'    => max(5, min(100, $count)),
        'tweet.fields'   => 'created_at,referenced_tweets',
        'expansions'     => 'attachments.media_keys',
        'media.fields'   => 'url,preview_image_url,type',
    ]);
    if (!$r || empty($r['data'])) return [];

    // Build media_key → image URL map from the includes block.
    $mediaMap = [];
    foreach ($r['includes']['media'] ?? [] as $m) {
        if (($m['type'] ?? '') === 'photo' && !empty($m['url'])) {
            $mediaMap[$m['media_key']] = $m['url'];
        } elseif (!empty($m['preview_image_url'])) {
            $mediaMap[$m['media_key']] = $m['preview_image_url'];
        }
    }

    $posts = [];
    foreach ($r['data'] as $t) {
        $isRT = false;
        foreach ($t['referenced_tweets'] ?? [] as $ref) {
            if (($ref['type'] ?? '') === 'retweeted') { $isRT = true; break; }
        }
        $images = [];
        foreach ($t['attachments']['media_keys'] ?? [] as $mk) {
            if (isset($mediaMap[$mk])) $images[] = $mediaMap[$mk];
        }
        $ts = isset($t['created_at']) ? strtotime($t['created_at']) : false;
        $posts[] = [
            'id'           => 'x_' . $t['id'],
            'text'         => mb_substr(trim((string)($t['text'] ?? '')), 0, 600),
            'url'          => 'https://x.com/' . $username . '/status/' . $t['id'],
            'published_at' => gmdate('c', $ts !== false ? $ts : time()),
            'is_retruth'   => $isRT,
            'has_media'    => !empty($images),
            'images'       => array_slice($images, 0, 4),
            'author'       => '@' . $username,
            'source'       => 'x',
        ];
    }
    return $posts;
}

function poll_x(): string {
    $bearer  = trim(setting('x_bearer_token', ''));
    $handles = x_parse_handles(setting_or('x_handles', X_HANDLES_DEFAULT));
    if ($bearer === '' || !$handles) {
        return 'X not configured (need bearer token + handles) — skipped';
    }
    if (!x_within_active_hours()) {
        return 'Outside active hours — skipped';
    }

    $maxPosts = max(1, (int) setting_or('x_max',       (string)X_MAX_POSTS));
    $maxAgeH  = max(1, (int) setting_or('x_max_age_h', (string)X_MAX_AGE_HOURS));
    $perHandle = max(5, min(100, (int) setting_or('x_per_handle', (string)X_TWEETS_PER_HANDLE)));

    $resolved = x_resolve_ids($handles, $bearer);
    if (!$resolved) {
        throw new RuntimeException('Could not resolve any X handles (auth/rate-limit?)');
    }

    $posts = [];
    foreach ($resolved as $h => $info) {
        $posts = array_merge($posts, x_fetch_timeline($info['id'], $info['username'], $bearer, $perHandle));
    }

    // Drop stale posts, dedupe by id, newest first.
    $posts = array_values(array_filter($posts, function ($p) use ($maxAgeH) {
        $ts = strtotime($p['published_at']);
        return $ts === false || (time() - $ts) < $maxAgeH * 3600;
    }));
    $seen = [];
    foreach ($posts as $p) { if (!isset($seen[$p['id']])) $seen[$p['id']] = $p; }
    $posts = array_values($seen);
    usort($posts, fn($a, $b) => strcmp($b['published_at'], $a['published_at']));
    $posts = array_slice($posts, 0, $maxPosts);

    if (!$posts) {
        return 'No fresh X posts — keeping previous cache';
    }

    // TTL 6× poll interval so posts survive transient API outages / rate limits.
    kv_set('x:posts', $posts, poller_interval('x', POLL_INTERVALS['x']) * 6);
    return 'X posts updated: ' . count($posts) . ' from ' . count($resolved) . ' handle(s)';
}
