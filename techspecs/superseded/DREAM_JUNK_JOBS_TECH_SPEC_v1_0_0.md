# AI Dream & Junk Jobs — Design & Tech Spec

Version 1.0.0 | 29 September 2026 | Status: **Decisions confirmed by Mark (Section 13)**

> Extends `ai-junk-jobs` v4.1.0 → **v5.0.0** into one combined task: **Dream & Junk Jobs**, Foundation Course **Week 2, Task 3**. This matches Steve's course update ("Task 3: Dream Jobs and Junk Jobs").
> `ai-dream-jobs` v6.0.1 is **retired** (Section 10).
> Includes Personality Fit (from PERSONALITY_FIT_TECH_SPEC v1.1.1, whose Section 3 helper is still required), the parent-tab rule, and concern flags.

---

## 1. Why combine them

The two halves play off each other, and the combined results screen is where the task pays off:
- Each junk job's reason is **reframed into a requirement** ("boring" → *"I need variety"*). Each dream job is then **checked against those requirements**: *"Journalist ticks 3 of your 4."*
- **Personality fit** on the dream jobs and **plot twists** on the junk jobs appear on the same screen: *"You avoided Nurse, but it uses the same strengths as your top dream job."*
- The **Dream Life values** (Task 2) run through both halves.

### Week 2 order (updated)
1. Life Wheel · 2. Dream Life · **3. Dream & Junk Jobs** · 4. Who Inspired You Most · 5. Happy now v improve / Future Self target setting / Wider interests · 6. Week 2 RAG

### Core objective
To turn "what I want" and "what I don't want" into one evidence-based picture: what matters to me in a career, which of my dream jobs give me that, how my personality fits, and my next small steps.

---

## 2. Student journey

Dream half first, then the Junk half, then one combined results screen. Progress **saves between every screen**, so a student can stop after the dream half and come back.

| # | Screen | Reuses | New |
|---|---|---|---|
| 1 | **Dream jobs**: enter 5 | Junk Jobs Screen 1 | Dream copy, placeholder *e.g. Vet, Game Designer, Footballer…* |
| 2 | **Rank dream jobs** (most wanted at top) | `enableDnD()` | |
| 3 | **Why do you want it?** 5 reasons, `minWords` by age | Junk Jobs Screen 3 | Submitting runs the **dream analysis** (Section 5.1) |
| 4 | **Halfway card**: *"Nice one, Steve's got your dream jobs. Now the fun bit: jobs you'd hate!"* | | Short transition screen, not a results screen |
| 5 | **Junk jobs**: enter 5 | Junk Jobs Screen 1 | Blocks entering any of their dream jobs |
| 6 | **Rank junk jobs** (most avoided at top) | as today | |
| 7 | **Why not?** 5 reasons | as today | Submitting runs the **junk + summary analysis** (5.2, 5.3) |
| 8 | **Results** (Section 3) | | New layout |

Word count: students write 10 reasons at 30 / 50 / 100 words, so a 14-year-old writes up to 1,000 words. That's accepted (Decision 1). The counters and the halfway card are there to keep the pace up.

Back buttons: work within each half as today. From the junk half you can go back to the halfway card, but **not** into the dream half, because its AI output is already generated. Editing the dream half means a reset.

**Prefill:** if the student has an old `wp_mfsd_ai_dream_jobs_results` row, prefill Screen 1 with those jobs and Screen 2 with that order. It has no reasons, so they still write them.

---

## 3. Results screen (Screen 8)

Three tabs. On mobile they're a full-width segmented control.

### Tab 1: The Big Picture (default)
1. **Steve says:** intro
2. **"You, the …" card**: avatar + character name + family, if there's a personality profile
3. **What you need from a career**: 3–5 requirement chips taken from the junk-job reframes, plus Dream Life value chips (styled differently, labelled *"from your Dream Life"*)
4. **How your dream jobs stack up**: one row per dream job, in ranked order:
   `#1 Journalist   🟢 Natural fit   ✓ variety ✓ people ✓ creativity ✗ security   3 of 4`
   On mobile each row wraps onto two lines: name + band, then ticks.
5. **Plot twists** (only if any): *"You avoided Nurse, but it uses your caring and organised strengths. Is it the job, or the idea of it?"*
6. **Steve's summary**: the combined conclusion (5.3), with 3 micro-actions and "— Steve"
7. **Jobs people like you often enjoy**: 2–3 chips from the personality `careers` list, not already in either of their lists
8. Nav: **View My Badges** · **Back to Course**

### Tab 2: Dream Jobs
One card per dream job, each showing:
- rank + name
- their reason (quoted)
- **fit band** badge (🟢 Natural fit / 🔵 Good fit + stretch / 🟣 Stretch zone)
- ✅ What helps you / 🌱 Your stretch / 🌉 Bridge it
- Steve's per-job text (skills, UK salary, routes, outlook)
- requirement ticks

Below the cards is **"Ask Steve about your dream jobs"**, the careers chat (Section 7a).

### Tab 3: Junk Jobs
One card per junk job, as today, plus:
- a **requirement chip** (the reframe)
- a **personality check** chip (🟢 *Your personality agrees* / 🟡 *Plot twist!*)

No profile → the personality parts are hidden, and Tab 1 shows the *"Complete Who Am I to unlock your Personality Fit"* card. Test mode → the personality parts are hidden and admins see a notice (per the helper rules).

---

## 4. Data

### 4.1 Table `wp_mfsd_ai_junk_jobs_results` (same table, new columns through `maybe_upgrade_table()`)
| Column | Type | Content |
|---|---|---|
| `dream_items_json` | LONGTEXT | 5 dream jobs, entry order |
| `dream_ranking_json` | LONGTEXT | ranked |
| `dream_reasons_json` | LONGTEXT | `{job: reason}` |
| `dream_summary_1` … `_5` | LONGTEXT | per-job AI text (markers removed) |
| `fit_json` | LONGTEXT | `{job: {fit, helps[], stretch, bridge}}` |
| `requirements_json` | LONGTEXT | `{junk_job: "I need variety"}` |
| `check_json` | LONGTEXT | `{junk_job: agrees|twist|neutral}` |
| `match_json` | LONGTEXT | `{dream_job: {met[], missing[]}}` |
| `also_like_json` | LONGTEXT | career chips |
| `values_json` | LONGTEXT | snapshot of the Dream Life values used |
| `parent_ai_summary` | LONGTEXT | cached parent view (Section 7) |

Existing columns are kept: `jobs_json`, `ranking_json` and `reasons_json` (now the **junk** half), `intro_text`, `job_summary_1–5`, `conclusion`, `mbti_type`, `status`.

### 4.2 Status machine
```
not_started
 → dream_in_progress   (dream jobs saved)
 → dream_reasons       (dream ranking saved)
 → dream_done          (dream reasons + dream analysis saved)
 → in_progress         (junk jobs saved)     ← existing value, same meaning
 → reasons             (junk ranking saved)  ← existing
 → completed           (junk analysis + combined summary saved) ← existing
```
**Existing students (Decision 3):** a row that's `completed` (or `in_progress` / `reasons`) but has **no `dream_items_json`** is a legacy junk-only row.
- `/status` returns `needs_dream_half: true`, and the student starts at Screen 1.
- Their junk data is kept. After the dream analysis, the junk half is **skipped**: the flow goes straight to `generate_analysis`, which re-runs the combined summary (the junk per-job texts are regenerated too, to add the requirement and check markers) and shows the results.
- Their course status (`mfsd_task_progress`) stays `completed` and the badge stays. `mfsd-ordering` never downgrades a status.

### 4.3 Concern flags: shared table (**changed from Dream Life v1.2.1**)
Both Dream Life and this plugin write to **one** table, **`wp_mfsd_concern_flags`**, instead of per-plugin tables. That's what the Concerns widget will read. The columns are the same as Dream Life spec 4.2, and `source_task` = `junk_jobs` here. Each plugin's activation runs the same `dbDelta()` (safe to repeat). The Dream Life spec is updated to match (v1.2.2).

---

## 5. REST + AI

`POST /ai-junk-jobs/v1/submit` gets these new steps:

| step | Does |
|---|---|
| `save_dream_items` | 5 dream jobs, unique, sanitised → `dream_in_progress` |
| `save_dream_ranking` | → `dream_reasons` |
| `back_to_dream_input` | clears dream ranking/reasons → `dream_in_progress` |
| `generate_dream_analysis` | checks reasons ≥ `minWords` on the server, runs 5.1 → `dream_done` (or legacy row: → straight to `generate_analysis`) |
| `save_jobs`, `save_ranking`, `back_to_input`, `back_to_rank`, `generate_analysis` | existing junk steps. `save_jobs` rejects any job already in the dream list. `generate_analysis` now also runs 5.3 |

Engine: **SteveGPT with AI Engine fallback**, the same as Dream Life. It uses the mappings `mfsd_stevegpt_map_junk_jobs_summary` and `mfsd_stevegpt_map_junk_jobs_parent_summary`, falling back to `$GLOBALS['mwai']`. Each call has its own try/catch and fallback text.

AI calls are **split across the two halves**, so no single wait gets too long:
- End of dream half: **5 calls** (~15–20 s)
- End of junk half: **1 intro + 5 junk + 1 conclusion = 7 calls** (~25 s)

Both use the existing rotating-message overlay.

Profile context comes from `mfsd_get_personality_profile()`. Values come from `wp_mfsd_ai_dream_life_results.values_json`, if the student completed it. Age comes from **`pp_dob`** with a `dob` fallback, **fixing the `date_of_birth` bug**.

### 5.1 Dream job, per job (< 110 words + markers)
The current Dream Jobs prompt (Solutions Mindset principles, speaking to the student as "you", UK careers facts) is reworked into a per-job prompt:
```
{name} ranked "{job}" as their #{n} dream job. Their reason: "{reason}"
{profile context, if any}
Write 3-4 flowing sentences: why it suits what they said, 2-3 key skills,
typical UK salary range (entry -> experienced), main UK routes (GCSEs/A-levels/T Levels/apprenticeships).
{if profile} Then output on separate lines:
[FIT:natural|good|stretch]
[HELPS:strength one|strength two]
[STRETCH:one part that may not come naturally]
[BRIDGE:one practical strategy or stepping-stone]
Rules: bands are about personality only; stretch always has a bridge; 'slight' axes
can't be the only reason for stretch; never use personality codes, 'MBTI' or 'Myers-Briggs'.
Safeguarding marker as Dream Life: final line [CONCERN:no|yes|urgent].
```

### 5.2 Junk job, per job (existing prompt, extended)
Keep the v4.1 prompt (acknowledge → reframe → UK facts) and add:
```
Output on separate lines:
[REQ:the positive requirement in 2-5 words, starting "I need"]
{if profile} [CHECK:agrees|twist|neutral] — a twist is when the job actually uses their strengths
{if a dream job shares strengths with this junk job} mention it in the twist sentence.
[CONCERN:no|yes|urgent]
```

### 5.3 Combined conclusion (< 160 words + markers)
Inputs:
- all 5 dream jobs with their fit bands and reasons
- all 5 junk jobs with their requirements and checks
- Dream Life values
- the profile

Instructions:
```
1) Say what their two lists reveal together about what they need from a career.
2) Name the dream job that best meets their requirements AND is the most natural fit (if the same, celebrate it; if different, explain the trade-off kindly).
3) If there are plot twists, one sentence on how assumptions about jobs aren't always right.
4) Band words used must match the [FIT:] tags exactly.
5) Exactly 3 one-percent actions this week: one skill, one explore, one habit.
6) Sign off "- Steve".
Then for each dream job output one line:
[MATCH:{dream job}|{requirements met, comma-separated}|{requirements missing, comma-separated}]
```
PHP parsing:
- Removes every marker before display.
- Matches requirement names case-insensitively against `requirements_json`, and drops anything unknown.
- A missing `MATCH` line means that job shows no ticks.
- A missing `FIT` means no band badge.
- The page never fails because of a parse problem.

---

## 6. Concern flags
These use the same rules as Dream Life (keyword pre-check with urgent/concern lists, plus the AI `[CONCERN:]` marker, and the gentle reply to the child on an AI marker), applied to **all 10 reasons**. Flags go into `wp_mfsd_concern_flags` with `source_task='junk_jobs'`. There's no email yet: that comes with the Concerns widget.

Worth knowing: junk-job reasons often mention family (*"my dad does that and he's always angry"*). The keyword list should be reviewed with that in mind.

---

## 7. Parent view (platform rule)
A parent who comes in through Course Progress (`?student_id=`, linked-parent check as in Dream Life 7.1–7.2) sees:

| Tab | Content |
|---|---|
| **{Child}'s view** | The child's whole results screen (all 3 tabs), read-only, including flagged entries |
| **Parent view** | Steve's adult assessment, cached in `parent_ai_summary` and regenerated if a new flag is raised |

Parent prompt sections:
- `[SECTION:Something to be aware of]` (only if there are flags, and always first)
- `[SECTION:What their choices tell you]`
- `[SECTION:How their personality fits]`, using the character name and bands, never the code
- `[SECTION:Conversation starters]`
- `[SECTION:How you can help]`: work experience, people to talk to, taster days, all low-cost

The tab code is copied from `includes/class-dream-life-parent.php` into `includes/class-junk-jobs-parent.php`.

**Parent Portal:** `class-parent-portal-data.php::get_junk_jobs_status()` and `render_junk_jobs_results()` (renderer line 619) show the old junk-only data today. Update them to show the combined status and link to the parent tabs, rather than rendering results themselves.

---

## 7a. Careers chat ("Ask Steve about your dream jobs")

> **Platform rule (Mark, 29 Sep 2026):** every chat on the platform must be a **SteveGPT chatbot** (`[stevegpt_chatbot]` + a `mfsd_stevegpt_map_*` mapping). No new `[mwai_chatbot]` embeds and no custom chat REST endpoints.
>
> Still on old AI Engine chatbots (separate migration tickets):
> - `life-wheel` (`chatbot-vxk8pu`, line 143). This is Week 2 Task 1, so migrate it **first**.
> - `high-performance-wheel` (`chatbot-tmhwsa`, line 86)
> - `ai-dream-jobs` (`chatbot-vxk8pu`), which is being retired anyway (Section 10)

This replaces Dream Jobs' old `career-chat` endpoint and `mwai` chatbot. It uses the **SteveGPT chatbot widget**, as in Who Am I and Word Association.

- **Where:** the bottom of the **Dream Jobs** tab (Screen 8, Tab 2), after the job cards. It only shows once the status is `completed`.
- **How:** the shortcode renders `[stevegpt_chatbot id="{get_option('mfsd_stevegpt_map_junk_jobs_chat')}" context="{context}"]` into a hidden `#ai-junk-jobs-chat-source` div. The JS moves it into the tab, the same way the Who Am I chatbot is placed.
- **Context string** (built in PHP; `"`, `[`, `]` and newlines stripped, as in Personality Test):
  ```
  Student context for Dream & Junk Jobs. Dream jobs in order: {1..5} with fit bands ({job}: natural fit ...).
  What they need from a career: {requirements}. Jobs they want to avoid: {junk 1..5}.
  Dream Life values: {values}. Personality: {character name} ({family} family) — strengths: {strengths}.
  Answer career questions for a UK student aged {age}. Relate answers to their dream jobs and needs.
  Keep answers to 2-4 sentences. Never use personality codes, 'MBTI' or 'Myers-Briggs'.
  ```
  The type code is **never** included. If there's no profile, or Who Am I is in test mode, the personality part is left out.
- **Starter chips** above the input (tap to send):
  - *"What GCSEs do I need for {#1 dream job}?"*
  - *"What could I do this year to get closer to {#1}?"*
  - *"Which of my dream jobs pays the most?"*
- **No mapping configured:** the chat section is hidden and the rest of the page works.
- **Parent view:** the chat isn't shown in either parent tab in v1. A parent version (with its own mapping, like Word Association's `wa_parent_chat`) is a later option.
- **Safeguarding:** chat messages rely on SteveGPT's own safeguarding handling. They aren't checked against the concern keywords in v1. When the Concerns widget is built, chat can write to `wp_mfsd_concern_flags` as well.

---

## 8. Admin (existing page, extended)
- Menu label becomes **Dream & Junk Jobs**.
- Student records gain a **Half** column (Dream / Junk / Done / Legacy – needs dream half).
- The "Save AI summary" description changes to *"all 13 AI segments"*.
- Reset clears **both halves** and task progress, but keeps flags.
- New: **Regenerate parent summary** per student.

---

## 9. Identity & versions
| Item | Value |
|---|---|
| Folder / main file | `ai-junk-jobs/ai-junk-jobs.php` (**folder not renamed**, because renaming it would deactivate the plugin) |
| Plugin Name header | `AI Dream & Junk Jobs` |
| Version | **4.1.0 → 5.0.0** |
| task_slug | **`junk_jobs`** (kept) |
| Shortcode | `[ai_junk_jobs]` kept, plus the alias `[ai_dream_junk_jobs]` |
| Quest Log | `badge_junk_jobs` stays. Task Order `display_name` → "Dream & Junk Jobs". `badge_description` updated in `class-quest-log-engine.php` (Minor bump) |

---

## 10. Retiring `ai-dream-jobs`
1. Task Order: set the `dream_jobs` row **`active = 0`**. That takes it out of the Week 2 badge count, so the week chests don't need it.
2. Replace its page's shortcode with `[ai_junk_jobs]`, or redirect the page.
3. Keep the plugin active until the prefill (Section 2) has been used, then deactivate it. **Don't drop** `wp_mfsd_ai_dream_jobs_results`.
4. Its `career-chat` REST endpoint and the `mwai` chatbot `chatbot-vxk8pu` are replaced by the SteveGPT careers chat (Section 7a).

---

## 11. Manual / data changes
- Task Order, Week 2:
  - Life Wheel 1, Dream Life 2, **Dream & Junk Jobs 3**, then Who Inspired, etc.
  - `dream_jobs` inactive
- Set up the three SteveGPT mappings: `junk_jobs_summary`, `junk_jobs_parent_summary`, `junk_jobs_chat`.
- Update the Course Design sheet: merge rows 8 and 10 into this task.
- Update the Quest Log tech spec's Week 2 table.

---

## 12. Testing
- **New student:** full flow. The halfway card shows. Pause and resume at every status. A junk job matching a dream job is rejected.
- **Legacy junk-only student:** starts at the dream half, junk data is kept, the junk half is skipped, the combined results show, and the badge isn't lost.
- **Old Dream Jobs data:** the inputs and ranking are prefilled.
- **Ages 12 / 13 / 14:** minimum words are 30 / 50 / 100 (the DOB fix).
- **Profile / no profile / test mode:** personality parts show, show the unlock card, or are hidden with an admin notice.
- **Parsing:** feed malformed AI output (missing `FIT`, `REQ` or `MATCH`). The page still renders.
- The ticks shown match `requirements_json`.
- Band words in the summary match the badges (check 5+ test students).
- **Flags:** flags written to `wp_mfsd_concern_flags` with `source_task='junk_jobs'`, and the parent view leads with the concern.
- **Careers chat:** shows only once completed; its context includes dream jobs, bands, requirements and values; no 4-letter code in the rendered context attribute; hidden when there's no mapping; starter chips send correctly; usable at 360px.
- **Parent:** linked parent sees both tabs, an unlinked parent gets 403, and the Parent Portal card links correctly.
- **No codes leak:** grep for the 4-letter code, "MBTI" and "Myers" in the page source and REST responses.
- **Mobile at 360px:**
  - the tabs fit
  - the stack-up rows wrap cleanly
  - ten reason cards scroll smoothly
  - the overlay is readable
- **debug.log:** zero new entries.

---

## 13. Decisions log
| # | Question | Decision |
|---|---|---|
| 1 | Jobs per side | **5 dream + 5 junk** (full word counts) |
| 2 | Order inside the task | **Dream half, then Junk half**, then combined results |
| 3 | Existing Junk Jobs completers | **Keep `junk_jobs` slug**; legacy rows resume at the dream half; badge kept |
| 4 | AI output | **Per-job replies + combined summary** |
| 5 | Which plugin to extend | **`ai-junk-jobs`** (more mature); `ai-dream-jobs` retired |
| 6 | Dream Jobs career chat | **Kept**: SteveGPT chat on the Dream Jobs tab, with the full task context (Section 7a) |
| 7 | Flags table | **Shared `wp_mfsd_concern_flags`** for Dream Life and Dream & Junk Jobs (confirmed) |

---

AI Dream & Junk Jobs Tech Spec v1.0.0 | 29 September 2026
