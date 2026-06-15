<?php
/**
 * AI poller — optional Ollama digests (port of api/services/{ollama,digests}.py).
 * If Ollama isn't running this is a fast no-op and the dashboard's AI cards
 * show their offline state. Install Ollama and `ollama pull phi3:mini gemma2:2b`
 * to light them up; host/models are configurable in the settings table.
 * Writes kv keys: ai:briefing, ai:global, ai:cyber, ai:portfolio
 */

// Default system prompts live in config.php (AI_SYSTEM_PROMPTS) so the admin page
// can show/edit them too. Returns the admin override when set, else the default.
function ai_prompt(string $settingKey, string $promptKey): string {
    return setting_or($settingKey, AI_SYSTEM_PROMPTS[$promptKey]);
}

function ollama_chat(string $system, string $user, string $model, bool $json_mode, int $timeout = 120): ?string {
    $payload = [
        'model'      => $model,
        'messages'   => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'stream'     => false,
        'keep_alive' => '30m',
        'options'    => [
            'temperature' => (float) setting_or('ai_temperature', (string)AI_TEMPERATURE),
            'num_predict' => (int)   setting_or('ai_num_predict', (string)AI_NUM_PREDICT),
        ],
    ];
    if ($json_mode) $payload['format'] = 'json';
    $resp = http_post_json(setting('ollama_host') . '/api/chat', $payload, $timeout);
    return $resp['message']['content'] ?? null;
}

function ai_strip_code_fences(string $text): string {
    $text = trim($text);
    if (str_starts_with($text, '```')) {
        $nl = strpos($text, "\n");
        $text = $nl !== false ? substr($text, $nl + 1) : '';
    }
    if (str_ends_with($text, '```')) {
        $text = substr($text, 0, strrpos($text, '```'));
    }
    return trim($text);
}

// Small models occasionally narrate their instructions or cite snapshot field
// names as if they were sources — strip those sentences (port of sanitize_briefing).
function ai_sanitize_briefing(string $text, int $max_sentences = 4): string {
    $text = preg_replace('/\s*[(\[](?:note|n\.b\.|meta|remark)\b[^)\]]*[)\]]/i', ' ', $text);
    $sentences = preg_split('/(?<=[.!?])\s+/', $text);
    $kept = [];
    foreach ($sentences as $s) {
        if ($s === '') continue;
        if (preg_match('/watch_headlines|top_winner|top_loser|portfolio_summary|total_equity|as instructed|json context|structured json|these instructions/i', $s)) continue;
        $kept[] = $s;
        if (count($kept) >= $max_sentences) break;
    }
    return trim(preg_replace('/\s{2,}/', ' ', implode(' ', $kept)));
}

function ai_is_fresh(?array $cached): bool {
    if (!$cached || empty($cached['generated_at'])) return false;
    $ts = strtotime($cached['generated_at']);
    // Admin override is in minutes; default falls back to AI_DIGEST_MIN_INTERVAL.
    $minSec = max(0, (int) setting_or('ai_min_interval_min',
        (string) intdiv(AI_DIGEST_MIN_INTERVAL, 60))) * 60;
    return $ts !== false && (time() - $ts) < $minSec;
}

function ai_finalize(string $key, array $result, string $model, int $ttl): void {
    $result['model'] = $model;
    $result['generated_at'] = iso_now();
    kv_set($key, $result, $ttl);
}

function ai_headlines(?array $arts, int $n): array {
    return array_values(array_filter(array_map(
        fn($a) => $a['title'] ?? '', array_slice($arts ?? [], 0, $n))));
}

function poll_ai(): string {
    // Quick reachability probe so cron isn't stuck when Ollama is off
    if (http_get(setting('ollama_host') . '/api/tags', 3) === null) {
        return 'Ollama offline — skipped (install Ollama to enable AI cards)';
    }

    $model     = setting('ollama_model', 'phi3:mini');
    $jsonModel = setting('ollama_json_model', 'gemma2:2b');
    $done = [];

    $newsGlobal = kv_get('news:global');
    $newsCyber  = kv_get('news:cyber');
    $holdings   = kv_get('portfolio:holdings');
    $summary    = kv_get('portfolio:summary');

    // ── Global digest ───────────────────────────────────────────────────────
    if ($newsGlobal && !ai_is_fresh(kv_get('ai:global'))) {
        $slim = array_map(fn($a) => ['title' => $a['title'], 'source' => $a['source']],
                          array_slice($newsGlobal, 0, 10));
        $raw = ollama_chat(ai_prompt('ai_prompt_global', 'global_digest'),
                           json_encode(['articles' => $slim]), $jsonModel, true);
        $parsed = $raw !== null ? json_decode(ai_strip_code_fences($raw), true) : null;
        if (is_array($parsed)) {
            ai_finalize('ai:global',
                ['digest' => $parsed['digest'] ?? $parsed['summary'] ?? ''], $jsonModel, 7200);
            $done[] = 'global';
        }
    }

    // ── Cyber digest ────────────────────────────────────────────────────────
    if ($newsCyber && !ai_is_fresh(kv_get('ai:cyber'))) {
        $slim = array_map(fn($a) => ['title' => $a['title'], 'source' => $a['source']],
                          array_slice($newsCyber, 0, 10));
        $raw = ollama_chat(ai_prompt('ai_prompt_cyber', 'cyber_digest'),
                           json_encode(['articles' => $slim]), $jsonModel, true);
        $parsed = $raw !== null ? json_decode(ai_strip_code_fences($raw), true) : null;
        if (is_array($parsed)) {
            ai_finalize('ai:cyber', [
                'digest' => $parsed['digest'] ?? $parsed['summary'] ?? $parsed['overview'] ?? '',
                'items'  => $parsed['items'] ?? $parsed['articles'] ?? [],
            ], $jsonModel, 7200);
            $done[] = 'cyber';
        }
    }

    // ── Portfolio analysis ──────────────────────────────────────────────────
    if ($holdings && !ai_is_fresh(kv_get('ai:portfolio'))) {
        $slim = array_map(fn($h) => [
            'ticker'           => $h['ticker'],
            'name'             => $h['name'],
            'day_pct'          => $h['percent_change_today'],
            'total_return_pct' => $h['total_return_percent'],
            'equity'           => $h['equity'],
        ], $holdings);
        $context = json_encode([
            // Give the model the exact totals so it never has to sum per-holding
            // equity itself (small models hallucinate the grand total otherwise).
            'total_equity'          => $summary['total_equity'] ?? null,
            'daily_change_percent'  => $summary['daily_change_percent'] ?? null,
            'daily_change_dollar'   => $summary['daily_change_dollar'] ?? null,
            'holdings'              => $slim,
            'top_movers'            => array_slice(kv_get('portfolio:movers') ?? [], 0, 5),
            'finance_and_macro_news'=> ai_headlines(array_values(array_filter(
                $newsGlobal ?? [], fn($a) => in_array('finance', $a['tags'] ?? [], true))), 10),
        ]);
        $raw = ollama_chat(ai_prompt('ai_prompt_portfolio', 'portfolio_analyst'),
                           "Portfolio data:\n$context", $model, false);
        if ($raw !== null && trim($raw) !== '') {
            ai_finalize('ai:portfolio',
                ['signals' => [], 'top_risk' => '', 'summary' => ai_strip_code_fences(trim($raw))],
                $model, 7200);
            $done[] = 'portfolio';
        }
    }

    // ── Morning briefing ────────────────────────────────────────────────────
    if (($summary || $newsGlobal) && !ai_is_fresh(kv_get('ai:briefing'))) {
        $byDay = array_values(array_filter($holdings ?? [],
            fn($h) => isset($h['percent_change_today'])));
        usort($byDay, fn($a, $b) => $a['percent_change_today'] <=> $b['percent_change_today']);
        $pick = fn($h) => $h ? array_intersect_key($h, array_flip(
            ['ticker', 'name', 'percent_change_today', 'dollar_change_today', 'equity'])) : null;

        $wx = (kv_get('weather:current') ?? [])['current'] ?? [];
        $snapshot = [
            'total_equity'        => $summary['total_equity'] ?? null,
            'daily_change_pct'    => $summary['daily_change_percent'] ?? null,
            'daily_change_dollar' => $summary['daily_change_dollar'] ?? null,
            'top_winner'          => $pick($byDay ? end($byDay) : null),
            'top_loser'           => $pick($byDay[0] ?? null),
            'watch_headlines'     => array_merge(ai_headlines($newsGlobal, 4),
                                                 ai_headlines($newsCyber, 2)),
            'weather'             => $wx ? [
                'condition' => $wx['condition'] ?? null,
                'temp_f'    => $wx['temperature_f'] ?? null,
                'location'  => $wx['location'] ?? null,
            ] : [],
        ];
        $raw = ollama_chat(ai_prompt('ai_prompt_briefing', 'daily_briefing'),
            "Generate my morning briefing from this context:\n" . json_encode($snapshot),
            $model, false);
        if ($raw !== null) {
            $text = ai_sanitize_briefing($raw);
            if ($text !== '') {
                ai_finalize('ai:briefing', ['briefing' => $text], $model, 7200);
                $done[] = 'briefing';
            }
        }
    }

    return $done ? 'AI digests generated: ' . implode(', ', $done)
                 : 'AI digests fresh — nothing to do';
}
