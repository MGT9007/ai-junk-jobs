# AI Dream & Junk Jobs — Design & Tech Spec

Version 1.3.2 | 24 October 2026 | Plugin `ai-junk-jobs` **6.0.0 → 7.0.0** | Status: **built (ticket K); for Mark's review**

> **v1.3.1** folds the old §17 ("Changes agreed with Mark, 23 Oct 2026") and Claude Code's review of v1.3.0 into the body. Where §17 and the older text disagreed, §17 won. Changes from v1.3.0:
> - §14 (shared components) is replaced by a pointer: flags, word lists and prompt blocks live in **`mfsd-safeguarding`**; the SteveGPT helpers live in `includes/mfsd-shared-helpers.php`.
> - The AI safeguarding rule moved to the **prompt layer**: every call passes a safeguarding context, and SteveGPT 14 appends the block, strips the markers and raises the AI flag (§5.4). The plugin's own `[CONCERN:]` rule and marker parsing are gone.
> - Three severities: `concern`, `urgent`, `moderation`. "Parents see everything" is no longer true: unreleased moderation items show as "(not shown)" (§7).
> - New status `commit`; the commitment is required (§15). Two badges, both after the commitment (§15.4).
> - Parent guessing game with `wp_mfsd_djj_guesses` (§16). Halfway video (§2.1). Age range 10–14.
> - New columns `actions_json`, `chosen_action`, `commitment_id`, `commitment_text`, `decoys_json` (§4.1). Getter settled (§4.4). Decisions 12–24 added (§13).

Earlier history: v1.0–v1.2 merged Dream Jobs into Junk Jobs (`ai-dream-jobs` retired, §10); v1.3.0 made it Week 2 **Task 1** and added the commitment card and the getter.

---

## 1. Purpose and place in the course

The two halves play off each other: each junk-job reason is **reframed into a requirement** ("boring" → *"I need variety"*), each dream job is **checked against those requirements**, and **personality fit** and **plot twists** sit on the same screen. Everything is stored so Wider Interests, Dream Life and the Weekly RAG can read it (§4.4).

**Week 2 order:** **1. Dream & Junk Jobs** · 2. Who Inspired You Most · 3. Wider Interests · 4. Dream Life · 5. Week 2 RAG. It unlocks once the Week 1 RAG is complete. Life Wheel is out of Week 2.

**Course age range: 10–14.** Minimum words per reason: `mfsd_min_words_for_age()` → 20 (10–11) / 30 (12) / 50 (13) / 100 (14+); unknown age → 30. Fallback age text: "a UK student aged 10-14".

---

## 2. Student journey

Dream half, then junk half, then results, then the commitment. Progress saves between every screen.

| # | Screen | Notes |
|---|---|---|
| 1 | Dream jobs: enter 5 | Prefilled from an old `wp_mfsd_ai_dream_jobs_results` row if one exists |
| 2 | Rank dream jobs | drag (pointer events) |
| 3 | Why? 5 reasons, `minWords` by age | Submitting runs the dream analysis (§5.1) |
| 4 | Halfway card (§2.1) | |
| 5 | Junk jobs: enter 5 | A dream job can't be entered |
| 6 | Rank junk jobs | |
| 7 | Why not? 5 reasons | Submitting runs junk + conclusion (§5.2, §5.3) |
| 8 | Results (§3) with the **commitment panel** open (§15) | status `commit` |
| 9 | Commitment saved → task complete | status `completed` |

Back buttons work within each half. From the junk half you can't go back into the dream half (its AI output exists); editing it means a reset.

### 2.1 Halfway video (optional)
- Admin: **Show halfway video** (checkbox, default off), **Halfway video** (Media Library attachment ID), **Poster image** (optional attachment ID). Media Library, because the Cloudways Deployer wipes plugin folders.
- Config: `halfwayVideo: {url, poster} | null` (null when off or no video).
- Screen: `<video controls playsinline preload="metadata" poster>` between the subtitle and the button, width 100%, max 480px. No autoplay. **"Let's go →" is always visible**; it gets focus when the video ends. Off → the card is unchanged.

---

## 3. Results screen

Three tabs (a full-width segmented control on mobile).

**Tab 1, The Big Picture:**
0. *While `commit`:* banner "**One last step to finish:** pick your one thing to do this week ↓" (tapping scrolls to the panel)
1. Steve says (intro)
2. "You, the …" card (avatar, character name, family), or the unlock card with no profile
3. What you need from a career: requirement chips
4. How your dream jobs stack up: band badge + requirement ticks per dream job
5. Plot twists (if any)
6. Steve's summary (§5.3)
7. **Your one thing** (§15): the picker while `commit`; "Your action: … · On your home screen" with the live card status once `completed`
8. **Parent guesses** (once any parent has played): "Mum guessed 11 of 14 of your jobs. You earned 22 bonus coins!"
9. Jobs people like you often enjoy
10. Nav: View My Badges · Back to Course

**Tab 2, Dream Jobs:** per job: reason, band, helps/stretch/bridge, Steve's text, ticks. Then the careers chat (§7a).

**Tab 3, Junk Jobs:** per job: requirement chip, personality check chip, reason, Steve's text.

No profile → personality parts hidden and Tab 1 shows "Complete Who Am I to unlock your Personality Fit". Who Am I test mode → hidden, admins see a notice.

---

## 4. Data

### 4.1 `wp_mfsd_ai_junk_jobs_results` (one row per student; new columns added by `maybe_upgrade_table()`)

| Column | Type | Content |
|---|---|---|
| `jobs_json`, `ranking_json`, `reasons_json` | LONGTEXT | junk half |
| `dream_items_json`, `dream_ranking_json`, `dream_reasons_json` | LONGTEXT | dream half |
| `dream_summary_1…5`, `intro_text`, `job_summary_1…5`, `conclusion` | LONGTEXT | AI text, markers removed |
| `fit_json` | LONGTEXT | `{job: {fit, helps[], stretch, bridge}}` |
| `requirements_json` | LONGTEXT | `{junk_job: "I need variety"}` |
| `check_json` | LONGTEXT | `{junk_job: agrees\|twist\|neutral}` |
| `match_json` | LONGTEXT | `{dream_job: {met[], missing[]}}` |
| `also_like_json` | LONGTEXT | career chips |
| `parent_ai_summary` | LONGTEXT | cached parent summary |
| `actions_json` | LONGTEXT | **7.0.0** `{skill, explore, habit}` from `[ACTION:]` (§5.3); `{}` if none parsed |
| `chosen_action` | VARCHAR(10) | **7.0.0** `skill\|explore\|habit\|own` |
| `commitment_id` | BIGINT UNSIGNED | **7.0.0** card ID (null when `mfsd-commitments` is inactive) |
| `commitment_text` | VARCHAR(140) | **7.0.0** the child's wording, always saved |
| `decoys_json` | LONGTEXT | **7.0.0** decoy jobs for the guessing game, picked at completion (§16.1) |
| `mbti_type` | CHAR(4) | server-side only, never returned |
| `status` | VARCHAR(20) | §4.2 |

### 4.2 Status machine
```
not_started → dream_in_progress → dream_reasons → dream_done
 → in_progress → reasons → (generate_analysis) → commit → (save_commitment) → completed
```
- **Legacy junk-only row** (`completed`/`in_progress`/`reasons` with no `dream_items_json`): `/status` returns `needs_dream_half`; the student does the dream half, the junk half is skipped, `generate_analysis` re-runs everything and the row goes to `commit` like any other. The ordering status (already `completed`) is never downgraded and its badge is kept.
- **Rows completed under 6.0.0** (with `requirements_json`, no commitment) stay `completed` (Decision 25).
- **Regeneration** ("Save AI summary" off) of a `completed` row keeps `completed`; of a `commit` row keeps `commit`. `actions_json` is refreshed only while `commit`, so a completed child's chosen action never changes under them.

### 4.3 `wp_mfsd_djj_guesses` (new, `dbDelta`)
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `parent_id`, `child_id` | BIGINT UNSIGNED | UNIQUE (`parent_id`,`child_id`) |
| `deck_json` | LONGTEXT | card order shown (job names) |
| `answers_json` | LONGTEXT | `{job: love\|hate\|neither}`, saved per answer |
| `score` | TINYINT UNSIGNED NULL | set at finish |
| `coins_awarded` | SMALLINT UNSIGNED NULL | |
| `created_at` | DATETIME | |
| `completed_at` | DATETIME NULL | |

Parent Portal 5.0.0 reads `parent_id`, `child_id`, `score`, `deck_json` (total = its length) and `completed_at`, and maps status `commit` to step 6 of 7, "Choosing their action". These names are fixed.

### 4.4 `mfsd_get_dream_junk_jobs( int $user_id ): ?array`
Read-only, defined in `ai-junk-jobs.php`; callers guard with `function_exists()`.
- Returns **null unless the status is `completed`** and the row is not legacy. (Decision 14: no partial rows. A student at `commit` hasn't finished, and ordering keeps the next tasks locked until then.)
```php
[
  'status'        => 'completed',
  'dream_ranked'  => ['Architect', 'Vet', …],
  'dream_reasons' => ['Architect' => '…', …],        // withheld items → '(not shown)'
  'fit'           => ['Architect' => 'natural'|'good'|'stretch', …],   // band only
  'twists'        => ['Nurse', …],                   // junk jobs whose check is 'twist'
  'requirements'  => ['I need variety', …],          // unique, junk-rank order
  'junk_ranked'   => ['Accountant', …],
  'junk_reasons'  => ['Accountant' => '…', …],       // withheld items → '(not shown)'
  'actions'       => ['skill' => '…', …] | null,
  'chosen_action' => 'explore'|'own'|null,
  'commitment_text' => '…',
  'completed_at'  => 'Y-m-d H:i:s',                  // completed_at column (set once at completion); updated_at for pre-7.0.0 rows
]
```
- `fit` and `twists` are empty when `mfsd_get_personality_profile()` returns nothing or Who Am I is in test mode.
- Never returns personality codes, `mbti_type`, AI per-job text or flags.
- **Withheld items are redacted** (Decision 15): any item with an unreleased moderation flag has its reason replaced with "(not shown)", so a consumer can't leak it to a parent. The job name stays.

---

## 5. REST + AI

`POST /ai-junk-jobs/v1/submit` steps: `save_dream_items`, `save_dream_ranking`, `back_to_dream_input`, `back_to_dream_rank`, `generate_dream_analysis`, `save_jobs`, `save_ranking`, `back_to_input`, `back_to_rank`, `generate_analysis`, **`save_commitment`** (§15). `GET /status`, `GET /parent-summary`, and the guess routes (§16.4).

- **SteveGPT only.** Unavailable (class missing, mapping empty, record inactive) → no AI output is saved, status unchanged, keyword flags still raised, `{ok:false, error:'steve_unavailable'}` and the "quick break" message. One failed call → that segment's fallback text.
- **`mfsd-safeguarding` inactive** → the shortcode shows the "quick break" message and every route returns 503 (free text can't be checked).
- Mappings (`stevegpt_plugin_integration_slots`, `task_slug` `junk_jobs`): `mfsd_stevegpt_map_junk_jobs_summary`, `…_parent_summary` (audience parent), `…_chat`.
- Calls: dream half 5; junk half 1 intro + 5 junk + 1 conclusion.

### 5.1 Dream job, per job (< 110 words + markers)
Why it suits what they said, 2–3 skills, UK salary range, UK routes. With a profile: `[FIT:natural|good|stretch]`, `[HELPS:a|b]`, `[STRETCH:…]`, `[BRIDGE:…]`.

### 5.2 Junk job, per job (< 80 words + markers)
Acknowledge → one Solutions Mindset reframe → UK facts. `[REQ:I need …]`; with a profile `[CHECK:agrees|twist|neutral]`.

### 5.3 Combined conclusion (< 160 words + markers)
What the two lists reveal; best dream job for requirements and fit; plot twists; band words must match the badges; exactly 3 one-percent actions (skill, explore, habit); sign off "- Steve". Then `[MATCH:{dream job}|{met}|{missing}]` per dream job, then:
```
[ACTION:skill|{action, <120 chars, written as the student's own plan}]
[ACTION:explore|…]
[ACTION:habit|…]
```
Parsing never fails the page: markers are removed before display, unknown requirement names are dropped, a missing `MATCH`/`FIT` just hides that part, and a missing or malformed `ACTION` drops that action. **No action parsed → "Write my own" only.**

### 5.4 Safeguarding prompt layer (replaces the old §14.2 rule)
Every call passes a context through `mfsd_steve_query( $bot, $prompt, $uid, $label, $ctx )` → `$options['safeguarding']`. SteveGPT 14 appends `mfsd_safeguarding_prompt($ctx)` last, strips `[CONCERN:…]`, raises the `ai` flag itself (from `item`, `item_text`, `field`) and exposes `$bot->last_safeguarding_severity()`.

| Call | `kind` | Extra keys |
|---|---|---|
| dream ×5, junk ×5 | `item` | `severity`/`matched` = this item's keyword result; `item` = job; `item_text` = reason; `field` = `dream_reason`/`junk_reason` |
| intro, conclusion | `summary` | (the `[ACTION:]` lines follow the SUGGESTION wording inside the prompt) |
| parent summary | `parent_summary` | `flags` = parent-visible flags |
| careers chat | (chat check) | SteveGPT 14 checks every student chat message under `junk_jobs` |

- **Keyword pre-check** first, in PHP, before any AI call: `mfsd_safeguarding_check()` on each job + reason (raises the keyword flag, `field` `reason`).
- When the reply's severity is not empty, Steve's reply is the gentle safety reply, and FIT/REQ/CHECK are ignored for that item.
- `mfsd_concern_safeguarding_rule()` and the plugin's own marker parsing are removed.
- The conclusion leaves out the reasons of flagged items ("handled separately").

---

## 6. Concern flags
Stored by `mfsd-safeguarding` in `wp_mfsd_concern_flags`, `source_task='junk_jobs'`. Severities `concern < urgent < moderation`. Rules used here:
- **Open** = status is not `closed` (open, seen or talked).
- **Parent-visible** = `mfsd_get_concern_flags( $child, 'junk_jobs' )` (unreleased moderation excluded).
- **Urgent for a parent** = an open parent-visible flag that is `urgent` or a **released** `moderation`. An unreleased moderation flag never shows to a parent.
- **Withheld** = unreleased moderation; the item's words and Steve's reply show as "(not shown)" in every parent-facing output (tab, summary prompt, getter).
- A new flag clears the cached parent summary (`mfsd_concern_flag_raised`).
- Junk-job reasons often mention family; the central word list is reviewed with that in mind.

---

## 7. Parent view
Parents come in through Course Progress (`?student_id=`); `MFSD_Parent_Tabs::assert_linked()` or 403. Parent views skip the ordering gate and never write status.

| Child's status | Parent sees |
|---|---|
| before `completed` (incl. `commit`) | progress only ("Progress: Choosing their action"); urgent concerns above it |
| `completed`, this parent hasn't finished the game | **the game** (§16); no tabs, and no jobs, reasons or summary in the page source or REST. Urgent concerns ("Something to be aware of") above the game |
| `completed`, played | **{Child}'s view** (the results, read-only, withheld items "(not shown)") and **Parent view** (Steve's summary) |

**Parent summary** (generated only when `completed`; cached in `parent_ai_summary`). Sections in order:
- `[SECTION:Something to be aware of]` (first, only when the safeguarding block lists parent-visible flags)
- `[SECTION:What their choices tell you]`
- `[SECTION:How their personality fits]` (profile only; character name, never a code)
- `[SECTION:Conversation starters]`
- `[SECTION:Their commitment]`: the action in the child's words and its kind; why this small step matters (link to #1 dream job and requirements); 1–2 low-cost ways to help; "When you see it happen, confirm it on your home screen. That's when {child} earns their coins."
- `[SECTION:How you can help]`
- final line `PARENT_NOTE: …|none` → `mfsd_concern_save_parent_note()`.

If the AI fails and there are flags, `MFSD_Parent_Tabs::concern_fallback_html()` lists them, so a concern is never lost.

## 7a. Careers chat
SteveGPT widget on the Dream Jobs tab, only once `completed` (status `commit` hides it). Rendered hidden into `#ai-junk-jobs-chat-source` and **moved** into the tab. Context: dream jobs with bands, requirements, junk jobs, character name + strengths, "a UK student aged {age|10-14}", 2–4 sentence answers; never a code. Starter chips: GCSEs for #1, getting closer to #1 this year, which pays most. No mapping → hidden. Not shown to parents. SteveGPT 14 runs the safeguarding chat check on every message (`junk_jobs`).

---

## 8. Admin
- Settings: Course Management; Save AI Summary ("all 13 AI segments"); Safeguarding words (link to MFSD Safeguarding); **Show halfway video**, **Halfway video**, **Poster image** (Media Library pickers); **Decoy jobs** (one per line, defaults); **Coins per correct guess** (default 2); **Dream Jobs badge coins** (default 10).
- Student records: Half, Status, Summary saved, Parent summary, **Action**, **Guesses** (parent · score · coins), Ordering status.
- Regenerate parent summary.
- **Reset student:** deletes the row and the task progress; deletes their guesses and reverses each `djj_guess_{parent_id}` wallet source; moves an open card from this task to `dropped` (a validated card stays as history). Flags are kept. **`badge_dream_jobs` and its coins stay** (Quest Log 3.0.0 has no revoke helper; Decision 22); on completing again the badge isn't paid twice.

---

## 9. Identity & versions
| Item | Value |
|---|---|
| Folder / file | `ai-junk-jobs/ai-junk-jobs.php` (not renamed) |
| Plugin Name | AI Dream & Junk Jobs |
| Version | **7.0.0** |
| task_slug | `junk_jobs` |
| Shortcodes | `[ai_junk_jobs]`, `[ai_dream_junk_jobs]` |
| REST | `ai-junk-jobs/v1` |
| Requires | `mfsd-safeguarding` 1.0+, SteveGPT 14.0.1+; optional `mfsd-commitments` 2.0.0, Quest Log 3.0.0, `mfsd-ordering` |

## 10. Retiring `ai-dream-jobs`
Task Order `dream_jobs` row `active = 0`; replace its page shortcode with `[ai_junk_jobs]`; keep the plugin until the prefill is used; never drop `wp_mfsd_ai_dream_jobs_results`.

## 11. Manual / data changes
- Task Order Week 2: Dream & Junk Jobs 1, Who Inspired 2 (inactive), Wider Interests 3, Dream Life 4, Week 2 RAG 5; `life_wheel` and `dream_jobs` inactive.
- SteveGPT mappings set; those chatbots have **Content aware off**.
- Upload the halfway video and poster to the Media Library if wanted.
- Quest Log artwork for `badge_dream_jobs` and `badge_junk_jobs`.

---

## 12. Testing
1. **SteveGPT down / safeguarding inactive:** quick-break message, nothing saved, status unchanged.
2. **New student:** full flow; halfway card (video on and off; button focus on end); pause/resume at every status; junk job matching a dream job rejected.
3. **Legacy row:** dream half → combined results → `commit`; ordering status stays completed.
4. **Ages 10/12/13/14:** 20/30/50/100 words; no "11-14" anywhere.
5. **Profile / none / test mode.** Malformed AI output still renders.
6. **Safeguarding:** every `query()` carries a context (SteveGPT debug log shows no warnings); keyword hit raises a `keyword` flag before the AI call; an AI marker gives the gentle reply and no FIT/REQ.
7. **Commitment:** `generate_analysis` → `commit`, ordering still `in_progress`, no badge. Pick / edit / Write my own (140 chars) → card created (`source_task junk_jobs`, `source_ref djj:{kind}`), `completed`, ordering completed, `badge_dream_jobs` + coins once, `badge_junk_jobs` by Quest Log. No `[ACTION:]` → Write my own only. Commitments inactive → text saved, task completes, text keyword-checked by this plugin. A second `save_commitment` changes nothing.
8. **Parent gate:** before completed → progress only; completed → game, no jobs/summary in source or REST (`/status`, `/parent-summary`); urgent / released moderation above the game; unreleased moderation never shown.
9. **Game:** 14 cards (5+5+4), flagged items swapped for decoys; swipe, buttons, keys and undo; reload resumes; finish pays `correct × coins` once (`djj_guess_{parent}`); second parent plays separately; reveal then tabs unlock; child sees "{Parent} guessed n of 14".
10. **Card hold:** the junk_jobs card is hidden from a parent's widget until that parent's `completed_at` is set.
11. **Getter:** null before completed; shape §4.4; no code; withheld reasons "(not shown)".
12. **Reset:** guesses gone, wallet entries reversed, open card dropped, flags kept.
13. 360px, touch only, 44px targets, keyboard, `prefers-reduced-motion`. No 4-letter code, "MBTI" or "Myers" in source or REST. debug.log clean.

---

## 13. Decisions log
| # | Question | Decision |
|---|---|---|
| 1 | Jobs per side | 5 dream + 5 junk, full word counts |
| 2 | Order | Dream half, junk half, combined results |
| 3 | Existing Junk Jobs completers | Keep `junk_jobs`; legacy rows resume at the dream half; badge kept |
| 4 | AI output | Per-job replies + combined summary |
| 5 | Plugin to extend | `ai-junk-jobs`; `ai-dream-jobs` retired |
| 6 | Career chat | SteveGPT chat on the Dream Jobs tab |
| 7 | Flags table | Central, now in `mfsd-safeguarding` |
| 8 | Week 2 position | Task 1 (Mark, 23 Oct) |
| 9 | Commitment | Child picks one of three actions or writes their own; **required**, no "Not now" (Mark, 23 Oct) |
| 10 | Card review | Week 2 RAG |
| 11 | `signal` trigger | In `mfsd-safeguarding` |
| 12 | Age range | 10–14; 20 words for 10–11 |
| 13 | Badges | `badge_dream_jobs` by this plugin on `save_commitment` (coins setting, default 10); `badge_junk_jobs` by Quest Log on completion; both wait for the commitment |
| 14 | Getter | `completed` rows only, else null (no partial) |
| 15 | Getter and withheld items | Redacted to "(not shown)" in the getter itself |
| 16 | Commitments inactive | Student still writes the action; saved to `commitment_text`; task completes; text keyword-checked here (`field commitment`) |
| 17 | Who checks the card text | `mfsd-commitments` when active; this plugin only when it's inactive |
| 18 | Card limits | None |
| 19 | Card hold | `mfsd_commitment_visible_to_parent` returns false for a `junk_jobs` card until **that** parent's guess row has `completed_at`; the 72 h / 48 h auto-confirm is handled centrally by Commitments 2.0.0 |
| 20 | Guessing game | 14 cards (5/5/4 decoys from the admin list, stored at completion); shuffled per parent; love/hate/neither with buttons, swipe, undo; 2 coins per correct (setting), once per parent via `has_source('djj_guess_{parent}')`; no #1 bonus |
| 21 | Flagged items in the deck | Any open flag (incl. unreleased moderation, read with `include_withheld`) → left out and replaced by extra decoys, so the deck stays 14 |
| 22 | Reset and badges | Guesses cleared, `reverse_source()` per parent; `badge_dream_jobs` kept (no revoke helper) |
| 23 | Urgent above the game | Open `urgent` or released `moderation`; unreleased never shows |
| 24 | AI safeguarding | Prompt layer through SteveGPT 14 (§5.4); plugin keeps only the keyword pre-check |
| 25 | Students who completed under 6.0.0 (no commitment) | **Stay `completed`** (never demoted). Their results show the commitment picker, optional for them; saving it creates the card and awards `badge_dream_jobs`. Decoys are picked on a parent's first game. ❓ Mark to confirm |

---

## 14. Shared components
Moved out of this spec:
- **Flags, word lists, prompt blocks, moderation release:** `MFSD_SAFEGUARDING_TECH_SPEC_v1_1_0.md` and `MFSD_SAFEGUARDING_PROMPT_LAYER_v1_0_0.md` (plugin `mfsd-safeguarding`).
- **SteveGPT helpers, student age, minimum words:** `includes/mfsd-shared-helpers.php` (`function_exists`-guarded copies in each task plugin).
- **Parent tabs:** `includes/class-mfsd-parent-tabs.php` (unchanged copy).

---

## 15. Commitment (required)

### 15.1 Screen
On Tab 1 while `commit`: the three actions as tappable cards (**Skill**, **Explore**, **Habit**, Steve's wording) plus **Write my own**. Picking one opens an edit box (140 characters, counter) prefilled with Steve's text. Button: **Add to my home screen and finish**. No skip. Tabs 2 and 3 stay readable.

### 15.2 `save_commitment` (`kind` = skill|explore|habit|own, `text`)
Only from `commit` (a repeat on a `completed` row returns the saved result and does nothing). Text 1–140 characters; an unknown kind or a kind with no parsed action (other than `own`) is refused.
1. With `mfsd_create_commitment()`:
   ```php
   mfsd_create_commitment( $uid, [ 'type' => 'action', 'source_task' => 'junk_jobs', 'source_ref' => 'djj:' . $kind,
       'title' => $text, 'detail' => $steve !== '' && $steve !== $text ? 'Steve suggested: ' . $steve : '' ] );
   ```
   A `WP_Error` is returned to the child and nothing else changes. Commitments checks the text itself.
   Without it: `mfsd_safeguarding_check( $uid, 'junk_jobs', 'Commitment', $text, ['field' => 'commitment'] )`.
2. Save `chosen_action`, `commitment_id`, `commitment_text`.
3. Pick the decoys (§16.1) into `decoys_json`.
4. `status = 'completed'`; `mfsd_set_task_status( $uid, 'junk_jobs', 'completed' )` (Quest Log then awards `badge_junk_jobs`).
5. Award `badge_dream_jobs` (§15.4).
Returns the full status payload.

### 15.3 Registration
- `mfsd_commitment_sources`: `junk_jobs` → label "Dream & Junk Jobs", kind "Action", colour `#9333EA`, icon ⚒️, url `/my-future-self-foundation-course/week-2/junk-jobs/`.
- `mfsd_commitment_visible_to_parent` (10, 3): for a `source_task='junk_jobs'` card, true only once that parent has a `wp_mfsd_djj_guesses` row for the card's child with `completed_at` set.
- `mfsd_quest_task_extra_badges`: `'junk_jobs' => ['badge_dream_jobs']`.

### 15.4 Badge
`class_exists( 'MFSD_Quest_Log_DB' ) && class_exists( 'MFSD_Quest_Log_Wallet' )`: `( new MFSD_Quest_Log_DB )->award_badge( $uid, 'badge_dream_jobs', $coins, ['source' => 'junk_jobs'] )`; when newly awarded and `! has_source( $uid, 'badge_dream_jobs' )`, `( new MFSD_Quest_Log_Wallet )->earn( $uid, 'badge_dream_jobs', $coins, 'Dream Jobs badge earned' )`. Card coins are separate (paid when a parent validates the card).

---

## 16. Parent guessing game: "Love it, hate it or neither?"

### 16.1 Deck
- Decoys are picked at completion from the admin list: shuffled, not matching any of the child's 10 (case-insensitive), **up to 14 stored** in `decoys_json` (4 used, the rest spares).
- A parent's deck is built on their first `GET /guess`: the 10 jobs minus any with an open flag, plus as many decoys as keep the deck at 14 (fewer only if the list runs out), shuffled, stored in `deck_json`. A reload keeps it.
- **Truth:** dream → love, junk → hate, decoy → neither. Never sent to the browser before finish.

### 16.2 Screen
One card at a time: job in large text, "5 / 14", "Would {child} **love** this job, **hate** it, or is it **neither**?". **Love it** (swipe right / → key), **Hate it** (swipe left / ← key), **Neither** (swipe up / ↑ key); buttons always visible (≥ 44px); **Undo**. Answers save as they go. `prefers-reduced-motion` turns off the fling.

### 16.3 Finish and reveal
Score = correct answers. Coins = score × "Coins per correct guess", paid once per parent: `if ( ! $wallet->has_source( $child, 'djj_guess_' . $parent ) ) $wallet->earn( $child, 'djj_guess_' . $parent, $coins, '{Parent first name} guessed {n} of {total} of your jobs' )` (0 coins → no transaction). Reveal: "You got 11 of 14!", every card with their answer and the truth, surprise callouts ("You thought {child} would hate Accountant… it's their #3 dream job!"), then **See {child}'s results** reloads into the unlocked tabs. Each linked parent plays separately; the child earns from each.

### 16.4 REST (linked parents only; child must be `completed`, not legacy)
- `GET /guess?student_id=` → `{deck:[…], answers:{…}, done:bool, child_name}`; when done also the reveal (`cards:[{job, answer, truth, rank}]`, `score`, `total`, `coins`).
- `POST /guess/answer` `{student_id, job, answer}` (answer `love|hate|neither|''` — empty removes it, for undo). Refused once finished.
- `POST /guess/finish` `{student_id}` → reveal. Needs every card answered. Idempotent.
- `/status` and `/parent-summary` return `{gated:true, status}` and nothing else to a parent who hasn't finished.

---

## 17. Consumers and knock-on fixes
| Consumer | Needs |
|---|---|
| Wider Interests, Dream Life | `mfsd_get_dream_junk_jobs()` (null until `completed`; reasons may be "(not shown)") |
| Weekly RAG | switch `api_summary()` from the retired `ai-dream-jobs` table to `mfsd_get_dream_junk_jobs()['dream_ranked']` |
| Parent Portal 5.0.0 | reads `wp_mfsd_djj_guesses` and status `commit` (§4.3) — no change needed |
| Commitments 2.0.0 | the visibility filter and source registration (§15.3) |
| Quest Log | **3.0.1:** rename `badge_junk_jobs` display name to "Junk Jobs" (`class-quest-log-engine.php`, currently "Dream & Junk Jobs"); a badge-revoke helper if resets must remove `badge_dream_jobs` |

---

AI Dream & Junk Jobs Tech Spec v1.3.2 | 24 October 2026

---

## 18. v1.3.2: fixes from the Claude Code code review (24 Oct 2026)

Plugin stays at **7.0.0** (not committed yet).

- **B1: commitment text held back from parents.**
  - New `commitment_withheld()` checks both this task's `Commitment` item and the card's own flag (`'commitments'`, card id). Commitments checks card text under that source.
  - It's used in the parent's "{Child}'s view" (`commitment_text` and the card title), in the parent-summary prompt, and in the getter.
  - A `'commitments'` flag now also clears the cached parent summary.
  - `parent_flags()` also includes flags that aren't closed on this task's card (source `'commitments'`), for the tab dot, the urgent bypass and "Something to be aware of".
- **Guess coins race.**
  - `handle_guess_finish` now claims the row first, setting `completed_at` only `WHERE completed_at IS NULL`.
  - Only the request that claims it pays, then records `coins_awarded`. A double submit pays nothing.
- **Answers race.** `handle_guess_answer` re-reads and writes `answers_json` inside a MySQL `GET_LOCK`, so two quick taps can't overwrite each other's answer.
- **Getter `completed_at`.**
  - New `completed_at` column on the results table, set once when the commitment completes the task.
  - The getter uses it, and falls back to `updated_at` for rows finished before 7.0.0.
- **Admin reset with a checked-in card.** A `checked_in` card can't go straight to `dropped`, so reset moves it to `not_yet` first, then drops it. Validated cards stay as history.
- **Re-review fixes:**
  - **N1.** `on_flag_raised` had `return;` stuck inside a comment. Fixed: it now clears the parent summary only for this task's flags, or for a `'commitments'` flag whose `source_ref` is this task's card.
  - **Guess answers.** The JS now saves answers one at a time, in order, and retries up to 3 times (the server's lock can answer 409 "Busy"). "See how I did" waits for the queued saves. If the server is still missing an answer, it re-sends them all once before finishing.
