# Call Analyse

Call Analyse is a call-center quality management application. Supervisors and QA staff upload customer call recordings, the system transcribes them with speaker diarization, scores operator behavior and customer tone, and presents results in a review dashboard. The goal is to measure operator courtesy, detect rudeness/aggression/interruptions, flag inappropriate language, and surface buyer potential — all from real phone conversations in Azerbaijani, Russian, or English.

## Technology stack

| Layer | Technologies |
|-------|--------------|
| Backend | PHP 8.3, Laravel 13, Laravel Sanctum |
| Frontend | Vue 3, Inertia.js v2, Tailwind CSS, Vite, Axios |
| Database | SQLite (default), Eloquent ORM |
| Queue | Laravel database queue (`jobs` table) |
| Transcription & AI scoring | Google Gemini Interactions API (`gemini-3.5-transcribe` for audio, `gemini-3.6-flash` for text analysis) |
| Testing | PHPUnit |

Legacy `python/` scripts (local Whisper) exist but are **not** used by the production pipeline — all transcription runs through Gemini.

## How it works

### End-to-end pipeline

```
Upload audio → TranscribeCallJob → AnalyzeCallJob → Call detail UI
```

1. **Upload** — User uploads audio (mp3, wav, m4a, ogg, webm; max 50 MB) via the web UI or API. A `calls` record is created with `status = pending` and `TranscribeCallJob` is dispatched.

2. **TranscribeCallJob** — Uploads audio to Gemini Files API, then calls the Interactions API with `gemini-3.5-transcribe` (configurable via `GEMINI_TRANSCRIPTION_MODEL`). The model returns verbatim text with word-level speaker annotations (`spk:0`, `spk:1`, …).

   Post-processing in `WhisperTranscriptionService`:
   - **Speaker role labeling** — The diarization prompt instructs Gemini to identify `operator` vs `musteri` based on context (who greets professionally, who states a need). Because Gemini's word annotations still return raw `spk:N` IDs, a post-processing step (`normalizeSpeakers`) scores each speaker's text against operator phrase patterns (e.g. "Buyurun", "necə kömək", "thank you for calling") and assigns roles. First-speaker bonus is used as a tiebreaker; if only one speaker is detected, they are labeled `operator`.
   - **Turn merging** — Consecutive words from the same speaker are merged into a single segment (`mergeSpeakerTurns`), combining text and spanning from the first word's start to the last word's end. Merging stops when another speaker's word overlaps the time window — overlaps are preserved as separate turns because they represent interruptions.
   - Segments are saved to `call_analyses.transcript_segments` with `speaker`, `start`, `end`, and `text` fields.

3. **AnalyzeCallJob** — Dispatched automatically after transcription completes. Computes:
   - **Gemini quality scores** (0–100) via `WhisperTranscriptionService::analyzeCallQuality()` using `gemini-3.6-flash`: `courtesy_score`, `rudeness_score`, `aggression_score`, `emotional_tone_score`.
   - **Deterministic counts** via `CallScoringService`:
     - `operator_interruption_count` / `customer_interruption_count` — directional interruption counts based on segment overlap and `operator`/`musteri` roles.
     - `interruption_count` — sum of both directional counts (kept for backward compatibility and used in `total_score`).
     - `inappropriate_words_count` — dictionary match against `config/inappropriate_words.php` for the call's language.

4. **total_score** — Starts at 100. Each category deducts `(weight / 100) × (100 − goodness)`. A weight is the maximum points that category can remove when its goodness score is 0. Weights are not divided by their sum and do not need to total 100.

```
goodness:
  courtesy, both interruption scores, both inappropriate-word scores = those 0–100 scores
  rudeness = 100 − rudeness_score
  emotion   = 100 − emotional_tone_score
  event scores = max(0, 100 − 10 × count)

total_score = round(clamp(100 − Σ(weight_i / 100 × (100 − goodness_i)), 0, 100))
```

If every weight is 0, `total_score` is 100. Parameters (from `settings`, group `scoring_params`, defaults in parentheses): `weight_courtesy` (25), `weight_operator_interruption` (10), `weight_customer_interruption` (10), `weight_rudeness` (15), `weight_emotion` (10), `weight_operator_inappropriate` (7), `weight_customer_inappropriate` (8).

### TranscribeCallJob unique lock

`TranscribeCallJob` implements `ShouldBeUnique` (one job per call ID). Laravel's `UniqueLock` reads the `uniqueFor()` **method** (not a public property) to set lock TTL. Without `uniqueFor()`, the TTL becomes 0 and the database driver defaults to **24 hours** — causing reprocess requests to silently no-op.

- `uniqueFor()` returns `max(60, $timeout)` seconds.
- `TranscribeCallJob::releaseUniqueLock($call)` is called from `reprocess()` and `failed()` to clear a stuck lock.
- Reprocess returns HTTP 409 if the call is already `processing`.

### Operational gotchas

- **Restart queue workers after code changes.** `php artisan queue:work` caches loaded PHP for the process lifetime. After deploying or editing backend code, stop and restart the worker or changes won't take effect.
- **Restart the Python SER service** after swapping `MODEL_DIR` / `MODEL_VERSION` or editing `python/ser_service/app.py` — the model loads only at process startup.
- **Update Gemini model names periodically.** Google deprecates model versions. Check [Google AI model docs](https://ai.google.dev/gemini-api/docs/models) and update `GEMINI_MODEL` / `GEMINI_TRANSCRIPTION_MODEL` in `.env` when models are retired.
- **Reprocess existing calls** to get updated diarization (merged turns, operator/musteri labels) — old records may still have raw `spk:0` word-level segments.
- **Frontend rebuild** — After Vue changes, run `npm run dev` or `npm run build`.

## Project structure

```
app/
├── Console/Commands/                        # SeedCallsFromFolder, TestTranscription, SER/export cmds
├── Http/Controllers/Api/                    # JSON API controllers
├── Jobs/
│   ├── TranscribeCallJob.php               # Queued audio transcription (ShouldBeUnique)
│   ├── AnalyzeCallJob.php                  # Queued call quality scoring (Gemini)
│   └── RescoreCallWithSerJob.php           # Queued acoustic SER scoring
├── Models/                                  # Call, CallAnalysis, SerScore, Operator, User, Setting
└── Services/
    ├── WhisperTranscriptionService.php      # Gemini upload, transcribe, quality analysis
    ├── CallScoringService.php               # Interruption and inappropriate-word counts
    ├── AudioClipExtractionService.php       # Shared ffmpeg segment clip extraction
    └── SerScoringService.php                # Per-segment HTTP client for local SER FastAPI service

config/
├── whisper.php                              # Gemini API key, models, timeout
├── ser.php                                  # Acoustic SER service URL / timeouts
└── inappropriate_words.php                  # Profanity dictionaries (az, ru, en)

python/ser_service/                          # Standalone wav2vec2 SER inference (port 8001)
resources/js/Pages/Calls/Show.vue           # Call detail: audio, transcript, Gemini + SER scores
database/migrations/                         # Schema including ser_scores history
tests/                                       # Feature and unit tests
```

## Installation

**Requirements:** PHP 8.3+, Composer, Node.js 18+, npm.

```bash
composer install
cp .env.example .env
php artisan key:generate

# SQLite (default)
touch database/database.sqlite
php artisan migrate
php artisan db:seed          # optional demo data

php artisan storage:link
npm install
npm run build
```

Set `GEMINI_API_KEY` in `.env` before transcribing audio.

Or run the bundled setup:

```bash
composer run setup
```

## How to run

**Development** (server, queue, logs, Vite together):

```bash
composer run dev
```

**Manual:**

```bash
php artisan serve
npm run dev
php artisan queue:work --timeout=120
```

Without a running queue worker, uploaded calls stay in `pending` status.

**Test transcription from CLI:**

```bash
php artisan test:transcription "path/to/audio.mp3" --language=az
```

### Bulk import / testing

Seed many local audio files as `Call` records and queue the normal transcription + Gemini analysis pipeline. Intended for **demo / local testing only**, not production imports.

Place files in `dataset/` at the project root (mp3, wav, m4a, ogg, webm), ensure operator id `1` exists (`php artisan db:seed`), then:

```bash
# Preview name/phone/called_at without writing anything
php artisan calls:seed-from-folder --dry-run

# Import with a 5s pause between each TranscribeCallJob dispatch (default)
php artisan calls:seed-from-folder

# Faster or slower staggering
php artisan calls:seed-from-folder --delay=10

# Custom folder (absolute or relative to project root)
php artisan calls:seed-from-folder --path=tests/Fixtures/audio --delay=0
```

Each file is copied into `storage/app/public/calls/audio/` the same way web uploads are, creates a pending `Call` (`operator_id=1`, language `az`, random Azerbaijani customer name/phone, random `called_at` within the last 30 days), and dispatches `TranscribeCallJob`. Restart the queue worker after code changes so jobs pick up the latest app code:

```bash
php artisan queue:work --timeout=120
```

**Run tests:**

```bash
composer test
# or
php artisan test
```

## Main features

- Upload call audio with language `az`, `ru`, or `en`
- Queue-based transcription with speaker diarization (operator / müştəri labels, merged turns)
- Automated quality scoring (Gemini + deterministic interruption/profanity counts)
- Call list with filters (search, status, operator, score range, problem flag)
- Call detail: audio playback, clickable transcript segments (row click seeks audio; chevron expands segment review), per-metric scores
- Manual supervisor review (`manual_score`, `manual_note`)
- Reprocess call (re-dispatch transcription; releases unique lock)
- Dashboard stats, score trend, recent activity (`?period=today|week|month`)
- Alerts for low scores, aggression, rudeness, high buyer potential
- Operator CRUD, user management (roles: `admin`, `supervisor`, `qa`, `manager`)
- Settings: independent scoring weights (relative importance per category; need not sum to 100), alert threshold, default language, audio retention

## Scoring fields on `call_analyses`

| Field | Type | Source | Description |
|-------|------|--------|-------------|
| `courtesy_score` | 0–100 | Gemini | Politeness / professionalism |
| `rudeness_score` | 0–100 | Gemini | Higher = more rude |
| `aggression_score` | 0–100 | Gemini | Higher = more aggressive |
| `emotional_tone_score` | 0–100 | Gemini | Higher = more agitated / negative; inverted in `total_score` like rudeness |
| `operator_interruption_count` | integer | Deterministic | Times operator interrupted customer |
| `customer_interruption_count` | integer | Deterministic | Times customer interrupted operator |
| `interruption_count` | integer | Sum of above | Used in `total_score` penalty |
| `inappropriate_words_count` | integer | Dictionary | Profanity / inappropriate language matches |
| `total_score` | 0–100 | Formula | Starts at 100; each category deducts up to its weight |

`interruption_count` — total cross-speaker overlaps (backward compatible). `operator_interruption_count` and `customer_interruption_count` track who interrupted whom when speaker roles are known (`operator`/`musteri` or legacy `spk:N` IDs). The directional counts may not sum to `interruption_count` on segments with unknown speaker labels.

## Environment variables

| Variable | Purpose | Default |
|----------|---------|---------|
| `APP_URL` | Application URL | `http://localhost:8000` |
| `DB_CONNECTION` | Database driver | `sqlite` |
| `QUEUE_CONNECTION` | Queue driver | `database` |
| `DB_QUEUE_RETRY_AFTER` | Job retry window (seconds; keep above SER job timeout of 600) | `700` |
| `WHISPER_TIMEOUT` | Transcription / job timeout (seconds) | `120` |
| `GEMINI_API_KEY` | Google Gemini API key (**required**) | — |
| `GEMINI_MODEL` | Model for call quality text analysis | `gemini-3.6-flash` |
| `GEMINI_TRANSCRIPTION_MODEL` | Model for audio transcription + diarization | `gemini-3.5-transcribe` |
| `GEMINI_SEGMENT_QUALITY_BATCH_SIZE` | Segments per Gemini request for weak labels | `25` |
| `SER_SERVICE_URL` | Local acoustic SER FastAPI base URL | `http://localhost:8001` |
| `SER_TIMEOUT` | SER `/predict` timeout (seconds) | `30` |
| `SER_CONNECT_TIMEOUT` | SER connect timeout (seconds) | `3` |

Both Gemini models call `https://generativelanguage.googleapis.com/v1beta/interactions`.

## Custom Acoustic SER Model

Parallel to Gemini text scoring, the app can score call audio with a locally trained wav2vec2 + LoRA Speech Emotion Recognition (SER) model. SER scores are **never** merged into `total_score` or Gemini fields on `call_analyses` — call-level aggregates live in an append-only history table, and per-segment SER scores are stored alongside Gemini scores inside `transcript_segments` (keys prefixed with `ser_`).

### How scoring works (per-segment)

The Python `/predict` endpoint scores whatever audio clip it receives (internally the model uses up to ~8 seconds of that clip). Laravel therefore **must not** send the whole call file — that only reflected the opening of the call and produced misleading results versus Gemini's full-transcript scores.

Instead:

1. For each entry in `transcript_segments` (skipping clips shorter than 0.5s), `AudioClipExtractionService` cuts a temp clip with ffmpeg (`-ss` / `-to`, stream copy with PCM fallback — same logic as `calls:export-segment-training-data`).
2. `SerScoringService::scoreSegments()` posts each clip to `/predict` and returns results in segment order. A single segment failure is logged and left as `null`; the rest continue.
3. Successful scores are written onto each segment as `ser_courtesy_score`, `ser_rudeness_score`, `ser_aggression_score`, `ser_emotional_tone_score`, `ser_model_version`.
4. The call-level aggregate (mean of each score across successfully scored segments) is appended as one new `ser_scores` row.

### Python inference service

Standalone FastAPI app in `python/ser_service/` (separate from the Laravel transcription stack):

```bash
cd python/ser_service
python -m venv .venv
# Windows: .venv\Scripts\activate
pip install -r requirements.txt

set MODEL_DIR=C:\path\to\final_model
set MODEL_VERSION=v1
uvicorn app:app --host 0.0.0.0 --port 8001
```

- `GET /health` → `{ "status": "ok", "model_version": "v1" }`
- `POST /predict` (multipart `file`) → courtesy / rudeness / aggression / emotional_tone scores (0–100 floats) + `model_version`

The model loads **once at startup** from `MODEL_DIR` (`best_model.pt`, `feature_extractor/`, `score_fields.json`). See `python/ser_service/README.md` for swap instructions. The Python service itself does not need changes for per-segment scoring — Laravel sends segment clips instead of the whole file.

### `ser_scores` history table (append-only)

Each re-score inserts a **new** row: `call_id`, `model_version`, four float scores (means of per-segment SER results), `scored_at`. Existing rows are never updated or deleted. One call can have many rows (one per model version / scoring run). This lets you compare `v1` vs `v2` without losing history.

Laravel pieces:

- `App\Models\SerScore` + `Call::serScores()` / `Call::latestSerScore()`
- `App\Services\AudioClipExtractionService` — shared ffmpeg segment clip extraction (used by SER scoring and training-data export)
- `App\Services\SerScoringService::scoreSegments()` — scores each transcript segment clip; connection / per-segment failures are logged and skipped
- `App\Services\SerScoringService::aggregateSegmentScores()` — mean of successful segment scores for the call-level row
- `config/ser.php` — `SER_SERVICE_URL` (default `http://localhost:8001`)

### Re-score after training a new model

Manual workflow:

1. Train in Colab and download `final_model/`
2. Point `MODEL_DIR` at the new folder and set `MODEL_VERSION` (e.g. `v2`)
3. **Restart** the Python inference service (`uvicorn`)
4. Run a backfill (requires `queue:work`):

```bash
php artisan calls:rescore-with-ser --all
php artisan calls:rescore-with-ser --since=2026-01-01
php artisan calls:rescore-with-ser --call-id=42
```

The command checks `/health` first, then dispatches `RescoreCallWithSerJob` per call (not synchronous). Each job writes per-segment `ser_*` fields into `transcript_segments` and appends one aggregate `ser_scores` row. Job timeout is 600s to allow many segments; keep `DB_QUEUE_RETRY_AFTER` above that (e.g. 700+).

### UI / API

`GET /api/calls/{id}` includes a separate `ser_score` key (latest row by `scored_at`) alongside Gemini `analysis`. The call detail page shows:

- Call-level card: **Səs-əsaslı analiz (model: v1)** — aggregate mean of segment SER scores (parallel to Gemini, not mixed into Overall)
- Per-segment review form: **(AI)** Gemini, **(SER)** acoustic (teal / read-only), **(manual)** supervisor inputs

### Operational reminders

- **Restart the queue worker** after Laravel SER code changes (`php artisan queue:work`).
- **Restart the Python SER service** after any model swap or `app.py` change.
- Keep Gemini and SER signals separate until you deliberately decide to blend them.

## Supervisor review and training data collection

### Call-level manual review (existing)

Supervisors can override the automated `total_score` with a single `manual_score` and `manual_note` on the call detail page (`PATCH /api/calls/{id}/review`). This feeds `effective_score` in the UI and dashboard but does **not** change `total_score` itself.

### Segment-level manual review (ground truth for SER)

Each diarized speaker turn in `transcript_segments` can be reviewed independently on the call detail page:

1. Open a call's transcript panel — progress shows **"Yoxlanılıb: X/Y"**.
2. **Click a segment row** (speaker, timestamp, or text) to seek the audio player to that segment's `start` time (seconds).
3. Use the **chevron button** on the right to expand/collapse the inline manual scoring form (seek and review are separate — do not merge these click targets in future UI changes).
4. Gemini's four per-segment scores are shown read-only as **(AI)** reference; acoustic SER scores (when present) appear beside them as teal read-only **(SER)** values; editable **(manual)** inputs are pre-filled with the Gemini values.
5. Save via `PATCH /api/calls/{call}/segments/{segmentIndex}/review`.

Each reviewed segment stores:

- `manual_courtesy_score`, `manual_rudeness_score`, `manual_aggression_score`, `manual_emotional_tone_score` (nullable 0–100)
- `manual_reviewed` (boolean), `manual_reviewed_by` (user id)

This layer is independent of call-level `manual_score`/`manual_note` and does not affect `total_score`, alerts, or `AnalyzeCallJob`.

**Transcript seek behavior:** Each segment's `start`/`end` are stored as seconds (float). Clicking the segment row seeks via `audio.currentTime`, then waits for the `seeked` event before calling `play()` (seek is async — calling `play()` immediately after assignment races and restarts from 0:00). The expand chevron only toggles manual review.

> **Maintenance warning (`Calls/Show.vue` transcript list):** This panel has **two independent click-driven behaviors** that have broken each other twice already:
> 1. **Seek** — click the segment row (speaker / timestamp / text) → `seekToSegment(index)` (wait for `seeked`, then `play()`).
> 2. **Review expand** — click the **chevron only** → `toggleSegmentReview(index)` (`@click.stop` so it never seeks).
>
> Do **not** merge these into one click handler, and do not simplify the segment row markup in a way that drops speaker badges, the **Yoxlanılıb: X/Y** counter, or the expand/scoring form. After any edit to this component, explicitly verify **both** seek and review-expand still work, and run `npm run build` (or `npm run dev`) so `public/build` is not serving a stale bundle.

### Segment-level Gemini weak labels

Beyond the existing **call-level** scoring (used for `total_score`, dashboard, alerts, and manual review), the app can enrich each diarized speaker turn with per-segment Gemini scores. This data is **not** wired into the UI scoring panels, `total_score`, or alerts — it exists to bootstrap a future acoustic Speech Emotion Recognition (SER) model.

### What gets added

After `AnalyzeCallJob` saves the existing call-level scores, it optionally calls `WhisperTranscriptionService::applySegmentQualityScores()`. Each `transcript_segments` entry gains:

- `courtesy_score`, `rudeness_score`, `aggression_score`, `emotional_tone_score` (0–100 integers)

Segment scoring is batched (default 25 segments per Gemini request, configurable via `GEMINI_SEGMENT_QUALITY_BATCH_SIZE`). If segment scoring fails for any reason, the failure is logged and the job still completes with the existing call-level fields unchanged.

These Gemini-derived segment scores are **weak labels** — useful for initial dataset construction, but distinct from the **manual per-segment scores** supervisors enter during structured review. For training, prefer `has_manual_review: true` rows in the export manifest; use Gemini-only rows for a larger, noisier dataset.

### Backfill segment scores for existing calls

Calls analyzed before per-segment scoring was added will not have AI scores on their segments. Backfill without re-transcribing or re-running call-level analysis:

```bash
php artisan calls:backfill-segment-scores
php artisan calls:backfill-segment-scores --call=11
php artisan calls:backfill-segment-scores --dry-run
```

New calls receive segment scores automatically at the end of `AnalyzeCallJob` (after call-level scores are saved). Failures are logged and do not fail the job.

### Export training clips

```bash
php artisan calls:export-segment-training-data \
  --from=2026-01-01 \
  --to=2026-08-31 \
  --output-dir=storage/app/segment-training-data
```

For each completed, analyzed call in the date range, the command:

1. Reads `transcript_segments` (only segments that already have per-segment scores)
2. Skips segments shorter than 0.5 seconds
3. Extracts each segment as `{call_id}_{segment_index}.wav` via `ffmpeg` (`-c copy`, re-encodes to PCM WAV if copy fails)
4. Writes `manifest.jsonl` — one JSON object per line:

```json
{"clip_path":"11_0.wav","call_id":11,"segment_index":0,"speaker":"operator","duration_seconds":1.2,"courtesy_score":90,"rudeness_score":5,"aggression_score":2,"emotional_tone_score":88,"manual_courtesy_score":95,"manual_rudeness_score":3,"manual_aggression_score":1,"manual_emotional_tone_score":92,"has_manual_review":true}
```

Filter on `has_manual_review` when building a high-quality training set; omit the filter to include Gemini weak labels only.

**API cost note:** Segment scoring adds one or more Gemini requests per analyzed call (one per batch of ~25 segments), on top of the existing single call-level quality request. Long calls with many turns will increase token usage noticeably compared to call-level-only scoring.

## API endpoints

All routes require `auth:sanctum`. Base path: `/api`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/dashboard` | Dashboard statistics (`?period=today\|week\|month`) |
| GET | `/calls` | Paginated call list with filters |
| POST | `/calls` | Upload audio, create call, dispatch transcription |
| GET | `/calls/{id}` | Call detail with analysis, optional `ser_score`, and audio URL |
| PATCH | `/calls/{id}/review` | Save call-level manual score and note |
| PATCH | `/calls/{id}/segments/{segmentIndex}/review` | Save per-segment manual scores |
| POST | `/calls/{id}/reprocess` | Re-queue transcription (409 if processing) |
| DELETE | `/calls/{id}` | Delete call and audio file |
| GET | `/operators` | List operators |
| POST | `/operators` | Create operator |
| GET | `/operators/{id}` | Show operator |
| PUT | `/operators/{id}` | Update operator |
| DELETE | `/operators/{id}` | Delete operator |
| GET | `/alerts` | Paginated alert list |
| GET | `/users` | List users |
| POST | `/users` | Create user |
| GET | `/users/{id}` | Show user |
| PUT | `/users/{id}` | Update user |
| DELETE | `/users/{id}` | Delete user |
| GET | `/settings` | Get application settings |
| PUT | `/settings` | Update settings |

### `GET /calls/{id}` analysis object (relevant fields)

```json
{
  "transcript": "full text…",
  "transcript_segments": [
    { "speaker": "operator", "start": 0.6, "end": 1.2, "text": "Buyurun." },
    { "speaker": "musteri", "start": 2.2, "end": 3.1, "text": "Salam, Əli xanım." }
  ],
  "courtesy_score": 80,
  "rudeness_score": 20,
  "aggression_score": 10,
  "emotional_tone_score": 75,
  "operator_interruption_count": 1,
  "customer_interruption_count": 2,
  "interruption_count": 3,
  "inappropriate_words_count": 0,
  "total_score": 74
}
```

When an acoustic SER score exists, the same response also includes a sibling `ser_score` object (not nested under `analysis`):

```json
{
  "model_version": "v2",
  "courtesy_score": 82.5,
  "rudeness_score": 8.0,
  "aggression_score": 2.5,
  "emotional_tone_score": 90.0,
  "scored_at": "06.09.2026 20:00"
}
```

Web UI routes (`/dashboard`, `/calls`, etc.) render Inertia pages and consume the API via session cookies.

## Demo users

After `php artisan db:seed`:

| Email | Password | Role |
|-------|----------|------|
| `admin@callanalyse.az` | `password` | admin |
| `supervisor@callanalyse.az` | `password` | supervisor |
