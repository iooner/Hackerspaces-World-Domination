# 🌍 Hackerspaces World Domination
Blog article (in french https://iooner.io/hswd/)

> A live, interactive globe of hackerspaces around the world.

**[hswd.iooner.io](https://hswd.iooner.io)** — crafted with ❤️ in 🇧🇪 by [iooner](https://iooner.io) @ [Liège Hackerspace](https://lghs.be)

---

## What is this?

A real-time map aggregating hackerspaces from two sources:

- **[SpaceAPI](https://spaceapi.io/)** — an open standard that hackerspaces use to publish their live status (open/closed/limited). Updated every few hours via a cron job.
- **[mapall.space](https://mapall.space/)** / **[wiki.hackerspaces.org](https://wiki.hackerspaces.org)** — static data for spaces without a SpaceAPI endpoint, shown as blue dots.

Nearby spaces cluster together at low zoom levels. Click a cluster to expand it, click a dot to see details.

---

## Status colors

| Color | Meaning |
|-------|---------|
| 🟢 Green | Currently open (SpaceAPI live) |
| 🟡 Yellow | Open to members only — `open_to_visitors: false` ([proposed for SpaceAPI schema v16](https://github.com/SpaceApi/schema/issues/133)) |
| 🔴 Red | Currently closed (SpaceAPI live) |
| ⚪ White | API temporarily unavailable — space kept visible for 30 days |
| 🔵 Blue | Static data from wiki / mapall, no live status |
| 🟠 Orange *(footer)* | API unreachable for 30+ days — removed from the globe |

---

## My space isn't on the map

Two ways to get listed:

1. **SpaceAPI** — implement the [SpaceAPI standard](https://spaceapi.io/) and submit your endpoint to the [directory](https://directory.spaceapi.io/). You'll get a live colored dot.
2. **Wiki** — register on [wiki.hackerspaces.org](https://wiki.hackerspaces.org/) following their guidelines. You'll appear as a static blue dot.

Changes appear on the next cache update (every 10 minutes).

## My space is wrong / misplaced

Coordinates and info come directly from the sources above — fix them at the source (your SpaceAPI endpoint or your wiki page) and the globe will update automatically on the next run.

---

## Project structure

```
.
├── index.html                   # Frontend — MapLibre GL JS globe, single file
├── api.php                      # API endpoint (see below)
├── assets/
│   ├── countries-50m-hswd.json  # World atlas 50m, pre-processed (antimeridian fix)
│   ├── countries-10m-hswd.json  # World atlas 10m, pre-processed (antimeridian fix)
│   └── imin.gif                 # Rick & Morty asset, very important
└── cache/
    ├── update_cache.php         # Cache pipeline (run via cron)
    ├── hackerspaces_cache.json  # Generated — main data cache
    ├── run_history.json         # Generated — pipeline run history (7 days)
    ├── banlist.json             # Manual exclusion list
    ├── test_geojson.json        # Dev snapshot — ignored in production
    └── .htaccess                # Blocks direct access to cache files
```

> **Note:** `hackerspaces_cache.json`, `run_history.json`, and `test_geojson.json` are generated at runtime and should be added to `.gitignore`.
>
> `countries-50m-hswd.json` and `countries-10m-hswd.json` are pre-processed from [world-atlas](https://www.npmjs.com/package/world-atlas) v2.0.2 to fix antimeridian artifacts (Russia, Fiji, Antarctica) in MapLibre's globe projection. They are committed to avoid requiring a Node.js build step on deploy.

---

## API

`api.php` serves the cache. All endpoints return JSON with CORS headers (`Access-Control-Allow-Origin: *`).

### `GET api.php?format=geojson`

Returns a GeoJSON `FeatureCollection` — the format consumed by the frontend.

**Query parameters:**

| Parameter | Values | Description |
|-----------|--------|-------------|
| `format` | `geojson` | GeoJSON FeatureCollection |
| `format` | `history` | Pipeline run history |
| `state` | `open` `limited` `closed` `unknown` `static` | Filter by state |
| `limit` | integer ≤ 1008 | Max runs to return (history only) |

**GeoJSON response:**

```json
{
  "type": "FeatureCollection",
  "metadata": {
    "last_update": "2026-06-13T16:20:02+00:00",
    "stats": {
      "open": 73, "limited": 1, "closed": 123,
      "unknown": 3, "static": 387, "down": 16,
      "expired": 0, "banned": 1, "no_coords": 2
    },
    "count": 587,
    "cache_age_hours": 0.1,
    "cache_age_text": "6 minutes ago"
  },
  "features": [
    {
      "type": "Feature",
      "geometry": { "type": "Point", "coordinates": [5.5856, 50.6427] },
      "properties": {
        "name": "Liège Hackerspace",
        "state": "limited",
        "city": "Liège",
        "country": "",
        "address": "Rue de la Loi 16, 4020 Liège, Belgique",
        "message": "Ouvert aux membres uniquement.",
        "url": "https://lghs.be",
        "logo": "https://raw.githubusercontent.com/LgHS/branding/...",
        "lastchange": 1718290800,
        "last_seen": "2026-06-13T16:20:02+00:00"
      }
    }
  ]
}
```

> **Coordinates:** GeoJSON order is `[longitude, latitude]`.

### `GET api.php?format=history`

Returns the pipeline run history for the stats modal.

```json
{
  "runs": [
    {
      "ts": "2026-06-13T16:20:02+00:00",
      "dur": 73,
      "stats": { "open": 73, "limited": 1, "closed": 123, ... },
      "down": ["Reaktor 23", "Leitstelle511", ...],
      "expired": [{ "name": "OldSpace", "days": 45 }],
      "unknown": [{ "name": "FabLab Allgäu", "days": 0, "last_seen": "..." }],
      "banned": [{ "name": "NSHkr", "reason": "...", "source": "mapall" }],
      "total": 587
    }
  ]
}
```

### `GET api.php` *(legacy)*

Returns the raw cache JSON. Kept for backward compatibility.

---

## Cache pipeline

`cache/update_cache.php` — run via cron (every 10 minutes at [hswd.iooner.io](https://hswd.iooner.io)).

**Pipeline stages:**

1. **Download** the [SpaceAPI directory](https://raw.githubusercontent.com/SpaceApi/directory/refs/heads/master/directory.json) (~246 spaces)
2. **Load** the existing cache (for grace period logic)
3. **Fetch** all SpaceAPI endpoints in parallel (10 concurrent curl requests, 5s timeout)
   - `open: true` + `open_to_visitors: false` → state `limited`
   - API down + cached < 30 days → state `unknown` (kept on globe)
   - API down + cached ≥ 30 days → `expired` (removed)
   - API down + never cached → Nominatim geocoding fallback → state `static` or `down`
4. **Merge** with [mapall.space/wiki.json](https://mapall.space/wiki.json) (~470 spaces) — deduplication by name similarity and geographic distance (< 1 km)
5. **Write** `hackerspaces_cache.json` + append to `run_history.json`

**Configuration** (top of `update_cache.php`):

| Variable | Default | Description |
|----------|---------|-------------|
| `$timeout` | `5` | Per-request curl timeout (seconds) |
| `$maxConcurrent` | `10` | Parallel curl requests |
| `$expirationDays` | `30` | Days before a silent space is removed |

**Run manually** (browser or CLI):

```bash
# CLI
php cache/update_cache.php

# Browser — readable output with live progress
https://yourdomain.com/cache/update_cache.php
```

> The script outputs `text/plain` with live progress when called from a browser (output buffering disabled).

---

## Banlist

`cache/banlist.json` — manually maintained exclusion list. Checked before any space enters the cache.

```json
{
  "_comment": "Two levels: spaces (full exclusion) and domains (URL hidden, space kept if coords known).",
  "spaces": [
    {
      "name": "ExactSpaceName",
      "reason": "Why it was banned",
      "since": "2026-06"
    }
  ],
  "domains": [
    {
      "domain": "example.com",
      "reason": "Domain squatted",
      "since": "2026-06"
    }
  ]
}
```

**The `name` must match exactly** what appears in the SpaceAPI directory or mapall (case-sensitive). Check the cron output logs to find the exact name.

Think a ban should be lifted? [Open an issue or PR](https://github.com/iooner/Hackerspaces-World-Domination/issues).

---

## Tech stack

| Layer | Tech |
|-------|------|
| Globe | [MapLibre GL JS](https://maplibre.org/) v5.24 — globe projection |
| Map data | world-atlas v2.0.2 (pre-processed, antimeridian-fixed) |
| Clustering | MapLibre native `cluster: true` on GeoJSON source |
| Frontend | Vanilla JS + CSS, single `index.html`, no build step |
| Backend | PHP 8+ |
| Data sources | SpaceAPI directory + mapall.space/wiki.json |
| Geocoding | OpenStreetMap Nominatim (fallback only) |
| Fonts | IBM Plex Mono (Google Fonts) |

---

## Local development

No build step required. Clone and serve:

```bash
git clone https://github.com/iooner/Hackerspaces-World-Domination.git
cd Hackerspaces-World-Domination

# PHP built-in server
php -S localhost:8080

# Then open http://localhost:8080
# The frontend falls back to cache/test_geojson.json if api.php is unavailable
```

To generate a fresh cache:

```bash
php cache/update_cache.php
```

---

## Contributing

Issues, PRs and feature requests are welcome at **[github.com/iooner/Hackerspaces-World-Domination](https://github.com/iooner/Hackerspaces-World-Domination)**.

---

## Roadmap

Ideas and planned upgrades — contributions welcome!

### Frontend

- [x] **Search with fly-to** — search field in the terminal prompt block, autocomplete over all space names, globe flies to the result on select
- [x] **Clickable status bar** — clicking a segment (`open`, `limited`, `closed`…) in the footer filters the globe directly, as a natural alternative to the [ALL] / [OPEN] buttons
- [x] **Shareable URL** — `#space=Liege+Hackerspace` opens the globe centered on a space with the info card expanded; useful for QR codes on hackerspace doors
- [ ] **Kiosk mode** — `?kiosk` query param: UI hidden, continuous rotation, fullscreen; designed for wall-mounted screens at hackerspaces
- [x] **Live tab title** — `(59🟢) HSWD` — updates the browser tab with the current open count

### Data & pipeline

- [ ] **Linked spaces** *(deprioritized, 2026-08-29)* — display `linked_spaces` from SpaceAPI (e.g. [LgHS](https://spaceapi.lghs.be/)) in the info card, with live status for each linked space and reverse links. Surveyed the full directory: only 8/197 reachable spaces declare `linked_spaces` (~4%), 38 link entries total, and just 3 reciprocal pairs — the rest are one-way. Adoption too low to justify a globe-wide toggle right now; revisit if usage grows.
- [x] **Nominatim investigation** — resolved: not a connectivity/hosting issue. `allow_url_fopen`, curl, DNS and TLS are all fine server-side, and requests to Nominatim return `200 OK`. The ~16 lost spaces are closed/inactive hackerspaces with no matching result on OpenStreetMap for `"<name> hackerspace"` — a legitimate empty search, not a failure. Also fixed a real bug found along the way: `sleep(1)` (Nominatim's 1 req/sec limit) only ran after a successful geocode, not after a failed one, risking a rate-limit ban on runs with consecutive misses.
- [x] **Dead code cleanup** — remove `test_geojson.json` from the repo (already in `.gitignore`, needs a `git rm --cached`)

### Stats modal

- [ ] **Fix run history endpoint** — `api.php?format=history` returns "No history yet" despite `run_history.json` existing; path resolution issue between `api.php` (`__DIR__`) and `cache/` to investigate
