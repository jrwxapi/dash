-- Personal dashboard schema.
-- kv replaces the Redis cache from the original Docker stack: every poller
-- writes its payload here and the frontend reads it back via public/api.php.

CREATE TABLE IF NOT EXISTS kv (
    key        TEXT PRIMARY KEY,
    value      JSONB NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

-- Named, collapsible groupings for holdings (e.g. "Retirement", "Cyber bets").
-- Managed in the admin page; a position with no section renders as "Ungrouped".
CREATE TABLE IF NOT EXISTS portfolio_sections (
    id         SERIAL PRIMARY KEY,
    name       TEXT NOT NULL UNIQUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Manual portfolio (replaces the Robinhood integration): enter positions in
-- the admin page; quotes are fetched from Yahoo Finance by cron/pollers/quotes.php.
CREATE TABLE IF NOT EXISTS holdings (
    id       SERIAL PRIMARY KEY,
    ticker   TEXT NOT NULL UNIQUE,
    name     TEXT NOT NULL DEFAULT '',
    quantity NUMERIC NOT NULL DEFAULT 0,
    avg_cost NUMERIC
);

-- Idempotent migrations so re-running this file upgrades an existing DB:
--   • section_id groups a holding (NULL → Ungrouped; section delete → NULL)
--   • avg_cost becomes optional (NULL = unknown cost basis, distinct from 0)
ALTER TABLE holdings ADD COLUMN IF NOT EXISTS section_id INTEGER
    REFERENCES portfolio_sections(id) ON DELETE SET NULL;
ALTER TABLE holdings ALTER COLUMN avg_cost DROP NOT NULL;
ALTER TABLE holdings ALTER COLUMN avg_cost DROP DEFAULT;

-- Account-value samples recorded each quotes poll; feeds the 5-day sparkline.
CREATE TABLE IF NOT EXISTS equity_history (
    id          SERIAL PRIMARY KEY,
    equity      NUMERIC NOT NULL,
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO settings (key, value) VALUES
    ('display_name',      'Operator'),
    ('weather_lat',       '40.7128'),
    ('weather_lon',       '-74.0060'),
    ('weather_location',  'New York, NY'),
    ('espp_ticker',       ''),
    ('espp_name',         'ESPP Holding'),
    ('espp_shares',       '0'),
    ('espp_cost_basis',   '0'),
    ('ollama_host',       'http://127.0.0.1:11434'),
    ('ollama_model',      'phi3:mini'),
    ('ollama_json_model', 'gemma2:2b')
ON CONFLICT (key) DO NOTHING;
