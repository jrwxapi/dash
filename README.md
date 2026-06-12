# Personal Intelligence Dashboard — LAPP edition

A no-Docker port of the containerized "slop-dashboard" sample to a plain
**Linux / Apache / PostgreSQL / PHP** stack. Same kiosk frontend (1920×1080,
auto-scaled), same panels — news, cyber/DIB feed, Truth Social, weather,
portfolio, ESPP, optional local-AI summaries — but everything runs directly on
the machine with no containers, Redis, FastAPI, or WebSockets.

## Quickstart

```bash
bash setup.sh
```

Then open **http://localhost/dash/** and configure things at
**http://localhost/dash/admin.php** (display name, weather location, your
holdings, ESPP position).

## How the port maps to the original

| Original (Docker) | This port (LAPP) |
|---|---|
| Redis cache + pub/sub | `kv` table in PostgreSQL (key, jsonb, ttl) |
| FastAPI + WebSocket push | `public/api.php?action=state` polled every 60s by `js/api.js` |
| Python worker containers | PHP pollers in `cron/pollers/`, dispatched by `cron/poll.php` every minute |
| Robinhood login + Infisical vault | Manual holdings table in admin.php; quotes from Yahoo Finance's public chart API |
| yfinance ESPP tracker | Same Yahoo endpoint, position stored in `settings` |
| Ollama containers + custom Modelfile | Optional: native Ollama install, stock models, same prompts (`cron/pollers/ai.php`) |
| SSE streaming briefing | Cached briefing rendered from state (no token streaming) |
| Mobile page (`/mobile`) | Not ported (desktop/kiosk view only) |

## Poller schedule

`cron/poll.php` runs every minute and fires each poller when its interval has
elapsed (configured in `includes/config.php`):

| Poller | Interval | Source |
|---|---|---|
| quotes | 5 min | Yahoo Finance chart API (browser UA required) |
| truth | 10 min | trumpstruth.org RSS mirror |
| weather | 15 min | Open-Meteo (no key) |
| news | 30 min | 16 RSS feeds (global + cyber/defense) |
| ai | 15 min | Local Ollama, skips digests fresher than 30 min |

Run one immediately, ignoring the schedule: `php cron/poll.php news`
Logs: `logs/poll.log`. Last-run status is also shown on the admin page.

## AI cards (optional)

Without Ollama the briefing/digest cards show an offline note and everything
else works. To enable:

```bash
curl -fsSL https://ollama.com/install.sh | sh
ollama pull phi3:mini
ollama pull gemma2:2b
```

Host and model names are editable in admin.php. Digests regenerate at most
every 30 minutes; "Force AI regenerate" on the admin page clears them.

## Security notes

- Apache serves the site with `Require local` — only this machine. For a
  kiosk/LAN setup, change it to `Require all granted` (or an IP allowlist) in
  `/etc/apache2/conf-available/dash.conf` and reload Apache.
- There is no login; the admin page is protected only by that Apache rule.
  Don't expose it beyond networks you trust.
- Change `DB_PASS` in `setup.sh` + `includes/config.php` if the Postgres
  instance is shared.

## Layout

```
public/          Apache-served docroot (Alias /dash)
  index.php      dashboard (ported kiosk frontend)
  admin.php      settings, holdings, poller status
  api.php        JSON state endpoint
  js/, styles.css  frontend (api.js rewritten for polling; rest ~as upstream)
includes/        config + PDO/kv/settings helpers
cron/poll.php    dispatcher (crontab runs it every minute)
cron/pollers/    quotes, news, truth, weather, ai
db/schema.sql    kv, settings, holdings, equity_history
```

If the page shows "Demo Feed", the API returned no data yet — wait for the
first cron cycle or check `logs/poll.log` and the admin poller-status table.
