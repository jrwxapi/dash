<?php
/**
 * Quotes poller — replaces both the Robinhood and yfinance/ESPP workers.
 * Positions come from the holdings table + ESPP settings (admin.php); prices
 * come from Yahoo Finance's public chart endpoint (browser UA required or it
 * 429s — same gotcha as the original).
 * Writes kv keys: portfolio:summary, portfolio:holdings, portfolio:movers,
 *                 portfolio:history, espp:holdings
 */

/** Fetch current price / previous close / name for one ticker. */
function yahoo_quote(string $ticker): ?array {
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
         . rawurlencode($ticker) . '?range=1d&interval=1d';
    $data = http_get_json($url, 12);
    $meta = $data['chart']['result'][0]['meta'] ?? null;
    if (!$meta || empty($meta['regularMarketPrice'])) return null;
    $prev = $meta['chartPreviousClose'] ?? $meta['previousClose'] ?? null;
    return [
        'price'      => (float)$meta['regularMarketPrice'],
        'prev_close' => $prev !== null ? (float)$prev : null,
        'name'       => $meta['shortName'] ?? $meta['longName'] ?? $ticker,
    ];
}

function poll_quotes(): string {
    $rows = holdings_rows();

    // Previous payload lets us keep last-known rows when Yahoo hiccups
    $prevHoldings = [];
    foreach (kv_get('portfolio:holdings') ?? [] as $h) {
        $prevHoldings[$h['ticker']] = $h;
    }

    $now = iso_now();
    $holdings = [];
    $fetched = 0;
    foreach ($rows as $row) {
        $ticker = strtoupper($row['ticker']);
        $qty = (float)$row['quantity'];
        $avg = (float)$row['avg_cost'];

        $q = yahoo_quote($ticker);
        usleep(250000);   // be polite to Yahoo
        if ($q === null) {
            if (isset($prevHoldings[$ticker])) $holdings[] = $prevHoldings[$ticker];
            continue;
        }
        $fetched++;

        $price  = $q['price'];
        $prev   = $q['prev_close'] ?: $price;
        $equity = $price * $qty;
        $cost   = $avg * $qty;
        $holdings[] = [
            'ticker'               => $ticker,
            'name'                 => $row['name'] !== '' ? $row['name'] : $q['name'],
            'quantity'             => $qty,
            'average_buy_price'    => $avg,
            'current_price'        => round($price, 2),
            'equity'               => round($equity, 2),
            'percent_change_today' => $prev ? round(($price - $prev) / $prev * 100, 2) : 0.0,
            'dollar_change_today'  => round(($price - $prev) * $qty, 2),
            'total_return_dollar'  => round($equity - $cost, 2),
            'total_return_percent' => $cost > 0 ? round(($equity - $cost) / $cost * 100, 2) : 0.0,
            'updated_at'           => $now,
        ];
    }

    $ttl = POLL_INTERVALS['quotes'] * 10;

    if ($holdings) {
        usort($holdings, fn($a, $b) => $b['equity'] <=> $a['equity']);

        $equityTotal = array_sum(array_column($holdings, 'equity'));
        $dayTotal    = array_sum(array_column($holdings, 'dollar_change_today'));
        $retTotal    = array_sum(array_column($holdings, 'total_return_dollar'));
        $costTotal   = $equityTotal - $retTotal;
        $prevEquity  = $equityTotal - $dayTotal;

        $summary = [
            'total_equity'         => round($equityTotal, 2),
            'daily_change_dollar'  => round($dayTotal, 2),
            'daily_change_percent' => $prevEquity > 0 ? round($dayTotal / $prevEquity * 100, 2) : 0.0,
            'total_return_dollar'  => round($retTotal, 2),
            'total_return_percent' => $costTotal > 0 ? round($retTotal / $costTotal * 100, 2) : 0.0,
            'updated_at'           => $now,
        ];

        $movers = array_map(fn($h) => [
            'ticker'         => $h['ticker'],
            'name'           => $h['name'],
            'percent_change' => $h['percent_change_today'],
            'dollar_change'  => $h['dollar_change_today'],
            'direction'      => $h['percent_change_today'] >= 0 ? 'up' : 'down',
        ], $holdings);
        usort($movers, fn($a, $b) => abs($b['percent_change']) <=> abs($a['percent_change']));

        kv_set('portfolio:holdings', $holdings, $ttl);
        kv_set('portfolio:summary',  $summary,  $ttl);
        kv_set('portfolio:movers',   $movers,   $ttl);

        // Record account value for the 5-day sparkline
        db()->prepare('INSERT INTO equity_history (equity) VALUES (:e)')
            ->execute([':e' => round($equityTotal, 2)]);
        db()->exec("DELETE FROM equity_history WHERE recorded_at < now() - interval '6 days'");

        $hist = db()->query(
            "SELECT equity, recorded_at FROM equity_history
             WHERE recorded_at > now() - interval '5 days'
             ORDER BY recorded_at"
        )->fetchAll();
        $points = array_map(fn($r) => [
            'adjusted_close_equity' => (float)$r['equity'],
            'begins_at'             => gmdate('c', strtotime($r['recorded_at'])),
        ], $hist);
        kv_set('portfolio:history', ['span' => 'week', 'equity_historicals' => $points], $ttl);
    }

    // ── ESPP ────────────────────────────────────────────────────────────────
    $esppTicker = strtoupper(trim(setting('espp_ticker')));
    $esppNote = '';
    if ($esppTicker !== '') {
        $shares = (float)setting('espp_shares', '0');
        $basis  = (float)setting('espp_cost_basis', '0');
        $q = yahoo_quote($esppTicker);
        $prevEspp = kv_get('espp:holdings');

        if ($q !== null || ($prevEspp && $prevEspp['ticker'] === $esppTicker)) {
            $isLive = $q !== null;
            $price  = $isLive ? $q['price'] : (float)$prevEspp['current_price'];
            $value  = $price * $shares;
            $cost   = $basis * $shares;
            kv_set('espp:holdings', [
                'ticker'               => $esppTicker,
                'name'                 => setting('espp_name', 'ESPP Holding'),
                'shares'               => $shares,
                'cost_basis'           => $basis,
                'current_price'        => round($price, 2),
                'current_value'        => round($value, 2),
                'total_return_dollar'  => round($value - $cost, 2),
                'total_return_percent' => $cost > 0 ? round(($value - $cost) / $cost * 100, 2) : 0.0,
                'price_live'           => $isLive,
                'price_note'           => $isLive ? null
                    : 'Price unavailable — ticker may be delisted. Showing last known price.',
                'updated_at'           => $now,
            ], $isLive ? $ttl : 86400);
            $esppNote = ", ESPP $esppTicker @ " . round($price, 2) . ($isLive ? '' : ' [STALE]');
        }
    }

    // ── Ticker tape extras ───────────────────────────────────────────────────
    // Extra symbols configured on the admin page to scroll in the ticker tape
    // alongside holdings. Quote-only (symbol / price / day change) — these are
    // NOT part of the portfolio totals. Symbols already held are skipped so the
    // tape doesn't show them twice.
    $held = array_map(fn($h) => strtoupper($h['ticker']), $rows);
    $extraSyms = preg_split('/[\s,]+/', strtoupper(trim(setting('ticker_extra_symbols', ''))), -1, PREG_SPLIT_NO_EMPTY);
    $extra = [];
    $seen = [];
    foreach ($extraSyms as $sym) {
        if (!preg_match('/^[A-Z0-9.\^\-=]{1,12}$/', $sym)) continue;   // allow ^GSPC, BTC-USD, EURUSD=X
        if (in_array($sym, $held, true) || isset($seen[$sym])) continue;
        $seen[$sym] = true;
        $q = yahoo_quote($sym);
        usleep(250000);
        if ($q === null) continue;
        $price = $q['price'];
        $prev  = $q['prev_close'] ?: $price;
        $extra[] = [
            'ticker'               => $sym,
            'name'                 => $q['name'],
            'current_price'        => round($price, 2),
            'percent_change_today' => $prev ? round(($price - $prev) / $prev * 100, 2) : 0.0,
        ];
    }
    kv_set('ticker:extra', $extra, $ttl);

    if (!$rows && $esppTicker === '') {
        return $extra
            ? 'Ticker extras updated: ' . count($extra) . ' (no holdings configured)'
            : 'No holdings configured — add positions in admin.php';
    }
    return "Quotes updated: $fetched/" . count($rows) . " holdings"
         . ($extra ? ', ' . count($extra) . ' ticker extras' : '') . $esppNote;
}
