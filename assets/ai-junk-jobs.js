These are the only changes needed in ai-junk-jobs.js.
All other code stays identical.

──────────────────────────────────────────────────────
1. renderRank() — back button  (remove old inline styles, add class)
──────────────────────────────────────────────────────
FIND:
    const backBtn = el("button", "cq-btn", "← Back");
    backBtn.style.background = "#fff";
    backBtn.style.color = "#111";

REPLACE WITH:
    const backBtn = el("button", "cq-btn cq-btn-back", "← Back");


──────────────────────────────────────────────────────
2. renderReasons() — back button  (same fix)
──────────────────────────────────────────────────────
FIND:
    const backBtn = el("button", "cq-btn", "← Back");
    backBtn.style.background = "#fff";
    backBtn.style.color = "#111";

REPLACE WITH:
    const backBtn = el("button", "cq-btn cq-btn-back", "← Back");


──────────────────────────────────────────────────────
3. renderReasons() — word count colours  (replace hardcoded hex)
──────────────────────────────────────────────────────
FIND:
        if (words.length >= 20 && words.length <= 40) {
          wordCount.style.color = "#27ae60";
        } else if (words.length > 40) {
          wordCount.style.color = "#e74c3c";
        } else {
          wordCount.style.color = "#666";
        }

REPLACE WITH:
        if (words.length >= 20 && words.length <= 40) {
          wordCount.style.color = "#00D4FF";
        } else if (words.length > 40) {
          wordCount.style.color = "#FF4C6A";
        } else {
          wordCount.style.color = "#888888";
        }


──────────────────────────────────────────────────────
4. renderResults() — jobs section inline styles  (remove hardcoded bg)
──────────────────────────────────────────────────────
FIND:
    jobsSection.style.cssText = "margin: 16px 0; padding: 12px; background: #f9f9f9; border-radius: 8px;";

REPLACE WITH:
    jobsSection.style.cssText = "margin: 16px 0;";


──────────────────────────────────────────────────────
5. renderResults() — job item inline styles  (remove hardcoded bg/border)
──────────────────────────────────────────────────────
FIND:
      jobItem.style.cssText = "margin-bottom: 10px; padding: 8px; background: #fff; border-radius: 6px; border-left: 3px solid #e74c3c;";

REPLACE WITH:
    jobItem.style.cssText = "margin-bottom: 10px;";


──────────────────────────────────────────────────────
6. renderResults() — job name inline colour  (remove hardcoded)
──────────────────────────────────────────────────────
FIND:
      jobName.style.cssText = "font-weight: 600; margin-bottom: 4px;";

REPLACE WITH:
      jobName.style.cssText = "margin-bottom: 4px;";


──────────────────────────────────────────────────────
7. renderResults() — job reason inline colour  (remove hardcoded)
──────────────────────────────────────────────────────
FIND:
      jobReason.style.cssText = "font-size: 14px; color: #666; font-style: italic;";

REPLACE WITH:
      jobReason.style.cssText = "";


──────────────────────────────────────────────────────
8. renderReasons() — reason card inline styles  (remove hardcoded bg)
──────────────────────────────────────────────────────
FIND:
      jobCard.style.cssText = "margin-bottom: 16px; padding: 12px; background: #f9f9f9; border-radius: 8px;";

REPLACE WITH:
      jobCard.style.cssText = "margin-bottom: 16px;";


──────────────────────────────────────────────────────
9. renderReasons() — job title inline styles  (remove hardcoded colour)
──────────────────────────────────────────────────────
FIND:
      jobTitle.style.cssText = "margin: 0 0 8px; font-size: 16px;";

REPLACE WITH:
      jobTitle.style.cssText = "margin: 0 0 8px;";


──────────────────────────────────────────────────────
10. renderReasons() — textarea inline styles  (remove hardcoded colours)
──────────────────────────────────────────────────────
FIND:
      textarea.style.cssText = "width: 100%; min-height: 80px; padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; font-family: inherit; resize: vertical;";

REPLACE WITH:
      textarea.style.cssText = "width: 100%;";