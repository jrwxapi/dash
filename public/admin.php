<?php
/**
 * Admin / settings page — replaces the original's admin portal + Infisical vault.
 * Display name, weather location, ESPP position, manual holdings, AI settings.
 * Saving a section clears the matching poller's schedule stamp so cron refreshes
 * the data within a minute.
 */
require_once __DIR__ . '/../includes/db.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'general':
            set_setting('display_name', trim($_POST['display_name'] ?? 'Operator'));
            $tz = trim($_POST['timezone'] ?? 'America/New_York');
            if (in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                set_setting('timezone', $tz);
                $msg = 'General settings saved.';
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
            break;

        case 'espp':
            set_setting('espp_ticker',     strtoupper(trim($_POST['espp_ticker'] ?? '')));
            set_setting('espp_name',       trim($_POST['espp_name'] ?? 'ESPP Holding'));
            set_setting('espp_shares',     (string)(float)($_POST['espp_shares'] ?? 0));
            set_setting('espp_cost_basis', (string)(float)($_POST['espp_cost_basis'] ?? 0));
            if (trim($_POST['espp_ticker'] ?? '') === '') kv_del('espp:holdings');
            kv_del('poll:last:quotes');
            $msg = 'ESPP position saved — refreshing within a minute.';
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
            } else {
                $msg = 'Invalid ticker.';
            }
            break;

        case 'holding_delete':
            db()->prepare('DELETE FROM holdings WHERE ticker = :t')
                ->execute([':t' => strtoupper(trim($_POST['ticker'] ?? ''))]);
            kv_del('poll:last:quotes');
            $msg = 'Holding removed.';
            break;

        case 'ai':
            set_setting('ollama_host',       rtrim(trim($_POST['ollama_host'] ?? ''), '/'));
            set_setting('ollama_model',      trim($_POST['ollama_model'] ?? 'phi3:mini'));
            set_setting('ollama_json_model', trim($_POST['ollama_json_model'] ?? 'gemma2:2b'));
            $msg = 'AI settings saved.';
            break;

        case 'ai_regen':
            foreach (['ai:briefing', 'ai:global', 'ai:cyber', 'ai:portfolio'] as $k) kv_del($k);
            kv_del('poll:last:ai');
            $msg = 'AI digests cleared — regenerating on the next cron run (if Ollama is up).';
            break;
    }
}

$holdings = holdings_rows();
$pollers = [];
foreach (array_keys(POLL_INTERVALS) as $name) {
    $pollers[$name] = kv_get("poll:last:$name");
}
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
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
  section { background:var(--panel); border:1px solid var(--border); border-radius:8px; padding:18px; margin-bottom:18px; }
  label { display:block; font-size:12px; color:var(--tx-2); margin:10px 0 3px; }
  input, select { background:#0c1118; border:1px solid var(--border); color:var(--tx); padding:7px 10px; border-radius:5px; width:100%; font-size:14px; }
  input:focus, select:focus { outline:none; border-color:var(--acc); }
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
<body>
<h1>Dashboard Admin</h1>
<p class="sub"><a href="./">&larr; back to dashboard</a></p>

<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; ?>

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

<section>
  <h2>AI (optional — requires Ollama)</h2>
  <form method="post">
    <input type="hidden" name="action" value="ai">
    <label>Ollama host</label>
    <input name="ollama_host" value="<?= e(setting('ollama_host')) ?>">
    <div class="row">
      <div><label>Briefing model</label><input name="ollama_model" value="<?= e(setting('ollama_model')) ?>"></div>
      <div><label>JSON digest model</label><input name="ollama_json_model" value="<?= e(setting('ollama_json_model')) ?>"></div>
    </div>
    <button>Save</button>
  </form>
  <form method="post">
    <input type="hidden" name="action" value="ai_regen">
    <button>Force AI regenerate</button>
  </form>
  <p class="hint">AI cards stay in their offline state until Ollama is installed and the models
  are pulled (<code>ollama pull phi3:mini &amp;&amp; ollama pull gemma2:2b</code>).</p>
</section>

</body>
</html>
