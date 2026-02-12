# AI Junk Jobs WordPress Plugin

## Overview
The **AI Junk Jobs** plugin is a sister plugin to AI Dream Jobs. Instead of exploring careers students want, this plugin helps students aged 12-14 identify jobs they DON'T want and understand why. Using Steve's Solutions Mindset approach, it reframes negative reasons into positive requirements and creates actionable plans.

## Features

### 4-Screen User Journey

1. **Screen 1: Input Junk Jobs**
   - Students enter 5 jobs they would NOT want to do
   - Same interface as Dream Jobs input screen
   - Requires all 5 fields to be filled

2. **Screen 2: Rank Junk Jobs**
   - Drag-and-drop ranking from MOST undesirable (top) to LEAST undesirable (bottom)
   - Visual pills show ranking position
   - Back button available to edit jobs

3. **Screen 3: Explain Why (NEW!)**
   - For each ranked job, students write ~30 words explaining why they don't want it
   - Word counter with visual feedback (green for 20-40 words)
   - Requires at least 10 words per job to proceed
   - Back button available to adjust ranking

4. **Screen 4: AI Analysis & Results**
   - Shows ranked jobs with student's reasons
   - AI-powered analysis that:
     - Acknowledges each concern
     - Provides job facts (UK salary, qualifications)
     - Explains MBTI mismatch (if available) for reassurance
     - **Reframes reasons into positive requirements** using Solutions Mindset
     - **Challenges limiting beliefs** and rewrites them in growth form
     - **Creates a 3-action marginal gains plan** for the week
   - Once completed, students always return to this summary page

### AI Analysis Components

The AI analysis includes:

1. **Per-Job Analysis:**
   - Brief acknowledgment of student's concern
   - 2-3 key facts about the job (UK salary range, qualifications needed, reality check)
   - MBTI personality mismatch explanation (reassuring tone)
   - **Solutions Mindset Reframe:** Transforms negative reasons into positive requirements
     - Example: "Doesn't pay well" → "You need financial security and value"
     - Example: "Boring" → "You need variety, creativity, and intellectual challenge"
   - **Limiting Belief Challenge:** Identifies and rewrites limiting beliefs
     - Example: "I'm not good with people" → "You haven't yet developed your interpersonal skills + strategy"

2. **Synthesis Section:**
   - What these 5 junk jobs reveal about what the student DOES value
   - 3-4 positive requirements extracted from all responses

3. **Marginal Gains Action Plan:**
   - 3 specific 1% actions for THIS WEEK:
     - One skill/learning action
     - One exploration/research action
     - One mindset/habits action
   - Reduces risk of ending up in a junk job

### Technical Features

- **Database Integration:** Stores jobs, ranking, reasons, and analysis
- **State Management:** Students can leave and return to where they left off
- **MBTI Integration:** Pulls personality type from High Performance Pathway plugin
- **Progressive Disclosure:** Only shows what's needed at each step
- **Responsive Design:** Mobile-friendly interface

## Installation

1. Upload the `ai-junk-jobs` folder to `/wp-content/plugins/`
2. Activate the plugin through WordPress admin
3. Plugin creates database table: `wp_mfsd_ai_junk_jobs_results`

## Usage

Add the shortcode to any page or post:

```
[ai_junk_jobs]
```

## Database Schema

Table: `wp_mfsd_ai_junk_jobs_results`

- `id` - Auto-increment primary key
- `user_id` - WordPress user ID (unique)
- `jobs_json` - JSON array of 5 junk jobs
- `ranking_json` - JSON array of jobs in ranked order
- `reasons_json` - JSON object mapping job → reason text
- `analysis` - AI-generated analysis text
- `mbti_type` - MBTI personality type (from RAG assessment)
- `status` - Workflow status: not_started, in_progress, reasons, completed
- `created_at` - Timestamp
- `updated_at` - Auto-updated timestamp

## API Endpoints

### GET `/wp-json/ai-junk-jobs/v1/status`
Returns user's current progress and data

**Response:**
```json
{
  "ok": true,
  "status": "completed",
  "jobs": [...],
  "ranking": [...],
  "reasons": {...},
  "analysis": "...",
  "mbti_type": "ENFP"
}
```

### POST `/wp-json/ai-junk-jobs/v1/submit`
Handles all workflow steps

**Steps:**
- `save_jobs` - Save initial 5 jobs
- `save_ranking` - Save ranked order
- `back_to_input` - Return to job input
- `back_to_rank` - Return to ranking
- `generate_analysis` - Generate AI analysis from reasons

## AI Integration

Requires **AI Engine** plugin (Meow Apps) with configured AI model.

The plugin uses `$GLOBALS['mwai']->simpleTextQuery()` to generate:
- Solutions Mindset reframing of negative reasons
- Growth mindset rewrites of limiting beliefs
- Personalized marginal gains action plans
- MBTI-based reassurance about job mismatches

## Dependencies

### Required:
- WordPress 5.0+
- PHP 7.4+
- AI Engine plugin (Meow Apps) for AI analysis

### Optional:
- High Performance Pathway plugin (for MBTI data)
- Ultimate Member plugin (for user profile integration)

## File Structure

```
ai-junk-jobs/
├── ai-junk-jobs.php          # Main plugin file
└── assets/
    ├── ai-junk-jobs.js        # Frontend JavaScript
    └── ai-junk-jobs.css       # Styling
```

## Solutions Mindset Examples

The AI reframes student concerns into positive requirements:

| Student's Negative Reason | Solutions Mindset Reframe |
|---------------------------|---------------------------|
| "Doesn't pay well" | "You need financial security and value" |
| "Boring and repetitive" | "You need variety, creativity, and intellectual challenge" |
| "People shout at you" | "You need a respectful, supportive environment" |
| "Too much pressure" | "You need reasonable work-life balance" |
| "No career progression" | "You need growth opportunities and advancement" |

## Limiting Belief Challenges

Example transformations:
- "I'm not good with people" → "You haven't yet mastered interpersonal skills. Strategy: Join one social club this term."
- "I could never do math" → "You're still developing your mathematical thinking. Strategy: Practice 10 minutes daily on Khan Academy."

## Version History

- **1.0.0** - Initial release
  - 4-screen workflow (input → rank → reasons → analysis)
  - Solutions Mindset AI reframing
  - Limiting belief challenges
  - Marginal gains action planning
  - MBTI integration for reassurance

## Support

Created by MisterT9007 for My Future Self Digital educational platform.

## License

Proprietary - For My Future Self Digital use only.
