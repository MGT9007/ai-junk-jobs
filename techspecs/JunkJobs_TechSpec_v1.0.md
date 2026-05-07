# AI Junk Jobs — Technical Specification v1.0

**Plugin directory:** `ai-junk-jobs/`
**Shortcode(s):** `[ai_junk_jobs]`
**Version:** 4.1.0
**Author:** MisterT9007
**Purpose:** A 4-screen career exploration activity for UK students aged 11–14. Students name 5 jobs they would NOT want to do, drag-to-rank them from most to least undesirable, then write written explanations for each choice (minimum word count scales with age). An AI careers coach (SteveGPT, speaking as "Steve Sallis") then generates a structured response: a warm opener, an individual analysis per job (reframing each negative into a positive career requirement plus UK salary/qualification facts), and a synthesis conclusion identifying 3–4 positive career requirements the student DOES need. The complete analysis is stored and replayed on return visits. Integrates with the MFSD course ordering system to enforce task sequencing and report completion.

---

## File Structure

| File | Purpose |
|------|---------|
| `ai-junk-jobs.php` | Single-class plugin: activation, asset registration, shortcode, REST route registration, route handlers, admin menu and page |
| `assets/ai-junk-jobs.css` | All frontend styles in a dark gamer theme (CSS custom properties, Exo 2 + Nunito fonts via Google Fonts) |
| `assets/ai-junk-jobs.js` | Vanilla JS SPA: state machine with 4 screens (input, rank, reasons, results), drag-and-drop ranking, loading overlay with progress messages, text formatters for AI output |

---

## Database Schema

(Table created in `register_activation_hook` → `on_activate`)

### wp_mfsd_ai_junk_jobs_results

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED NOT NULL | WordPress user ID; UNIQUE KEY enforces one row per student |
| `jobs_json` | LONGTEXT | JSON array of 5 job titles as entered by student |
| `ranking_json` | LONGTEXT | JSON array of 5 job titles in ranked order (most → least undesirable) |
| `reasons_json` | LONGTEXT | JSON object keyed by job title, values are free-text explanations |
| `analysis` | LONGTEXT | Legacy: single-string AI output (pre-v4.0.0) |
| `intro_text` | LONGTEXT | Steve's warm opener paragraph (v4.0.0+) |
| `job_summary_1` | LONGTEXT | AI analysis for ranked job 1 |
| `job_summary_2` | LONGTEXT | AI analysis for ranked job 2 |
| `job_summary_3` | LONGTEXT | AI analysis for ranked job 3 |
| `job_summary_4` | LONGTEXT | AI analysis for ranked job 4 |
| `job_summary_5` | LONGTEXT | AI analysis for ranked job 5 |
| `conclusion` | LONGTEXT | Synthesis conclusion paragraph |
| `mbti_type` | CHAR(4) | Stored for future use (not yet set by this plugin) |
| `status` | VARCHAR(20) | `not_started` / `in_progress` / `reasons` / `completed` |
| `created_at` | DATETIME | Auto-set |
| `updated_at` | DATETIME | Auto-updated |

**Indexes:** `idx_user` (user_id), `idx_status` (status), `idx_user_unique` (user_id UNIQUE)

**Upgrade path:** `maybe_upgrade_table()` (called on `plugins_loaded` when version number changes) uses `DESCRIBE` + `ALTER TABLE` to add `intro_text`, `job_summary_1–5`, and `conclusion` columns to pre-4.0.0 installs without data loss.

---

## Key Flows

### New Student

1. Student visits a page containing `[ai_junk_jobs]`.
2. Shortcode checks MFSD Ordering gate: if task status is `locked`, a locked message is returned and no activity renders.
3. If status is `available`, `mfsd_set_task_status()` advances it to `in_progress`.
4. `window.AI_JUNK_JOBS_CFG` is written inline before the JS with REST URLs, nonce, user info, and age-based `minWords`.
5. JS calls `GET /ai-junk-jobs/v1/status` — receives `not_started` → renders Screen 1 (Input).
6. Student fills in 5 job names, clicks "Next: Rank them". JS calls `POST /submit` with `step=save_jobs` — saves row with status `in_progress`.
7. Screen 2 (Rank): student drags jobs to reorder. "Next: Explain why" calls `POST /submit` with `step=save_ranking` — saves ranking, advances status to `reasons`.
8. Screen 3 (Reasons): one textarea per ranked job, live word counter, submit disabled until each textarea meets `minWords`. Animated loading overlay cycles through per-job progress messages.
9. "Generate AI Analysis" calls `POST /submit` with `step=generate_analysis` — server makes 7 sequential AI calls, saves structured result, marks task `completed` via `mfsd_set_task_status()`.
10. Screen 4 (Results): structured output renders — Steve's opener, 5 per-job blocks (job name, student's reason in italic, Steve's reframe + UK facts), conclusion box. Navigation buttons link to Badges page and Course page.

### Returning Student (completed)

1. `GET /status` returns `completed` with stored `intro_text`, `job_summaries`, `conclusion`.
2. JS detects `has_new_format` and jumps directly to Screen 4 with the saved data.
3. If `save_summary` option is OFF and the row has `needs_regeneration`, the JS auto-triggers a fresh `generate_analysis` call.

### Back Navigation

- Screen 2 → Screen 1: calls `POST /submit` with `step=back_to_input`, clears ranking/reasons, resets status to `in_progress`.
- Screen 3 → Screen 2: calls `POST /submit` with `step=back_to_rank`, clears reasons but retains ranking, status stays `in_progress`.

---

## AJAX / REST Endpoints

| Route | Method | Auth | Description |
|-------|--------|------|-------------|
| `/wp-json/ai-junk-jobs/v1/status` | GET | Logged in | Returns the student's current progress status and saved data. Returns one of: `not_started`, `in_progress` (with jobs), `reasons` (with jobs + ranking), `completed` (with full analysis or `needs_regeneration` flag). |
| `/wp-json/ai-junk-jobs/v1/submit` | POST | Logged in | Multi-step save endpoint. Accepts JSON body with `step` plus relevant data. Valid steps: `save_jobs`, `save_ranking`, `back_to_input`, `back_to_rank`, `generate_analysis`. |

**Nonce:** `X-WP-Nonce` header with value from `wp_create_nonce('wp_rest')` is required by the WordPress REST API. The `permission_callback` checks `is_user_logged_in()` only; the nonce is validated by WordPress core's REST infrastructure.

All `$wpdb->replace()` calls use the UNIQUE KEY on `user_id` to upsert — each student always has exactly one row.

---

## Admin Panel

**Location:** WP Admin → Junk Jobs (menu position 31, dashicons-hammer, `manage_options`)

**Settings section:**

| Option | Key | Default | Description |
|--------|-----|---------|-------------|
| Course Management | `ai_junk_jobs_course_management` | 1 (on) | When on, integrates with MFSD Ordering for task locking and completion tracking |
| Save AI Summary | `ai_junk_jobs_save_summary` | 1 (on) | When on, stores the 7 AI segments in the DB for instant replay on return visits. When off, regenerates every visit — useful for prompt testing. |

**Student Records table:** lists all students who have started (display name, status, whether summary is saved, ordering status from `mfsd_get_task_status()`). Shows warning notice if `mfsd-ordering` plugin is not active.

**Reset Student Answers:** dropdown of students with data; on submit, deletes the student's row from `wp_mfsd_ai_junk_jobs_results` and also deletes their `junk_jobs` row from `wp_mfsd_task_progress`. Requires `check_admin_referer('ai_junk_jobs_reset_student')`.

Settings form requires `check_admin_referer('ai_junk_jobs_settings')`.

---

## SteveGPT Integration

The plugin uses `$GLOBALS['mwai']` (the SteveGPT/MWAI global) via `simpleTextQuery()`. It does NOT use the newer `SteveGPT_Chatbot::get()` pattern.

**When `generate_analysis` is called, 7 sequential AI calls are made:**

1. **Intro prompt** — warm 2-3 sentence opener addressed to the student by name. Persona: "SteveGPT — Steve Sallis's AI careers coach using Steve's Solutions Mindset." Mentions that what you don't want reveals what you DO need. Starts with "Steve says:". Under 60 words.

2–6. **Per-job prompts (×5)** — one call per ranked job. Inputs: job title, rank number, student's reason. Output structure: (1) acknowledge the concern, (2) one Solutions Mindset reframe (negative → positive requirement), (3) UK salary range and qualifications. No headers, no bullets, flowing sentences, under 80 words.

7. **Conclusion prompt** — synthesis of all 5 choices and reasons. Identifies 3-4 positive career requirements, ends encouragingly, signs off "— Steve". Under 100 words.

**Fallbacks:** If any individual AI call throws an exception, a hardcoded fallback string is used for that segment so the overall save still succeeds.

**Age-based minimum word count** for reasons (calculated from `date_of_birth` user meta):
- Age < 13: 30 words
- Age 13: 50 words
- Age ≥ 14: 100 words
- No DOB: 30 words (default)

---

## Assets

### `assets/ai-junk-jobs.css`

Dark gamer theme (same palette as Word Association). CSS custom properties defined on `.cq-wrap`. Exo 2 (headings) + Nunito (body) imported from Google Fonts. Key components:
- `.cq-wrap`, `.cq-card`: page/card containers with dark backgrounds and cyan glow
- `.cq-list`, `.cq-item`, `.cq-handle`, `.cq-ghost`: drag-and-drop list with ghost placeholder
- `.cq-rankpill`: "N of 5" position indicator
- `.cq-input-row`, `.cq-inputs-vertical`: Screen 1 job entry inputs
- `.cq-reason-card`, `.cq-word-count`: Screen 3 per-job textarea cards
- `.cq-loading-overlay`, `.cq-spinner`: full-screen loading overlay
- `.cq-analysis-opener-box`, `.cq-job-result-block`, `.cq-job-result-header/reason/ai-label/ai`, `.cq-conclusion-box`: Screen 4 structured result layout (v4.0.0+)
- `.cq-analysis-text`, `.cq-analysis-opener/para/signoff`: legacy single-analysis display
- `.cq-nav-actions`: bottom navigation buttons (badges + course links)
- `.cq-mbti-note`: purple sidebar callout (reserved)

### `assets/ai-junk-jobs.js`

Vanilla JS IIFE. State machine with `step` variable controlling which screen renders:
- `loading` → calls `checkStatus()` → sets `step` and calls `mount()`
- `input` → `renderInput()`: 5 labelled text inputs, submit disabled until all 5 filled
- `rank` → `renderRank()`: drag-and-drop list using Pointer Events API (no library dependency), ghost placeholder, live rank pill updates
- `reasons` → `renderReasons()`: one textarea per ranked job, live word counter with colour feedback (grey / cyan / green), submit disabled until all meet `MIN_WORDS`; on submit shows loading overlay cycling through 8 progress messages at 3.5 s intervals
- `regenerating` → `renderRegenerating()`: auto-triggers fresh analysis for returning students whose summary was not saved
- `results` → `renderResults()`: renders new structured format (intro + per-job blocks + conclusion) or legacy single-analysis fallback

**Text formatters:**
- `formatInline(text)`: converts `**bold**` to `<strong class="cq-analysis-highlight">`, strips remaining asterisks, converts newlines to `<br>`
- `formatAnalysis(text)`: for legacy single-analysis, splits on double newlines, wraps in typed paragraph classes (`cq-analysis-opener`, `cq-analysis-para`, `cq-analysis-signoff`)

---

## Security

- REST endpoints require `is_user_logged_in()` via `permission_callback`.
- WordPress REST API validates the `X-WP-Nonce` header automatically (nonce action `wp_rest`).
- All `$req->get_param()` calls for user content are used directly in JSON encode/DB insert — WordPress REST sanitises input but the plugin also applies `sanitize_text_field()` to the `step` parameter.
- Admin form submissions use `check_admin_referer()` with specific nonce actions.
- Admin reset operations require `manage_options` capability (enforced by the admin menu registration).
- All `$wpdb` queries use `$wpdb->prepare()` or `$wpdb->replace()` with format arrays.
- User ID is always taken from `get_current_user_id()` server-side; the `userId` in `AI_JUNK_JOBS_CFG` is informational only and never used as the authoritative identity for DB writes.

---

## Inter-Plugin Dependencies

| Plugin | Usage |
|--------|-------|
| `mfsd-ordering` (utility plugin) | `mfsd_get_task_status($student_id, 'junk_jobs')` for ordering gate in shortcode; `mfsd_set_task_status()` to advance to `in_progress` and `completed`; `mfsd_ordering_locked_message()` for locked display; `wp_mfsd_task_progress` table for reset. Plugin warns in admin if not active. |
| `stevegtp` / `mwai` | `$GLOBALS['mwai']->simpleTextQuery($prompt)` for all AI calls. Falls back gracefully if null (skips all AI calls; summary fields are empty strings). |

---

## Version History

| Version | Changes |
|---------|---------|
| 4.1.0 | Current. `check_version()` / `maybe_upgrade_table()` live migration pattern. |
| 4.0.0 | Structured 7-segment AI output (intro + 5 job summaries + conclusion) replacing single `analysis` blob. New result screen UI. `save_summary` admin toggle. `needs_regeneration` flag for returning students when save_summary was off. |
| 3.x | Legacy single `analysis` column. Age-based minimum word counts. Drag-to-rank with Pointer Events. |
