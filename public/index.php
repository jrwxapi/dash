<?php
require_once __DIR__ . '/../includes/db.php';
function feed_hl(string $key): int {
    $v = setting("feed_{$key}_highlight_min", '');
    return $v === '' ? FEED_HIGHLIGHT_MIN : max(0, (int)$v);
}
try {
    $displayName = setting('display_name', 'Operator');
    $weatherLoc  = setting('weather_location', 'New York, NY');
    $timeZone    = setting('timezone', 'America/New_York');
    $feedHl      = ['global' => feed_hl('global'), 'cyber' => feed_hl('cyber'), 'truth' => feed_hl('truth')];
    $tickerSpeed = max(5, min(400, (int) setting_or('ticker_speed', (string)TICKER_SPEED)));
} catch (Throwable $e) {
    // DB not set up yet — page still renders (demo mode)
    $displayName = 'Operator';
    $weatherLoc  = 'New York, NY';
    $timeZone    = 'America/New_York';
    $feedHl      = ['global' => 30, 'cyber' => 30, 'truth' => 30];
    $tickerSpeed = 60;
}
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
// Config kebab (⋮) — opens the inline admin modal for the given section key(s).
function kebab(string $key, string $title): string {
    $t = e($title);
    return '<button class="kebab" type="button" data-cfg="' . e($key) . '" data-cfg-title="' . $t
         . '" title="Configure ' . $t . '" aria-label="Configure ' . $t . '">&#8942;</button>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Personal Intelligence Dashboard</title>
<link rel="stylesheet" href="styles.css?v=5">
</head>
<body>
<div id="stage">
  <div id="canvas">
    <div id="grid-root">

      <!-- ============ HEADER ============ -->
      <header id="header">
        <div class="brand">
          <div class="brand-mark"><span class="ring"></span></div>
          <div>
            <div class="brand-title">Intelligence</div>
            <div class="brand-sub">Personal Command · <span id="brand-name"><?= e($displayName) ?></span></div>
          </div>
          <?= kebab('general', 'General') ?>
        </div>

        <div class="header-spacer"></div>

        <div class="hstat">
          <span class="label">Status</span>
          <span class="val"><span id="mkt-badge" class="mkt-badge mkt-closed"><span class="dot"></span> Market</span></span>
        </div>
        <div class="hstat">
          <span class="label">Date</span>
          <span class="val mono" id="date-val">—</span>
        </div>
        <div class="hstat">
          <span class="label">Feed</span>
          <span class="val"><span id="conn" class="conn"><span class="led"></span> Connecting…</span></span>
        </div>
        <div class="hstat" style="border-left:1px solid var(--border)">
          <span class="label"><span id="header-location"><?= e($weatherLoc) ?></span> · <span id="tz-label">ET</span></span>
          <span class="val mono" id="clock">—</span>
        </div>
      </header>

      <!-- ============ AI SUMMARY BAR ============ -->
      <section id="briefing">

        <div class="ai-card ai-card--briefing">
          <div class="ai-card-hd">
            <span class="ai-dot ai-dot--acc"></span>
            <span class="ai-card-label">Briefing</span>
            <span class="ai-card-sub" id="briefing-meta">ollama · —</span>
            <?= kebab('ai', 'AI') ?>
          </div>
          <div class="ai-card-body" id="briefing-text">Waiting for data…</div>
        </div>

        <div class="ai-card ai-card--weather">
          <div class="ai-card-hd">
            <span class="ai-dot ai-dot--wx"></span>
            <span class="ai-card-label">Weather</span>
            <span class="ai-card-sub" id="ai-sum-wx-loc"><?= e($weatherLoc) ?></span>
          </div>
          <div class="ai-card-body" id="ai-sum-weather">—</div>
        </div>

      </section>

      <!-- ============ MAIN GRID ============ -->
      <div id="main">

        <!-- ---- COL 1: PORTFOLIO ---- -->
        <div class="col">
          <div class="panel" style="flex:1">
            <div class="panel-h">
              <span class="tick gain"></span>
              <span class="tag">Portfolio</span>
              <span class="spacer"></span>
              <span class="hint" id="pf-updated">UPD —</span>
              <?= kebab('portfolio,ticker', 'Portfolio') ?>
            </div>
            <div class="panel-body">
              <div class="pf-summary">
                <div class="pf-equity-row">
                  <div class="pf-equity">
                    <div class="lbl">Total Equity</div>
                    <div class="val mono" id="pf-equity-val">$—</div>
                  </div>
                  <div class="pf-deltas">
                    <div class="pf-delta"><span class="k">Today</span><span class="chip up" id="pf-day-chip">—</span></div>
                    <div class="pf-delta"><span class="k">Lifetime</span><span class="chip up" id="pf-tot-chip">—</span></div>
                  </div>
                </div>
              </div>

              <div class="pf-spark">
                <div class="pf-spark-wrap">
                  <svg id="pf-spark-svg" viewBox="0 0 560 64" preserveAspectRatio="none"></svg>
                </div>
                <div class="axis"><span id="pf-spark-lo">—</span><span>5-DAY ACCOUNT VALUE</span><span id="pf-spark-hi">—</span></div>
              </div>

              <div class="mover-wrap">
                <div class="mover-hero up" id="mover-hero"></div>
                <div class="mover-pips" id="mover-pips"></div>
              </div>

              <div class="holdings">
                <div class="holdings-head">
                  <span>Position</span><span class="r">Equity</span><span class="r">Today</span><span class="r">Lifetime</span>
                </div>
                <div class="holdings-scroll" id="holdings-scroll">
                  <div class="holdings-track" id="holdings-track"></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ---- COL 2: GLOBAL NEWS ---- -->
        <div class="col">
          <!-- AI Carousel: Global · Cyber · Portfolio — rotates every 7s -->
          <div class="ai-card" id="ai-carousel-card" style="flex:1">
            <div class="ai-card-hd">
              <span class="ai-dot" id="ai-carousel-dot"></span>
              <span class="ai-card-label" id="ai-carousel-label">—</span>
              <span class="ai-card-sub" id="ai-carousel-meta">—</span>
              <span id="ai-carousel-dots" style="display:flex;gap:4px;margin-left:6px">
                <span class="cdot cdot-0 active"></span>
                <span class="cdot cdot-1"></span>
                <span class="cdot cdot-2"></span>
              </span>
            </div>
            <div class="panel-progress" id="global-progress"></div>
            <div class="ai-card-body" id="ai-carousel-body">—</div>
          </div>
          <div class="panel" style="flex:3">
            <div class="panel-h">
              <span class="tick"></span>
              <span class="tag">Global Feed</span>
              <span class="spacer"></span>
              <span class="hint">Geopolitics · Markets</span>
              <?= kebab('global', 'Global Feed') ?>
            </div>
            <div class="panel-body">
              <div class="news-scroll" id="global-scroll">
                <div class="news-track" id="global-track"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ---- COL 3: POTUS + CYBER / DIB / AI ---- -->
        <div class="col">
          <div class="panel" style="flex:1">
            <div class="panel-h">
              <span class="tick potus"></span>
              <span class="tag">Interesting People Posts</span>
              <span class="spacer"></span>
              <span class="hint">X &amp; Truth Social</span>
              <?= kebab('x,truth', 'Interesting People') ?>
            </div>
            <div class="panel-body">
              <div class="news-scroll" id="truth-scroll">
                <div class="news-track" id="truth-track"></div>
              </div>
            </div>
          </div>
          <div class="panel" style="flex:2">
            <div class="panel-h">
              <span class="tick cyber"></span>
              <span class="tag">Cyber · DIB · AI</span>
              <span class="spacer"></span>
              <span class="hint">AI-Prioritized</span>
              <?= kebab('cyber', 'Cyber Feed') ?>
            </div>
            <div class="panel-body">
              <div class="cyber-digest-note" id="cyber-digest-note" style="display:none"></div>
              <div class="news-scroll" id="cyber-scroll">
                <div class="news-track" id="cyber-track"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ---- COL 4: WEATHER + ESPP ---- -->
        <div class="col">
          <div class="panel">
            <div class="panel-h">
              <span class="tick cyber"></span>
              <span class="tag">Weather</span>
              <span class="spacer"></span>
              <span class="hint">7-Day</span>
              <?= kebab('weather', 'Weather') ?>
            </div>
            <div class="panel-body">
              <div class="wx-current">
                <div class="wx-loc" id="wx-loc">—</div>
                <div class="wx-main">
                  <div class="wx-temp mono" id="wx-temp">—</div>
                  <div class="wx-cond">
                    <div class="wx-ico" id="wx-ico">⛅</div>
                    <div class="wx-cond-t" id="wx-cond-t">—</div>
                    <div class="wx-feels" id="wx-feels">—</div>
                  </div>
                </div>
                <div class="wx-stats" id="wx-stats"></div>
              </div>
              <div class="wx-forecast" id="wx-forecast"></div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-h">
              <span class="tick gain"></span>
              <span class="tag">ESPP</span>
              <span class="spacer"></span>
              <span class="hint">Yahoo Finance</span>
              <?= kebab('espp', 'ESPP') ?>
            </div>
            <div class="panel-body">
              <div class="espp" id="espp-body"></div>
            </div>
          </div>

        </div>

      </div>

      <!-- ============ TICKER TAPE ============ -->
      <div id="ticker">
        <div class="tk-label"><span class="dot"></span> Holdings</div>
        <div class="tk-viewport" id="ticker-viewport">
          <div class="tk-track" id="ticker-track"></div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ============ CONFIG MODAL (opened by the ⋮ kebabs) ============ -->
<div id="cfg-overlay" class="cfg-overlay" hidden>
  <div class="cfg-modal" role="dialog" aria-modal="true" aria-labelledby="cfg-title">
    <div class="cfg-bar">
      <span class="cfg-title" id="cfg-title">Configure</span>
      <button class="cfg-close" type="button" aria-label="Close">&#10005;</button>
    </div>
    <iframe class="cfg-frame" id="cfg-frame" title="Configuration" src="about:blank"></iframe>
  </div>
</div>

<script>
  window.DASH_TZ = <?= json_encode($timeZone) ?>;
  window.DASH_FEED_HL = <?= json_encode($feedHl) ?>;  /* new-item highlight window, minutes, per feed */
  window.DASH_TICKER  = { speed: <?= (int)$tickerSpeed ?> };  /* ticker tape scroll speed, px/sec */
</script>
<script src="js/mock-data.js?v=1"></script>
<script src="js/format.js?v=1"></script>
<script src="js/api.js?v=1"></script>
<script src="js/autoscroll.js?v=1"></script>
<script src="js/panels.js?v=5"></script>
<script src="js/main.js?v=7"></script>
</body>
</html>
