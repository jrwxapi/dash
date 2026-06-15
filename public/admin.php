<?php
/**
 * Admin / settings page — replaces the original's admin portal + Infisical vault.
 * Display name, weather location, ESPP position, manual holdings, AI settings.
 * Saving a section clears the matching poller's schedule stamp so cron refreshes
 * the data within a minute.
 */
require_once __DIR__ . '/../includes/db.php';

// "only" mode renders just the named section(s) — used by the dashboard's inline
// config modals (e.g. admin.php?only=weather, ?only=portfolio,ticker). Without
// it, the full admin page renders as before.
$only  = isset($_GET['only']) && $_GET['only'] !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $_GET['only']))))
    : null;
$embed = $only !== null;
function show_sec(string $key): bool {
    global $only;
    return $only === null || in_array($key, $only, true);
}

$msg   = '';
$saved = false;   // a settings save succeeded → the modal may close
$dirty = false;   // holdings/digests changed → keep modal open, refresh dashboard on close

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'general':
            set_setting('display_name', trim($_POST['display_name'] ?? 'Operator'));
            $tz = trim($_POST['timezone'] ?? 'America/New_York');
            if (in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                set_setting('timezone', $tz);
                $msg = 'General settings saved.';
                $saved = true;
            } else {
                $msg = 'Invalid time zone — display name saved, time zone unchanged.';
            }
            break;

        case 'weather':
            set_setting('weather_lat',      trim($_POST['weather_lat'] ?? ''));
            set_setting('weather_lon',      trim($_POST['weather_lon'] ?? ''));
            set_setting('weather_location', trim($_POST['weather_location'] ?? ''));
            kv_del('poll:last:weather');
            $msg = 'Weather location saved — refreshing within a minute.';
            $saved = true;
            break;

        case 'espp':
            set_setting('espp_ticker',     strtoupper(trim($_POST['espp_ticker'] ?? '')));
            set_setting('espp_name',       trim($_POST['espp_name'] ?? 'ESPP Holding'));
            set_setting('espp_shares',     (string)(float)($_POST['espp_shares'] ?? 0));
            set_setting('espp_cost_basis', (string)(float)($_POST['espp_cost_basis'] ?? 0));
            if (trim($_POST['espp_ticker'] ?? '') === '') kv_del('espp:holdings');
            kv_del('poll:last:quotes');
            $msg = 'ESPP position saved — refreshing within a minute.';
            $saved = true;
            break;

        case 'ticker':
            set_setting('ticker_speed', (string) max(5, min(400, (int)($_POST['ticker_speed'] ?? TICKER_SPEED))));
            // Normalize the extra symbols: uppercase, validated, de-duplicated.
            $syms = preg_split('/[\s,]+/', strtoupper(trim($_POST['ticker_extra_symbols'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $syms = array_values(array_unique(array_filter($syms,
                fn($s) => (bool)preg_match('/^[A-Z0-9.\^\-=]{1,12}$/', $s))));
            set_setting('ticker_extra_symbols', implode(', ', $syms));
            kv_del('poll:last:quotes');   // refetch quotes (incl. new symbols) next tick
            $msg = 'Ticker tape settings saved — refreshing within a minute.';
            $saved = true;
            break;

        case 'holding_add':
            $ticker = strtoupper(trim($_POST['ticker'] ?? ''));
            if ($ticker !== '' && preg_match('/^[A-Z0-9.\-]{1,10}$/', $ticker)) {
                db()->prepare(
                    'INSERT INTO holdings (ticker, name, quantity, avg_cost)
                     VALUES (:t, :n, :q, :c)
                     ON CONFLICT (ticker) DO UPDATE
                     SET name = EXCLUDED.name, quantity = EXCLUDED.quantity, avg_cost = EXCLUDED.avg_cost'
                )->execute([
                    ':t' => $ticker,
                    ':n' => trim($_POST['name'] ?? ''),
                    ':q' => (float)($_POST['quantity'] ?? 0),
                    ':c' => (float)($_POST['avg_cost'] ?? 0),
                ]);
                kv_del('poll:last:quotes');
                $msg = "Holding $ticker saved — quotes refresh within a minute.";
                $dirty = true;
            } else {
                $msg = 'Invalid ticker.';
            }
            break;

        case 'holding_delete':
            db()->prepare('DELETE FROM holdings WHERE ticker = :t')
                ->execute([':t' => strtoupper(trim($_POST['ticker'] ?? ''))]);
            kv_del('poll:last:quotes');
            $msg = 'Holding removed.';
            $dirty = true;
            break;

        case 'feed_global':
        case 'feed_cyber':
            $f = $action === 'feed_global' ? 'global' : 'cyber';
            set_setting("feed_{$f}_sources",      trim($_POST['sources'] ?? ''));
            set_setting("poll_interval_$f",       (string) max(30, (int)($_POST['interval'] ?? 1800)));
            set_setting("feed_{$f}_max",          (string) max(1,  (int)($_POST['max_items'] ?? 60)));
            set_setting("feed_{$f}_max_age_h",    (string) max(1,  (int)($_POST['max_age_h'] ?? 48)));
            set_setting("feed_{$f}_highlight_min",(string) max(0,  (int)($_POST['highlight_min'] ?? 30)));
            kv_del("poll:last:$f");   // force a refresh on the next cron tick
            $msg = ucfirst($f) . ' feed settings saved — refreshing within a minute.';
            $saved = true;
            break;

        case 'feed_truth':
            $src = trim($_POST['source'] ?? '');
            if ($src !== '' && !filter_var($src, FILTER_VALIDATE_URL)) {
                $msg = 'Invalid Truth source URL — settings not saved.';
                break;
            }
            set_setting('feed_truth_source',       $src);
            set_setting('poll_interval_truth',     (string) max(30, (int)($_POST['interval'] ?? 600)));
            set_setting('feed_truth_max',          (string) max(1,  (int)($_POST['max_items'] ?? 20)));
            set_setting('feed_truth_max_age_h',    (string) max(1,  (int)($_POST['max_age_h'] ?? 24)));
            set_setting('feed_truth_highlight_min',(string) max(0,  (int)($_POST['highlight_min'] ?? 30)));
            kv_del('poll:last:truth');
            $msg = 'POTUS feed settings saved — refreshing within a minute.';
            $saved = true;
            break;

        case 'ai':
            set_setting('ollama_host',       rtrim(trim($_POST['ollama_host'] ?? ''), '/'));
            set_setting('ollama_model',      trim($_POST['ollama_model'] ?? 'phi3:mini'));
            set_setting('ollama_json_model', trim($_POST['ollama_json_model'] ?? 'gemma2:2b'));
            // Model tuning
            set_setting('ai_temperature',      (string) max(0.0, min(2.0, (float)($_POST['ai_temperature'] ?? AI_TEMPERATURE))));
            set_setting('ai_num_predict',      (string) max(64, (int)($_POST['ai_num_predict'] ?? AI_NUM_PREDICT)));
            set_setting('ai_min_interval_min', (string) max(0, (int)($_POST['ai_min_interval_min'] ?? intdiv(AI_DIGEST_MIN_INTERVAL, 60))));
            // Per-card prompts — store blank (= follow the built-in default) when
            // left empty or unchanged from the default, so edits to the default
            // still flow through unless the user actually customized the prompt.
            $promptDefaults = [
                'ai_prompt_briefing'  => AI_SYSTEM_PROMPTS['daily_briefing'],
                'ai_prompt_portfolio' => AI_SYSTEM_PROMPTS['portfolio_analyst'],
                'ai_prompt_global'    => AI_SYSTEM_PROMPTS['global_digest'],
                'ai_prompt_cyber'     => AI_SYSTEM_PROMPTS['cyber_digest'],
            ];
            foreach ($promptDefaults as $pk => $pdef) {
                $val = trim($_POST[$pk] ?? '');
                set_setting($pk, ($val === '' || $val === trim($pdef)) ? '' : $val);
            }
            // Clear cached digests so the new settings take effect on the next run.
            foreach (['ai:briefing', 'ai:global', 'ai:cyber', 'ai:portfolio'] as $k) kv_del($k);
            kv_del('poll:last:ai');
            $msg = 'AI settings saved — digests regenerate on the next run (if Ollama is up).';
            $saved = true;
            break;

        case 'ai_regen':
            foreach (['ai:briefing', 'ai:global', 'ai:cyber', 'ai:portfolio'] as $k) kv_del($k);
            kv_del('poll:last:ai');
            $msg = 'AI digests cleared — regenerating on the next cron run (if Ollama is up).';
            $dirty = true;
            break;
    }
}

$holdings = holdings_rows();
$pollers = [];
foreach (array_keys(POLL_INTERVALS) as $name) {
    $pollers[$name] = kv_get("poll:last:$name");
}
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

// Render a default feed list ([[name,url],...]) as editable "Name | URL" lines.
function feeds_to_text(array $feeds): string {
    return implode("\n", array_map(fn($f) => $f[0] . ' | ' . $f[1], $feeds));
}
function ago(?int $ts): string {
    if (!$ts) return 'never';
    $d = time() - $ts;
    if ($d < 60) return $d . 's ago';
    if ($d < 3600) return intdiv($d, 60) . 'm ago';
    return intdiv($d, 3600) . 'h ' . intdiv($d % 3600, 60) . 'm ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Admin</title>
<style>
  :root { --bg:#0a0e14; --panel:#10161f; --border:#1d2735; --tx:#d7e0ea; --tx-2:#8b99a8;
          --acc:#4aa8ff; --gain:#27c281; --loss:#f2545b; }
  * { box-sizing:border-box; margin:0; }
  body { background:var(--bg); color:var(--tx); font:14px/1.5 system-ui, sans-serif; padding:28px; max-width:880px; margin:0 auto; }
  h1 { font-size:20px; margin-bottom:4px; }
  h2 { font-size:13px; text-transform:uppercase; letter-spacing:1px; color:var(--tx-2); margin-bottom:12px; }
  a { color:var(--acc); text-decoration:none; }
  .sub { color:var(--tx-2); margin-bottom:24px; font-size:13px; }
  .msg { background:#13301f; border:1px solid var(--gain); color:var(--gain); padding:10px 14px; border-radius:6px; margin-bottom:20px; }
  .msg.err { background:#301316; border-color:var(--loss); color:var(--loss); }
  /* Embedded (?only=) mode: rendered inside the dashboard's config modal iframe —
     drop the page chrome so the section sits flush in the modal body. */
  body.embed { padding:14px; max-width:none; }
  body.embed section { margin-bottom:0; }
  body.embed section + section { margin-top:14px; }
  section { background:var(--panel); border:1px solid var(--border); border-radius:8px; padding:18px; margin-bottom:18px; }
  label { display:block; font-size:12px; color:var(--tx-2); margin:10px 0 3px; }
  input, select, textarea { background:#0c1118; border:1px solid var(--border); color:var(--tx); padding:7px 10px; border-radius:5px; width:100%; font-size:14px; }
  input:focus, select:focus, textarea:focus { outline:none; border-color:var(--acc); }
  textarea { font:13px/1.5 ui-monospace, 'SFMono-Regular', Menlo, monospace; resize:vertical; min-height:130px; }
  .row { display:flex; gap:12px; } .row > div { flex:1; }
  button { background:var(--acc); color:#06121f; font-weight:600; border:0; padding:8px 16px; border-radius:5px; cursor:pointer; margin-top:14px; font-size:13px; }
  button.danger { background:transparent; color:var(--loss); border:1px solid var(--loss); padding:4px 10px; margin:0; }
  table { width:100%; border-collapse:collapse; margin-top:8px; font-size:13px; }
  th, td { text-align:left; padding:7px 8px; border-bottom:1px solid var(--border); }
  th { color:var(--tx-2); font-size:11px; text-transform:uppercase; letter-spacing:0.5px; }
  td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; }
  .ok { color:var(--gain); } .err { color:var(--loss); }
  .hint { font-size:12px; color:var(--tx-2); margin-top:6px; }
  form.inline { display:contents; }
</style>
</head>
<body<?= $embed ? ' class="embed"' : '' ?>>
<?php if (!$embed): ?>
<h1>Dashboard Admin</h1>
<p class="sub"><a href="./">&larr; back to dashboard</a></p>
<?php endif; ?>

<?php if ($msg): $msgErr = (stripos($msg, 'invalid') !== false || stripos($msg, 'unchanged') !== false || stripos($msg, 'not saved') !== false); ?>
<div class="msg<?= $msgErr ? ' err' : '' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<?php if (!$embed): ?>
<section>
  <h2>Poller status</h2>
  <table>
    <tr><th>Poller</th><th>Last run</th><th>Status</th></tr>
    <?php foreach ($pollers as $name => $p): ?>
    <tr>
      <td><?= e($name) ?></td>
      <td><?= e(ago($p['ran_at'] ?? null)) ?></td>
      <td class="<?= ($p['ok'] ?? true) ? 'ok' : 'err' ?>"><?= e($p['status'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="hint">Pollers run from cron every minute on their own intervals. If everything says
  "never", check that the crontab entry from setup.sh is installed.</p>
</section>
<?php endif; ?>

<?php if (show_sec('general')): ?>
<section>
  <h2>General</h2>
  <form method="post">
    <input type="hidden" name="action" value="general">
    <label>Display name (dashboard header)</label>
    <input name="display_name" value="<?= e(setting('display_name', 'Operator')) ?>">
    <label>Time zone (header clock &amp; date)</label>
    <select name="timezone">
      <?php $curTz = setting('timezone', 'America/New_York');
      foreach (DateTimeZone::listIdentifiers() as $tzId): ?>
      <option value="<?= e($tzId) ?>"<?= $tzId === $curTz ? ' selected' : '' ?>><?= e($tzId) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="hint">Market open/closed status always follows New York exchange hours.</p>
    <button>Save</button>
  </form>
</section>
<?php endif; ?>

<?php if (show_sec('weather')): ?>
<section>
  <h2>Weather</h2>
  <form method="post">
    <input type="hidden" name="action" value="weather">
    <div class="row">
      <div><label>Latitude</label><input name="weather_lat" value="<?= e(setting('weather_lat')) ?>"></div>
      <div><label>Longitude</label><input name="weather_lon" value="<?= e(setting('weather_lon')) ?>"></div>
    </div>
    <label>Location label</label>
    <input name="weather_location" value="<?= e(setting('weather_location')) ?>">
    <p class="hint">Find coordinates at <a href="https://open-meteo.com/en/docs#geocoding_api" target="_blank">open-meteo.com geocoding</a> or any map service.</p>
    <button>Save</button>
  </form>
</section>
<?php endif; ?>

<?php
// Global + Cyber share the same RSS-list form; render both from one template.
$rssFeeds = [
    'global' => ['Global Feed',     'feed_global', GLOBAL_FEEDS_DEFAULT, GLOBAL_MAX_ITEMS, NEWS_MAX_AGE_HOURS, POLL_INTERVALS['global']],
    'cyber'  => ['Cyber · DIB feed', 'feed_cyber',  CYBER_FEEDS_DEFAULT,  CYBER_MAX_ITEMS,  NEWS_MAX_AGE_HOURS, POLL_INTERVALS['cyber']],
];
foreach ($rssFeeds as $f => [$title, $action, $defFeeds, $defMax, $defAge, $defInt]):
    if (!show_sec($f)) continue;
?>
<section>
  <h2><?= e($title) ?></h2>
  <form method="post">
    <input type="hidden" name="action" value="<?= e($action) ?>">
    <label>Sources — one per line, <code>Name | RSS URL</code></label>
    <textarea name="sources" spellcheck="false"><?= e(setting_or("feed_{$f}_sources", feeds_to_text($defFeeds))) ?></textarea>
    <div class="row">
      <div><label>Refresh interval (seconds)</label>
        <input name="interval" type="number" min="30" step="30" value="<?= e(setting_or("poll_interval_$f", (string)$defInt)) ?>"></div>
      <div><label>Max items kept</label>
        <input name="max_items" type="number" min="1" value="<?= e(setting_or("feed_{$f}_max", (string)$defMax)) ?>"></div>
      <div><label>Max article age (hours)</label>
        <input name="max_age_h" type="number" min="1" value="<?= e(setting_or("feed_{$f}_max_age_h", (string)$defAge)) ?>"></div>
      <div><label>Highlight new for (minutes)</label>
        <input name="highlight_min" type="number" min="0" value="<?= e(setting_or("feed_{$f}_highlight_min", (string)FEED_HIGHLIGHT_MIN)) ?>"></div>
    </div>
    <p class="hint">Newly published items get a highlight that fades after the set minutes (0 disables it).</p>
    <button>Save</button>
  </form>
</section>
<?php endforeach; ?>

<?php if (show_sec('truth')): ?>
<section>
  <h2>POTUS · Truth Social feed</h2>
  <form method="post">
    <input type="hidden" name="action" value="feed_truth">
    <label>Source RSS URL</label>
    <input name="source" value="<?= e(setting_or('feed_truth_source', TRUTH_RSS_DEFAULT)) ?>">
    <div class="row">
      <div><label>Refresh interval (seconds)</label>
        <input name="interval" type="number" min="30" step="30" value="<?= e(setting_or('poll_interval_truth', (string)POLL_INTERVALS['truth'])) ?>"></div>
      <div><label>Max posts kept</label>
        <input name="max_items" type="number" min="1" value="<?= e(setting_or('feed_truth_max', (string)TRUTH_MAX_POSTS)) ?>"></div>
      <div><label>Max post age (hours)</label>
        <input name="max_age_h" type="number" min="1" value="<?= e(setting_or('feed_truth_max_age_h', (string)TRUTH_MAX_AGE_HOURS)) ?>"></div>
      <div><label>Highlight new for (minutes)</label>
        <input name="highlight_min" type="number" min="0" value="<?= e(setting_or('feed_truth_highlight_min', (string)FEED_HIGHLIGHT_MIN)) ?>"></div>
    </div>
    <p class="hint">The trumpstruth.org mirror is the default; Truth Social's own API blocks server-side requests.</p>
    <button>Save</button>
  </form>
</section>
<?php endif; ?>

<?php if (show_sec('portfolio')): ?>
<section>
  <h2>Portfolio holdings</h2>
  <?php if ($holdings): ?>
  <table>
    <tr><th>Ticker</th><th>Name</th><th class="num">Shares</th><th class="num">Avg cost</th><th></th></tr>
    <?php foreach ($holdings as $h): ?>
    <tr>
      <td><?= e($h['ticker']) ?></td>
      <td><?= e($h['name']) ?></td>
      <td class="num"><?= e(rtrim(rtrim(number_format((float)$h['quantity'], 4, '.', ''), '0'), '.')) ?></td>
      <td class="num">$<?= e(number_format((float)$h['avg_cost'], 2)) ?></td>
      <td style="text-align:right">
        <form method="post" class="inline">
          <input type="hidden" name="action" value="holding_delete">
          <input type="hidden" name="ticker" value="<?= e($h['ticker']) ?>">
          <button class="danger">Remove</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="hint">No holdings yet — add positions below and the portfolio panel comes alive
  (prices via Yahoo Finance, no brokerage login needed).</p>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="holding_add">
    <div class="row">
      <div><label>Ticker</label><input name="ticker" placeholder="AAPL" required></div>
      <div><label>Name (optional)</label><input name="name" placeholder="Apple Inc."></div>
      <div><label>Shares</label><input name="quantity" type="number" step="any" min="0" required></div>
      <div><label>Avg cost / share</label><input name="avg_cost" type="number" step="any" min="0" required></div>
    </div>
    <button>Add / update holding</button>
  </form>
</section>
<?php endif; ?>

<?php if (show_sec('espp')): ?>
<section>
  <h2>ESPP position</h2>
  <form method="post">
    <input type="hidden" name="action" value="espp">
    <div class="row">
      <div><label>Ticker (blank to hide panel)</label><input name="espp_ticker" value="<?= e(setting('espp_ticker')) ?>"></div>
      <div><label>Display name</label><input name="espp_name" value="<?= e(setting('espp_name')) ?>"></div>
    </div>
    <div class="row">
      <div><label>Shares</label><input name="espp_shares" type="number" step="any" min="0" value="<?= e(setting('espp_shares')) ?>"></div>
      <div><label>Cost basis / share</label><input name="espp_cost_basis" type="number" step="any" min="0" value="<?= e(setting('espp_cost_basis')) ?>"></div>
    </div>
    <button>Save</button>
  </form>
</section>
<?php endif; ?>

<?php if (show_sec('ticker')): ?>
<section>
  <h2>Ticker tape</h2>
  <form method="post">
    <input type="hidden" name="action" value="ticker">
    <label>Scroll speed (px/sec — higher is faster)</label>
    <input name="ticker_speed" type="number" min="5" max="400" value="<?= e(setting_or('ticker_speed', (string)TICKER_SPEED)) ?>">
    <label>Additional symbols (comma-separated — scroll alongside your holdings)</label>
    <input name="ticker_extra_symbols" value="<?= e(setting('ticker_extra_symbols')) ?>" placeholder="^GSPC, BTC-USD, TSLA">
    <p class="hint">Quotes for these are pulled from Yahoo Finance and scroll in the ticker in addition to your holdings.
      Use Yahoo symbols, e.g. <code>^GSPC</code> (S&amp;P 500), <code>^IXIC</code> (Nasdaq), <code>BTC-USD</code>, <code>TSLA</code>.
      Symbols you already hold are skipped automatically.</p>
    <button>Save</button>
  </form>
</section>
<?php endif; ?>

<?php if (show_sec('ai')): ?>
<section>
  <h2>AI (optional — requires Ollama)</h2>
  <form method="post">
    <input type="hidden" name="action" value="ai">
    <label>Ollama host</label>
    <input name="ollama_host" value="<?= e(setting('ollama_host')) ?>">
    <div class="row">
      <div><label>Briefing model (prose)</label><input name="ollama_model" value="<?= e(setting('ollama_model')) ?>"></div>
      <div><label>JSON digest model</label><input name="ollama_json_model" value="<?= e(setting('ollama_json_model')) ?>"></div>
    </div>
    <div class="row">
      <div><label>Temperature (0–2)</label>
        <input name="ai_temperature" type="number" step="0.05" min="0" max="2" value="<?= e(setting_or('ai_temperature', (string)AI_TEMPERATURE)) ?>"></div>
      <div><label>Max response tokens</label>
        <input name="ai_num_predict" type="number" min="64" step="32" value="<?= e(setting_or('ai_num_predict', (string)AI_NUM_PREDICT)) ?>"></div>
      <div><label>Min regenerate interval (min)</label>
        <input name="ai_min_interval_min" type="number" min="0" value="<?= e(setting_or('ai_min_interval_min', (string)intdiv(AI_DIGEST_MIN_INTERVAL, 60))) ?>"></div>
    </div>

    <label>Briefing prompt — top summary bar (3-sentence portfolio/markets/world)</label>
    <textarea name="ai_prompt_briefing" spellcheck="false"><?= e(setting_or('ai_prompt_briefing', AI_SYSTEM_PROMPTS['daily_briefing'])) ?></textarea>
    <label>Portfolio prompt — Portfolio·AI carousel slide</label>
    <textarea name="ai_prompt_portfolio" spellcheck="false"><?= e(setting_or('ai_prompt_portfolio', AI_SYSTEM_PROMPTS['portfolio_analyst'])) ?></textarea>
    <label>Global digest prompt — Global·AI slide (must return <code>{"digest":"…"}</code> JSON)</label>
    <textarea name="ai_prompt_global" spellcheck="false"><?= e(setting_or('ai_prompt_global', AI_SYSTEM_PROMPTS['global_digest'])) ?></textarea>
    <label>Cyber digest prompt — Cyber·AI slide (must return the <code>{"digest","items"}</code> JSON)</label>
    <textarea name="ai_prompt_cyber" spellcheck="false"><?= e(setting_or('ai_prompt_cyber', AI_SYSTEM_PROMPTS['cyber_digest'])) ?></textarea>
    <p class="hint">Leave a prompt blank to reset it to the built-in default. The digest prompts must keep
      returning their JSON shape or the card can't parse the result.</p>
    <button>Save</button>
  </form>
  <form method="post">
    <input type="hidden" name="action" value="ai_regen">
    <button>Force AI regenerate</button>
  </form>
  <p class="hint">AI cards stay in their offline state until Ollama is installed and the models
  are pulled (<code>ollama pull phi3:mini &amp;&amp; ollama pull gemma2:2b</code>).</p>
</section>
<?php endif; ?>

<?php if ($embed): ?>
<script>
  // Tell the dashboard modal how tall this section is so the iframe fits it.
  function _cfgHeight() {
    parent.postMessage({ type: 'dash-config', action: 'height',
      value: document.documentElement.scrollHeight }, '*');
  }
  window.addEventListener('load', _cfgHeight);
  window.addEventListener('resize', _cfgHeight);
  if (window.ResizeObserver) new ResizeObserver(_cfgHeight).observe(document.body);
  <?php if ($saved): ?>parent.postMessage({ type: 'dash-config', action: 'saved' }, '*');<?php endif; ?>
  <?php if ($dirty): ?>parent.postMessage({ type: 'dash-config', action: 'dirty' }, '*');<?php endif; ?>
</script>
<?php endif; ?>
</body>
</html>
