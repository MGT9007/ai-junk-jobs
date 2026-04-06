(function () {
  'use strict';

  const cfg  = window.AI_JUNK_JOBS_CFG || {};
  const root = document.getElementById('ai-junk-jobs-root');
  if (!root) return;

  const MIN_WORDS = cfg.minWords || 30;

  let jobs       = [];
  let ranking    = [];
  let reasons    = {};
  let resultData = null;
  let step       = 'loading';

  /* ================================================================
     HELPERS
     ================================================================ */
  function el(tag, cls, txt) {
    const x = document.createElement(tag);
    if (cls) x.className = cls;
    if (txt !== undefined && txt !== null) x.textContent = txt;
    return x;
  }

  function showLoadingOverlay(text) {
    let overlay = document.querySelector('.cq-loading-overlay');
    if (overlay) {
      const t = overlay.querySelector('.cq-loading-text');
      if (t) t.textContent = text || 'Loading...';
      return overlay;
    }
    overlay = el('div', 'cq-loading-overlay');
    overlay.appendChild(el('div', 'cq-spinner'));
    overlay.appendChild(el('div', 'cq-loading-text', text || 'Loading...'));
    document.body.appendChild(overlay);
    return overlay;
  }

  function updateLoadingText(text) {
    const t = document.querySelector('.cq-loading-text');
    if (t) t.textContent = text;
  }

  function hideLoadingOverlay() {
    const overlay = document.querySelector('.cq-loading-overlay');
    if (overlay) overlay.remove();
  }

  async function apiFetch(url, options) {
    const res = await fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce || '', 'Accept': 'application/json' }
    }, options));
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  }

  /* ================================================================
     STATUS CHECK
     ================================================================ */
  async function checkStatus() {
    try {
      const data = await apiFetch(cfg.restUrlStatus + '?_=' + Date.now());

      if (data.ok && data.status === 'completed' && (data.intro_text || data.job_summaries)) {
        // New structured format
        resultData = data;
        ranking    = data.ranking || [];
        jobs       = data.jobs    || [];
        reasons    = data.reasons || {};
        step       = 'results';
      } else if (data.ok && data.status === 'completed' && data.analysis) {
        // Legacy single-analysis format
        resultData = data;
        ranking    = data.ranking || [];
        jobs       = data.jobs    || [];
        reasons    = data.reasons || {};
        step       = 'results';
      } else if (data.ok && data.status === 'completed' && data.needs_regeneration) {
        jobs    = data.jobs    || [];
        ranking = data.ranking || [];
        reasons = data.reasons || {};
        step    = 'regenerating';
      } else if (data.ok && data.status === 'reasons' && data.jobs && data.ranking) {
        jobs    = data.jobs;
        ranking = data.ranking;
        reasons = data.reasons || {};
        step    = 'reasons';
      } else if (data.ok && data.status === 'in_progress' && data.jobs) {
        jobs    = data.jobs;
        ranking = data.ranking && data.ranking.length > 0 ? data.ranking : jobs;
        step    = 'rank';
      } else {
        step = 'input';
      }
    } catch (err) {
      console.error('Status check error:', err);
      step = 'input';
    }
    mount();
  }

  /* ================================================================
     DRAG AND DROP
     ================================================================ */
  function makeItem(job) {
    const li     = el('li', 'cq-item');
    li.dataset.job = job;
    li.appendChild(el('span', 'cq-handle', '☰'));
    li.appendChild(el('span', 'cq-label', job));
    li.appendChild(el('span', 'cq-rankpill', ''));
    return li;
  }

  function enableDnD(list) {
    let dragEl = null;
    let ghost  = null;

    function updatePills() {
      const items = Array.from(list.querySelectorAll('.cq-item'));
      const total = items.length || 1;
      items.forEach(function (li, i) {
        const pill = li.querySelector('.cq-rankpill');
        if (pill) pill.textContent = (i + 1) + ' of ' + total;
      });
    }

    function getDragAfterElement(container, y) {
      return Array.from(container.querySelectorAll('.cq-item:not(.dragging)')).reduce(function (closest, child) {
        const box    = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) return { offset: offset, element: child };
        return closest;
      }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
    }

    function onPointerDown(e) {
      const targetItem = e.target.closest('.cq-item');
      if (!targetItem || !list.contains(targetItem)) return;
      e.preventDefault();
      dragEl = targetItem;
      dragEl.classList.add('dragging');
      ghost = document.createElement('div');
      ghost.className = 'cq-ghost';
      dragEl.after(ghost);
      window.addEventListener('pointermove', onPointerMove);
      window.addEventListener('pointerup', onPointerUp);
      window.addEventListener('pointercancel', onPointerUp);
    }

    function onPointerMove(e) {
      if (!dragEl) return;
      e.preventDefault();
      const after = getDragAfterElement(list, e.clientY);
      if (!ghost) { ghost = document.createElement('div'); ghost.className = 'cq-ghost'; }
      if (after == null) list.appendChild(ghost);
      else list.insertBefore(ghost, after);
    }

    function onPointerUp(e) {
      if (!dragEl) return;
      e.preventDefault();
      if (ghost) { list.insertBefore(dragEl, ghost); ghost.remove(); ghost = null; }
      dragEl.classList.remove('dragging');
      dragEl = null;
      window.removeEventListener('pointermove', onPointerMove);
      window.removeEventListener('pointerup', onPointerUp);
      window.removeEventListener('pointercancel', onPointerUp);
      updatePills();
    }

    list.addEventListener('pointerdown', onPointerDown);
    updatePills();
  }

  /* ================================================================
     SCREEN 1 — Input
     ================================================================ */
  function renderInput() {
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card');
    const head = el('div', 'cq-header');
    head.appendChild(el('h2', 'cq-title', "Step 1: Jobs you DON'T want"));
    card.appendChild(head);
    card.appendChild(el('p', 'cq-sub', "Think of 5 jobs you'd really NOT want to do. Be honest – this is about figuring out what matters to you!"));

    const inputsWrap = el('div', 'cq-inputs-vertical');
    const existing   = jobs.length ? jobs : Array(5).fill('');
    for (var i = 0; i < 5; i++) {
      const row   = el('div', 'cq-input-row');
      const label = el('label', '', 'Job ' + (i + 1));
      const input = document.createElement('input');
      input.type        = 'text';
      input.placeholder = 'e.g. Telemarketer, Bin Collector, Factory Worker…';
      input.value       = existing[i] || '';
      row.appendChild(label);
      row.appendChild(input);
      inputsWrap.appendChild(row);
    }
    card.appendChild(inputsWrap);

    const actions = el('div', 'cq-actions');
    const nextBtn = el('button', 'cq-btn', 'Next: Rank them');
    nextBtn.disabled = true;
    actions.appendChild(nextBtn);
    card.appendChild(actions);

    function updateCanProceed() {
      const vals = Array.from(inputsWrap.querySelectorAll('input')).map(function (i) { return i.value.trim(); });
      nextBtn.disabled = vals.filter(function (v) { return v !== ''; }).length < 5;
    }
    inputsWrap.addEventListener('input', updateCanProceed);
    updateCanProceed();

    nextBtn.onclick = async function () {
      const vals = Array.from(inputsWrap.querySelectorAll('input')).map(function (i) { return i.value.trim(); });
      jobs    = vals.filter(function (v) { return v !== ''; }).slice(0, 5);
      ranking = jobs.slice();
      try {
        nextBtn.disabled  = true;
        nextBtn.textContent = 'Saving...';
        const data = await apiFetch(cfg.restUrlSubmit, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
          body:    JSON.stringify({ jobs: jobs, step: 'save_jobs' })
        });
        if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Save failed');
        step = 'rank';
        mount();
      } catch (err) {
        console.error('Save error:', err);
        alert('Failed to save: ' + err.message);
        nextBtn.disabled    = false;
        nextBtn.textContent = 'Next: Rank them';
      }
    };

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  /* ================================================================
     SCREEN 2 — Rank
     ================================================================ */
  function renderRank() {
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card');
    const head = el('div', 'cq-header');
    head.appendChild(el('h2', 'cq-title', 'Step 2: Rank your junk jobs'));
    card.appendChild(head);
    card.appendChild(el('p', 'cq-sub', 'Drag to reorder from MOST undesirable (top) to LEAST undesirable (bottom).'));

    const list = el('ul', 'cq-list');
    ranking.forEach(function (job) { list.appendChild(makeItem(job)); });
    card.appendChild(list);
    enableDnD(list);

    const actions = el('div', 'cq-actions');
    const backBtn = el('button', 'cq-btn cq-btn-back', '← Back');
    const nextBtn = el('button', 'cq-btn', 'Next: Explain why');
    actions.appendChild(backBtn);
    actions.appendChild(nextBtn);
    card.appendChild(actions);

    backBtn.onclick = async function () {
      try {
        await apiFetch(cfg.restUrlSubmit, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
          body:    JSON.stringify({ jobs: jobs, ranking: ranking, step: 'back_to_input' })
        });
      } catch (err) { console.error('Back error:', err); }
      step = 'input';
      mount();
    };

    nextBtn.onclick = async function () {
      ranking = Array.from(list.querySelectorAll('.cq-item')).map(function (li) { return li.dataset.job; });
      try {
        nextBtn.disabled    = true;
        nextBtn.textContent = 'Saving…';
        const data = await apiFetch(cfg.restUrlSubmit, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
          body:    JSON.stringify({ jobs: jobs, ranking: ranking, step: 'save_ranking' })
        });
        if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Save failed');
        step = 'reasons';
        mount();
      } catch (err) {
        console.error('Ranking save error:', err);
        alert('Failed to save ranking: ' + err.message);
        nextBtn.disabled    = false;
        nextBtn.textContent = 'Next: Explain why';
      }
    };

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  /* ================================================================
     SCREEN 3 — Reasons
     ================================================================ */
  function renderReasons() {
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card');
    const head = el('div', 'cq-header');
    head.appendChild(el('h2', 'cq-title', "Step 3: Why don't you want these jobs?"));
    card.appendChild(head);
    card.appendChild(el('p', 'cq-sub', 'For each job, write at least ' + MIN_WORDS + ' words explaining why you wouldn\'t want to do it. Be specific!'));

    const reasonsWrap = el('div', 'cq-reasons-wrap');

    ranking.forEach(function (job, idx) {
      const jobCard  = el('div', 'cq-reason-card');
      const jobTitle = el('h4', '', (idx + 1) + '. ' + job);
      const textarea = document.createElement('textarea');
      textarea.placeholder  = 'Why you don\'t want this job (aim for ' + MIN_WORDS + ' words)...';
      textarea.value        = reasons[job] || '';
      textarea.dataset.job  = job;
      const wordCount = el('div', 'cq-word-count', '0 words');

      textarea.addEventListener('input', function () {
        const words = textarea.value.trim().split(/\s+/).filter(function (w) { return w.length > 0; });
        const wc    = words.length;
        wordCount.textContent = wc + ' words';
        if (wc >= MIN_WORDS) {
          wordCount.style.color = '#00FF88';
        } else if (wc > 0) {
          wordCount.style.color = '#00D4FF';
        } else {
          wordCount.style.color = '#888888';
        }
        updateCanProceed();
      });
      textarea.dispatchEvent(new Event('input'));

      jobCard.appendChild(jobTitle);
      jobCard.appendChild(textarea);
      jobCard.appendChild(wordCount);
      reasonsWrap.appendChild(jobCard);
    });

    card.appendChild(reasonsWrap);

    const actions = el('div', 'cq-actions');
    const backBtn = el('button', 'cq-btn cq-btn-back', '← Back');
    const nextBtn = el('button', 'cq-btn', 'Generate AI Analysis');
    nextBtn.disabled = true;
    actions.appendChild(backBtn);
    actions.appendChild(nextBtn);
    card.appendChild(actions);

    function updateCanProceed() {
      const textareas = Array.from(reasonsWrap.querySelectorAll('textarea'));
      if (textareas.length < ranking.length) { nextBtn.disabled = true; return; }
      nextBtn.disabled = !textareas.every(function (ta) {
        return ta.value.trim().split(/\s+/).filter(function (w) { return w.length > 0; }).length >= MIN_WORDS;
      });
    }
    updateCanProceed();

    backBtn.onclick = async function () {
      Array.from(reasonsWrap.querySelectorAll('textarea')).forEach(function (ta) {
        reasons[ta.dataset.job] = ta.value.trim();
      });
      try {
        await apiFetch(cfg.restUrlSubmit, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
          body:    JSON.stringify({ jobs: jobs, ranking: ranking, step: 'back_to_rank' })
        });
      } catch (err) { console.error('Back error:', err); }
      step = 'rank';
      mount();
    };

    nextBtn.onclick = async function () {
      Array.from(reasonsWrap.querySelectorAll('textarea')).forEach(function (ta) {
        reasons[ta.dataset.job] = ta.value.trim();
      });

      nextBtn.disabled    = true;
      nextBtn.textContent = 'Analyzing…';

      const loadingMessages = [
        'Reading your choices...',
        'Analysing job 1 of 5...',
        'Analysing job 2 of 5...',
        'Analysing job 3 of 5...',
        'Analysing job 4 of 5...',
        'Analysing job 5 of 5...',
        'Writing your conclusions...',
        'Almost done...'
      ];

      showLoadingOverlay(loadingMessages[0]);

      var msgIdx = 0;
      var msgTimer = setInterval(function () {
        msgIdx++;
        if (msgIdx < loadingMessages.length) {
          updateLoadingText(loadingMessages[msgIdx]);
        }
      }, 3500);

      try {
        const data = await apiFetch(cfg.restUrlSubmit, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
          body:    JSON.stringify({ jobs: jobs, ranking: ranking, reasons: reasons, step: 'generate_analysis' })
        });

        clearInterval(msgTimer);
        hideLoadingOverlay();

        if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Analysis failed');
        resultData = data;
        step       = 'results';
        mount();
      } catch (err) {
        clearInterval(msgTimer);
        hideLoadingOverlay();
        console.error('Analysis error:', err);
        alert('AI analysis failed: ' + err.message);
        nextBtn.disabled    = false;
        nextBtn.textContent = 'Generate AI Analysis';
      }
    };

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  /* ================================================================
     SCREEN 4 — Results (new structured format)
     ================================================================ */
  function renderResults() {
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card');
    const head = el('div', 'cq-header');
    head.appendChild(el('h2', 'cq-title', 'Your Junk Jobs Analysis'));
    card.appendChild(head);

    const rankedJobs     = (resultData && resultData.ranking)       || ranking;
    const jobReasons     = (resultData && resultData.reasons)        || reasons;
    const introText      = (resultData && resultData.intro_text)     || '';
    const jobSummaries   = (resultData && resultData.job_summaries)  || [];
    const conclusion     = (resultData && resultData.conclusion)     || '';
    const legacyAnalysis = (resultData && resultData.analysis)       || '';

    // ── Intro (Steve says opener) ──────────────────────────────────
    if (introText) {
      const introBox = el('div', 'cq-analysis-opener-box');
      introBox.innerHTML = formatInline(introText);
      card.appendChild(introBox);
    }

    // ── Per-job blocks ─────────────────────────────────────────────
    if (jobSummaries && jobSummaries.length > 0) {

      rankedJobs.forEach(function (job, idx) {

        // Outer block — matches the styled card from the old jobs section
        const block = el('div', 'cq-job-result-block');

        // ── Job header (number + name in yellow, purple left border) ──
        const jobHeader = el('div', 'cq-job-result-header');
        const jobNum    = el('span', 'cq-job-result-num', (idx + 1) + '.');
        const jobName   = el('span', 'cq-job-result-name', job);
        jobHeader.appendChild(jobNum);
        jobHeader.appendChild(jobName);
        block.appendChild(jobHeader);

        // ── Student's own reason (muted italic, matches old job-item style) ──
        if (jobReasons[job]) {
          const reasonBox = el('div', 'cq-job-result-reason');
          reasonBox.textContent = jobReasons[job];
          block.appendChild(reasonBox);
        }

        // ── AI summary for this job (cyan left border, body text) ──
        const summary = jobSummaries[idx] || '';
        if (summary) {
          const aiLabel = el('div', 'cq-job-result-ai-label', 'Steve says:');
          const aiBox   = el('div', 'cq-job-result-ai');
          aiBox.innerHTML = formatInline(summary);
          block.appendChild(aiLabel);
          block.appendChild(aiBox);
        }

        card.appendChild(block);
      });

      // ── Conclusion (inside card, above buttons) ────────────────────
      if (conclusion) {
        const concBox = el('div', 'cq-conclusion-box');
        concBox.innerHTML = formatInline(conclusion);
        card.appendChild(concBox);
      }

    } else if (legacyAnalysis) {
      const analysisBox  = el('div', 'cq-analysis');
      const analysisText = el('div', 'cq-analysis-text');
      analysisText.innerHTML = formatAnalysis(legacyAnalysis);
      analysisBox.appendChild(analysisText);
      card.appendChild(analysisBox);
    }

    // ── Navigation buttons — inside card, always last ──────────────
    const navWrap   = el('div', 'cq-nav-actions');
    const badgesBtn = document.createElement('a');
    badgesBtn.className   = 'cq-btn cq-btn-back';
    badgesBtn.href        = cfg.urlBadges || 'https://mfsd.me/badges/';
    badgesBtn.textContent = 'View My Badges';
    const courseBtn = document.createElement('a');
    courseBtn.className   = 'cq-btn';
    courseBtn.href        = cfg.urlCourse || 'https://mfsd.me/about/parent-portal-home/?course_id=1';
    courseBtn.textContent = 'Return to Course';
    navWrap.appendChild(badgesBtn);
    navWrap.appendChild(courseBtn);
    card.appendChild(navWrap);

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  /* ================================================================
     REGENERATING (save_summary was OFF, returning student)
     ================================================================ */
  async function renderRegenerating() {
    showLoadingOverlay('Generating your analysis...');
    try {
      const data = await apiFetch(cfg.restUrlSubmit, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
        body:    JSON.stringify({ jobs: jobs, ranking: ranking, reasons: reasons, step: 'generate_analysis' })
      });
      hideLoadingOverlay();
      if (data && data.ok) {
        resultData = data;
        step       = 'results';
        mount();
      } else {
        throw new Error((data && data.error) || 'Generation failed');
      }
    } catch (err) {
      hideLoadingOverlay();
      console.error('Regeneration error:', err);
      step = 'reasons';
      mount();
    }
  }

  /* ================================================================
     TEXT FORMATTERS
     ================================================================ */

  // Inline formatting only — bold markers, clean up asterisks
  function formatInline(text) {
    if (!text) return '';
    text = text.replace(/\*\*([^*]+)\*\*/g, '<strong class="cq-analysis-highlight">$1</strong>');
    text = text.replace(/\*+/g, '');
    // Convert newlines to <br>
    text = text.replace(/\n/g, '<br>');
    return text;
  }

  // Full paragraph formatter — used for legacy single-analysis only
  function formatAnalysis(text) {
    if (!text) return '';
    text = text.replace(/\*\*([^*]+)\*\*/g, '<strong class="cq-analysis-highlight">$1</strong>');
    text = text.replace(/\*+/g, '');
    return text.split(/\n\n+/).map(function (p) {
      p = p.trim();
      if (!p) return '';
      if (p.match(/^[-–]\s*Steve/i))  return '<p class="cq-analysis-signoff">' + p + '</p>';
      if (p.match(/^Steve says:/i))   return '<p class="cq-analysis-opener">'  + p + '</p>';
      return '<p class="cq-analysis-para">' + p.replace(/\n/g, '<br>') + '</p>';
    }).join('');
  }

  /* ================================================================
     MOUNT
     ================================================================ */
  function mount() {
    if      (step === 'loading')      root.textContent = 'Loading...';
    else if (step === 'input')        renderInput();
    else if (step === 'rank')         renderRank();
    else if (step === 'reasons')      renderReasons();
    else if (step === 'regenerating') renderRegenerating();
    else                              renderResults();
  }

  checkStatus();
})();