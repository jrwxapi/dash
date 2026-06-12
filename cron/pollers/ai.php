<?php
/**
 * AI poller — optional Ollama digests (port of api/services/{ollama,digests}.py).
 * If Ollama isn't running this is a fast no-op and the dashboard's AI cards
 * show their offline state. Install Ollama and `ollama pull phi3:mini gemma2:2b`
 * to light them up; host/models are configurable in the settings table.
 * Writes kv keys: ai:briefing, ai:global, ai:cyber, ai:portfolio
 */

const AI_SYSTEM_PROMPTS = [
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
];

function ollama_chat(string $system, string $user, string $model, bool $json_mode, int $timeout = 120): ?string {
    $payload = [
        'model'      => $model,
        'messages'   => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'stream'     => false,
        'keep_alive' => '30m',
        'options'    => ['temperature' => 0.15, 'num_predict' => 1024],
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
    return $ts !== false && (time() - $ts) < AI_DIGEST_MIN_INTERVAL;
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
        $raw = ollama_chat(AI_SYSTEM_PROMPTS['global_digest'],
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
        $raw = ollama_chat(AI_SYSTEM_PROMPTS['cyber_digest'],
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
            'holdings'              => $slim,
            'top_movers'            => array_slice(kv_get('portfolio:movers') ?? [], 0, 5),
            'finance_and_macro_news'=> ai_headlines(array_values(array_filter(
                $newsGlobal ?? [], fn($a) => in_array('finance', $a['tags'] ?? [], true))), 10),
        ]);
        $raw = ollama_chat(AI_SYSTEM_PROMPTS['portfolio_analyst'],
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
        $raw = ollama_chat(AI_SYSTEM_PROMPTS['daily_briefing'],
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
