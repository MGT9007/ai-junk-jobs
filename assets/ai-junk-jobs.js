(function () {
  const cfg = window.AI_JUNK_JOBS_CFG || {};
  const root = document.getElementById("ai-junk-jobs-root");
  if (!root) return;

  console.log('AI Junk Jobs Config:', cfg);

  let jobs = [];
  let ranking = [];
  let reasons = {};
  let resultData = null;
  let step = "loading";

  function el(tag, cls, txt) {
    const x = document.createElement(tag);
    if (cls) x.className = cls;
    if (txt !== undefined && txt !== null) x.textContent = txt;
    return x;
  }

  function showLoadingOverlay(text = "Generating your analysis...") {
    const overlay = el("div", "cq-loading-overlay");
    const spinner = el("div", "cq-spinner");
    const textEl = el("div", "cq-loading-text", text);
    
    overlay.appendChild(spinner);
    overlay.appendChild(textEl);
    document.body.appendChild(overlay);
    
    return overlay;
  }

  function hideLoadingOverlay(overlay) {
    if (overlay && overlay.parentNode) {
      overlay.parentNode.removeChild(overlay);
    }
  }

  async function checkStatus() {
    try {
      console.log('Checking status at:', cfg.restUrlStatus);
      
      const res = await fetch(cfg.restUrlStatus + "?_=" + Date.now(), {
        method: 'GET',
        headers: {
          'X-WP-Nonce': cfg.nonce || '',
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });

      console.log('Status response:', res.status, res.statusText);

      if (res.ok) {
        const data = await res.json();
        console.log('Junk Jobs Status:', data);
        
        if (data.ok && data.status === 'completed' && data.analysis) {
          resultData = {
            ranking: data.ranking || [],
            reasons: data.reasons || {},
            analysis: data.analysis,
            mbti_type: data.mbti_type
          };
          ranking = data.ranking || [];
          jobs = data.jobs || [];
          reasons = data.reasons || {};
          step = "results";
        } else if (data.ok && data.status === 'completed' && data.needs_regeneration) {
          // Save summary is OFF — task was completed before but analysis wasn't stored.
          // Restore data and auto-regenerate the summary silently.
          jobs    = data.jobs    || [];
          ranking = data.ranking || [];
          reasons = data.reasons || {};
          step = "regenerating";
        } else if (data.ok && data.status === 'reasons' && data.jobs && data.ranking) {
          jobs = data.jobs;
          ranking = data.ranking;
          reasons = data.reasons || {};
          step = "reasons";
        } else if (data.ok && data.status === 'in_progress' && data.jobs) {
          jobs = data.jobs;
          ranking = data.ranking && data.ranking.length > 0 ? data.ranking : jobs;
          step = "rank";
        } else {
          step = "input";
        }
      } else {
        const errorText = await res.text();
        console.error('Status check failed:', res.status, errorText);
        step = "input";
      }
    } catch (err) {
      console.error('Status check error:', err);
      step = "input";
    }

    mount();
  }

  function makeItem(job) {
    const li = el("li", "cq-item");
    li.dataset.job = job;

    const handle = el("span", "cq-handle", "☰");
    const text = el("span", "cq-label", job);
    const pill = el("span", "cq-rankpill", "");

    li.appendChild(handle);
    li.appendChild(text);
    li.appendChild(pill);

    return li;
  }

  function enableDnD(list) {
    let dragEl = null;
    let ghost = null;

    function updatePills() {
      const items = Array.from(list.querySelectorAll(".cq-item"));
      const total = items.length || 1;
      items.forEach((li, i) => {
        const pill = li.querySelector(".cq-rankpill");
        if (pill) pill.textContent = `${i + 1} of ${total}`;
      });
    }

    function getDragAfterElement(container, y) {
      const els = Array.from(
        container.querySelectorAll(".cq-item:not(.dragging)")
      );
      return els.reduce(
        (closest, child) => {
          const box = child.getBoundingClientRect();
          const offset = y - box.top - box.height / 2;
          if (offset < 0 && offset > closest.offset) {
            return { offset, element: child };
          }
          return closest;
        },
        { offset: Number.NEGATIVE_INFINITY, element: null }
      ).element;
    }

    function onPointerDown(e) {
      const targetItem = e.target.closest(".cq-item");
      if (!targetItem || !list.contains(targetItem)) return;

      e.preventDefault();
      dragEl = targetItem;
      dragEl.classList.add("dragging");

      ghost = document.createElement("div");
      ghost.className = "cq-ghost";
      dragEl.after(ghost);

      window.addEventListener("pointermove", onPointerMove);
      window.addEventListener("pointerup", onPointerUp);
      window.addEventListener("pointercancel", onPointerUp);
    }

    function onPointerMove(e) {
      if (!dragEl) return;
      e.preventDefault();

      const after = getDragAfterElement(list, e.clientY);
      if (!ghost) {
        ghost = document.createElement("div");
        ghost.className = "cq-ghost";
      }
      if (after == null) list.appendChild(ghost);
      else list.insertBefore(ghost, after);
    }

    function onPointerUp(e) {
      if (!dragEl) return;
      e.preventDefault();

      if (ghost) {
        list.insertBefore(dragEl, ghost);
        ghost.remove();
        ghost = null;
      }

      dragEl.classList.remove("dragging");
      dragEl = null;

      window.removeEventListener("pointermove", onPointerMove);
      window.removeEventListener("pointerup", onPointerUp);
      window.removeEventListener("pointercancel", onPointerUp);

      updatePills();
    }

    list.addEventListener("pointerdown", onPointerDown);
    updatePills();
  }

  function renderInput() {
    const wrap = el("div", "cq-wrap");
    const card = el("div", "cq-card");

    const head = el("div", "cq-header");
    head.appendChild(el("h2", "cq-title", "Step 1: Jobs you DON'T want"));
    card.appendChild(head);

    card.appendChild(
      el("p", "cq-sub",
        "Think of 5 jobs you'd really NOT want to do. Be honest – this is about figuring out what matters to you!")
    );

    const inputsWrap = el("div", "cq-inputs-vertical");

    const existing = jobs.length ? jobs : Array(5).fill("");
    for (let i = 0; i < 5; i++) {
      const row = el("div", "cq-input-row");
      const label = el("label", "", `Job ${i + 1}`);
      const input = document.createElement("input");
      input.type = "text";
      input.placeholder = "e.g. Telemarketer, Bin Collector, Factory Worker…";
      input.value = existing[i] || "";
      row.appendChild(label);
      row.appendChild(input);
      inputsWrap.appendChild(row);
    }

    card.appendChild(inputsWrap);

    const actions = el("div", "cq-actions");
    const nextBtn = el("button", "cq-btn", "Next: Rank them");
    nextBtn.disabled = true;
    actions.appendChild(nextBtn);
    card.appendChild(actions);

    function updateCanProceed() {
      const vals = Array.from(inputsWrap.querySelectorAll("input")).map((i) => i.value.trim());
      const filled = vals.filter((v) => v !== "");
      nextBtn.disabled = filled.length < 5;
    }

    inputsWrap.addEventListener("input", updateCanProceed);
    updateCanProceed();

    nextBtn.onclick = async () => {
      const vals = Array.from(inputsWrap.querySelectorAll("input")).map((i) => i.value.trim());
      jobs = vals.filter((v) => v !== "").slice(0, 5);
      ranking = [...jobs];

      try {
        nextBtn.disabled = true;
        nextBtn.textContent = "Saving...";

        console.log('Saving jobs:', jobs);

        const res = await fetch(cfg.restUrlSubmit, {
          method: "POST",
          headers: { 
            "Content-Type": "application/json",
            "X-WP-Nonce": cfg.nonce || ''
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            jobs: jobs,
            step: 'save_jobs'
          }),
        });

        const raw = await res.text();
        console.log('Save response (raw):', raw);
        
        let j = null;
        try {
          j = raw ? JSON.parse(raw) : null;
        } catch (e) {
          throw new Error("Server returned non-JSON: " + raw.slice(0, 280));
        }

        console.log('Save response (parsed):', j);

        if (!res.ok || !j || j.ok !== true) {
          throw new Error((j && j.error) || `${res.status} ${res.statusText}`);
        }

        step = "rank";
        mount();

      } catch (err) {
        console.error('Save error:', err);
        alert("Failed to save: " + err.message);
        nextBtn.disabled = false;
        nextBtn.textContent = "Next: Rank them";
      }
    };

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  function renderRank() {
    const wrap = el("div", "cq-wrap");
    const card = el("div", "cq-card");

    const head = el("div", "cq-header");
    head.appendChild(el("h2", "cq-title", "Step 2: Rank your junk jobs"));
    card.appendChild(head);

    card.appendChild(
      el("p", "cq-sub",
        "Drag to reorder from MOST undesirable (top) to LEAST undesirable (bottom)."
      )
    );

    const list = el("ul", "cq-list");
    ranking.forEach((job) => list.appendChild(makeItem(job)));
    card.appendChild(list);

    enableDnD(list);

    const actions = el("div", "cq-actions");
    
    const backBtn = el("button", "cq-btn", "← Back");
    backBtn.style.background = "#fff";
    backBtn.style.color = "#111";
    backBtn.onclick = async () => {
      try {
        await fetch(cfg.restUrlSubmit, {
          method: "POST",
          headers: { 
            "Content-Type": "application/json",
            "X-WP-Nonce": cfg.nonce || ''
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            jobs: jobs,
            ranking: ranking,
            step: 'back_to_input'
          }),
        });
      } catch (err) {
        console.error('Back error:', err);
      }
      
      step = "input";
      mount();
    };
    
    const nextBtn = el("button", "cq-btn", "Next: Explain why");
    actions.appendChild(backBtn);
    actions.appendChild(nextBtn);
    card.appendChild(actions);

    nextBtn.onclick = async () => {
      const order = Array.from(list.querySelectorAll(".cq-item")).map(
        (li) => li.dataset.job
      );
      ranking = order;

      try {
        nextBtn.disabled = true;
        nextBtn.textContent = "Saving…";

        const payload = {
          jobs: jobs,
          ranking: ranking,
          step: 'save_ranking'
        };

        console.log('Saving ranking:', payload);

        const res = await fetch(cfg.restUrlSubmit, {
          method: "POST",
          headers: { 
            "Content-Type": "application/json",
            "X-WP-Nonce": cfg.nonce || ''
          },
          credentials: 'same-origin',
          body: JSON.stringify(payload),
        });

        const raw = await res.text();
        console.log('Ranking response (raw):', raw);
        
        let j = null;
        try {
          j = raw ? JSON.parse(raw) : null;
        } catch (e) {
          throw new Error("Server returned non-JSON: " + raw.slice(0, 280));
        }

        console.log('Ranking response (parsed):', j);

        if (!res.ok || !j || j.ok !== true) {
          throw new Error((j && j.error) || `${res.status} ${res.statusText}`);
        }

        step = "reasons";
        mount();

      } catch (err) {
        console.error('Ranking save error:', err);
        alert("Failed to save ranking: " + err.message);
        nextBtn.disabled = false;
        nextBtn.textContent = "Next: Explain why";
      }
    };

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  function renderReasons() {
    const wrap = el("div", "cq-wrap");
    const card = el("div", "cq-card");

    const head = el("div", "cq-header");
    head.appendChild(el("h2", "cq-title", "Step 3: Why don't you want these jobs?"));
    card.appendChild(head);

    card.appendChild(
      el("p", "cq-sub",
        "For each job, write 30 words explaining why you wouldn't want to do it. Be specific!"
      )
    );

    const reasonsWrap = el("div", "cq-reasons-wrap");
    reasonsWrap.style.cssText = "margin-top: 16px;";

    ranking.forEach((job, idx) => {
      const jobCard = el("div", "cq-reason-card");
      jobCard.style.cssText = "margin-bottom: 16px; padding: 12px; background: #f9f9f9; border-radius: 8px;";
      
      const jobTitle = el("h4", "", `${idx + 1}. ${job}`);
      jobTitle.style.cssText = "margin: 0 0 8px; font-size: 16px;";
      
      const textarea = document.createElement("textarea");
      textarea.placeholder = "Why you don't want this job (aim for ~30 words)...";
      textarea.style.cssText = "width: 100%; min-height: 80px; padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; font-family: inherit; resize: vertical;";
      textarea.value = reasons[job] || "";
      textarea.dataset.job = job;
      
      const wordCount = el("div", "cq-word-count", "0 words");
      wordCount.style.cssText = "font-size: 12px; color: #666; margin-top: 4px; text-align: right;";
      
      textarea.addEventListener("input", () => {
        const words = textarea.value.trim().split(/\s+/).filter(w => w.length > 0);
        wordCount.textContent = `${words.length} words`;
        if (words.length >= 20 && words.length <= 40) {
          wordCount.style.color = "#27ae60";
        } else if (words.length > 40) {
          wordCount.style.color = "#e74c3c";
        } else {
          wordCount.style.color = "#666";
        }
        updateCanProceed();
      });
      
      // Trigger initial count
      textarea.dispatchEvent(new Event('input'));
      
      jobCard.appendChild(jobTitle);
      jobCard.appendChild(textarea);
      jobCard.appendChild(wordCount);
      reasonsWrap.appendChild(jobCard);
    });

    card.appendChild(reasonsWrap);

    const actions = el("div", "cq-actions");
    
    const backBtn = el("button", "cq-btn", "← Back");
    backBtn.style.background = "#fff";
    backBtn.style.color = "#111";
    backBtn.onclick = async () => {
      // Save current reasons first
      const textareas = Array.from(reasonsWrap.querySelectorAll("textarea"));
      textareas.forEach(ta => {
        reasons[ta.dataset.job] = ta.value.trim();
      });
      
      try {
        await fetch(cfg.restUrlSubmit, {
          method: "POST",
          headers: { 
            "Content-Type": "application/json",
            "X-WP-Nonce": cfg.nonce || ''
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            jobs: jobs,
            ranking: ranking,
            step: 'back_to_rank'
          }),
        });
      } catch (err) {
        console.error('Back error:', err);
      }
      
      step = "rank";
      mount();
    };
    
    const nextBtn = el("button", "cq-btn", "Generate AI Analysis");
    nextBtn.disabled = true;
    actions.appendChild(backBtn);
    actions.appendChild(nextBtn);
    card.appendChild(actions);

    function updateCanProceed() {
      const textareas = Array.from(reasonsWrap.querySelectorAll("textarea"));
      const allFilled = textareas.every(ta => {
        const words = ta.value.trim().split(/\s+/).filter(w => w.length > 0);
        return words.length >= 10; // At least 10 words
      });
      nextBtn.disabled = !allFilled;
    }

    updateCanProceed();

    nextBtn.onclick = async () => {
      // Collect all reasons
      const textareas = Array.from(reasonsWrap.querySelectorAll("textarea"));
      textareas.forEach(ta => {
        reasons[ta.dataset.job] = ta.value.trim();
      });

      let overlay = null;

      try {
        nextBtn.disabled = true;
        nextBtn.textContent = "Analyzing…";
        
        overlay = showLoadingOverlay("Analyzing your junk jobs and generating insights...");

        const payload = {
          jobs: jobs,
          ranking: ranking,
          reasons: reasons,
          step: 'generate_analysis'
        };

        console.log('Generating analysis:', payload);

        const res = await fetch(cfg.restUrlSubmit, {
          method: "POST",
          headers: { 
            "Content-Type": "application/json",
            "X-WP-Nonce": cfg.nonce || ''
          },
          credentials: 'same-origin',
          body: JSON.stringify(payload),
        });

        const raw = await res.text();
        console.log('Analysis response (raw):', raw.substring(0, 500));
        
        let j = null;
        try {
          j = raw ? JSON.parse(raw) : null;
        } catch (e) {
          throw new Error("Server returned non-JSON: " + raw.slice(0, 280));
        }

        console.log('Analysis response (parsed):', j);

        if (!res.ok || !j || j.ok !== true) {
          throw new Error((j && j.error) || `${res.status} ${res.statusText}`);
        }

        resultData = j;
        
        hideLoadingOverlay(overlay);
        
        step = "results";
        mount();

      } catch (err) {
        hideLoadingOverlay(overlay);
        console.error('Analysis error:', err);
        alert("AI analysis failed: " + err.message);
        nextBtn.disabled = false;
        nextBtn.textContent = "Generate AI Analysis";
      }
    };

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  function renderResults() {
    const wrap = el("div", "cq-wrap");
    const card = el("div", "cq-card");

    const head = el("div", "cq-header");
    head.appendChild(el("h2", "cq-title", "Your Junk Jobs Analysis"));
    card.appendChild(head);

    const rankedJobs = (resultData && resultData.ranking) || ranking;
    const jobReasons = (resultData && resultData.reasons) || reasons;
    const analysis = (resultData && resultData.analysis) || "";
    const mbtiType = resultData && resultData.mbti_type;

    // Show ranked jobs with reasons
    const jobsSection = el("div", "cq-jobs-section");
    jobsSection.style.cssText = "margin: 16px 0; padding: 12px; background: #f9f9f9; border-radius: 8px;";
    
    const jobsTitle = el("h3", "", "Your ranked junk jobs:");
    jobsTitle.style.cssText = "margin: 0 0 12px; font-size: 16px;";
    jobsSection.appendChild(jobsTitle);
    
    rankedJobs.forEach((job, idx) => {
      const jobItem = el("div", "cq-job-item");
      jobItem.style.cssText = "margin-bottom: 10px; padding: 8px; background: #fff; border-radius: 6px; border-left: 3px solid #e74c3c;";
      
      const jobName = el("div", "", `${idx + 1}. ${job}`);
      jobName.style.cssText = "font-weight: 600; margin-bottom: 4px;";
      
      const jobReason = el("div", "", jobReasons[job] || "");
      jobReason.style.cssText = "font-size: 14px; color: #666; font-style: italic;";
      
      jobItem.appendChild(jobName);
      jobItem.appendChild(jobReason);
      jobsSection.appendChild(jobItem);
    });
    
    card.appendChild(jobsSection);

    if (mbtiType) {
      const mbtiNote = el("p", "cq-mbti-note", 
        "Based on your MBTI personality type (" + mbtiType + "), here's why these jobs don't suit you and what you DO need:"
      );
      card.appendChild(mbtiNote);
    }

    if (analysis) {
      const analysisBox = el("div", "cq-analysis");
      const analysisText = el("div", "cq-analysis-text");
      analysisText.textContent = analysis;
      analysisBox.appendChild(analysisText);
      card.appendChild(analysisBox);
    } else {
      const analysisBox = el("div", "cq-analysis");
      analysisBox.appendChild(
        el("p", "cq-analysis-text",
          "We couldn't generate AI analysis right now, but your junk jobs and reasons have been saved.")
      );
      card.appendChild(analysisBox);
    }

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  function mount() {
    if (step === "loading") {
      root.textContent = "Loading...";
    } else if (step === "input") {
      renderInput();
    } else if (step === "rank") {
      renderRank();
    } else if (step === "reasons") {
      renderReasons();
    } else if (step === "regenerating") {
      renderRegenerating();
    } else {
      renderResults();
    }
  }

  // Auto-regenerates summary when save_summary is OFF and student returns.
  // Shows a loading state, fires generate_analysis silently, then shows results.
  async function renderRegenerating() {
    const overlay = showLoadingOverlay("Generating your analysis...");

    try {
      const res = await fetch(cfg.restUrlSubmit, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": cfg.nonce || ""
        },
        credentials: "same-origin",
        body: JSON.stringify({
          jobs:    jobs,
          ranking: ranking,
          reasons: reasons,
          step:    "generate_analysis"
        })
      });

      const raw = await res.text();
      let j = null;
      try { j = raw ? JSON.parse(raw) : null; } catch(e) { /* ignore */ }

      hideLoadingOverlay(overlay);

      if (j && j.ok) {
        resultData = j;
        step = "results";
        mount();
      } else {
        throw new Error((j && j.error) || "Generation failed");
      }
    } catch (err) {
      hideLoadingOverlay(overlay);
      console.error("Regeneration error:", err);
      // Fall back to reasons screen so student can retry manually
      step = "reasons";
      mount();
    }
  }

  checkStatus();
})();