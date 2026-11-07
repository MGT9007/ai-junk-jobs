(function () {
  'use strict';

  const cfg  = window.AI_JUNK_JOBS_CFG || {};
  const root = document.getElementById('ai-junk-jobs-root');
  if (!root) return;

  const MIN_WORDS   = cfg.minWords || 30;
  const PARENT_VIEW = !!cfg.parentView;
  const REDUCED     = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const KINDS       = { skill: 'Skill', explore: 'Explore', habit: 'Habit', own: 'Write my own' };
  const chatSource  = document.getElementById('ai-junk-jobs-chat-source');

  // Two halves share the same three screens (enter → rank → why).
  const HALVES = {
    dream: {
      label:        'Dream jobs',
      inputTitle:   'Step 1: Your 5 dream jobs',
      inputSub:     "Think of 5 jobs you'd LOVE to do one day. Dream big – there are no wrong answers!",
      placeholder:  'e.g. Vet, Game Designer, Footballer…',
      saveStep:     'save_dream_items',
      payloadKey:   'items',
      rankTitle:    'Step 2: Rank your dream jobs',
      rankSub:      'Drag to reorder from MOST wanted (top) to LEAST wanted (bottom).',
      rankStep:     'save_dream_ranking',
      backToInput:  'back_to_dream_input',
      reasonsTitle: 'Step 3: Why do you want these jobs?',
      reasonsSub:   'For each job, write at least ' + MIN_WORDS + ' words about why you want to do it. What excites you about it?',
      reasonsPh:    'Why you want this job (aim for ' + MIN_WORDS + ' words)...',
      backToRank:   'back_to_dream_rank',
      genStep:      'generate_dream_analysis',
      genLabel:     'Send to Steve',
      loading: ['Reading your dream jobs...', 'Looking at dream job 1 of 5...', 'Looking at dream job 2 of 5...',
                'Looking at dream job 3 of 5...', 'Looking at dream job 4 of 5...', 'Looking at dream job 5 of 5...', 'Almost done...']
    },
    junk: {
      label:        'Junk jobs',
      inputTitle:   "Step 4: Jobs you DON'T want",
      inputSub:     "Now the fun bit! Think of 5 jobs you'd really NOT want to do. Be honest – this is about figuring out what matters to you!",
      placeholder:  'e.g. Telemarketer, Bin Collector, Factory Worker…',
      saveStep:     'save_jobs',
      payloadKey:   'jobs',
      rankTitle:    'Step 5: Rank your junk jobs',
      rankSub:      'Drag to reorder from MOST undesirable (top) to LEAST undesirable (bottom).',
      rankStep:     'save_ranking',
      backToInput:  'back_to_input',
      reasonsTitle: "Step 6: Why don't you want these jobs?",
      reasonsSub:   'For each job, write at least ' + MIN_WORDS + " words explaining why you wouldn't want to do it. Be specific!",
      reasonsPh:    "Why you don't want this job (aim for " + MIN_WORDS + ' words)...',
      backToRank:   'back_to_rank',
      genStep:      'generate_analysis',
      genLabel:     'Generate AI Analysis',
      loading: ['Reading your choices...', 'Analysing junk job 1 of 5...', 'Analysing junk job 2 of 5...', 'Analysing junk job 3 of 5...',
                'Analysing junk job 4 of 5...', 'Analysing junk job 5 of 5...', 'Putting both lists together...', 'Writing your summary...', 'Almost done...']
    }
  };

  const BANDS = {
    natural: { icon: '🟢', label: 'Natural fit' },
    good:    { icon: '🔵', label: 'Good fit + stretch' },
    stretch: { icon: '🟣', label: 'Stretch zone' }
  };

  const state = {
    dream: { items: [], ranking: [], reasons: {} },
    junk:  { items: [], ranking: [], reasons: {} }
  };
  let data         = {};
  let step         = 'loading';
  let isLegacy     = false;
  let chatContext  = '';
  let chatWidget   = null;

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

  // Throws with the server's own error message on HTTP errors.
  async function apiFetch(url, options) {
    const res = await fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce || '', 'Accept': 'application/json' }
    }, options));
    let json = null;
    try { json = await res.json(); } catch (e) { /* non-JSON */ }
    if (!res.ok) throw new Error((json && (json.error || json.message)) || ('HTTP ' + res.status));
    return json;
  }

  function post(body) {
    return apiFetch(cfg.restUrlSubmit, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body:    JSON.stringify(body)
    });
  }

  function isUnavailable(res) {
    return res && res.ok === false && res.error === 'steve_unavailable';
  }

  function notice(card, text, kind) {
    let n = card.querySelector('.cq-notice');
    if (!n) {
      n = el('div', 'cq-notice');
      card.insertBefore(n, card.querySelector('.cq-actions'));
    }
    n.className = 'cq-notice' + (kind ? ' cq-notice--' + kind : '');
    n.textContent = text;
  }

  function wordCount(text) {
    return text.trim().split(/\s+/).filter(function (w) { return w.length > 0; }).length;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Inline formatting only — bold markers, newlines. Text is escaped first.
  function formatInline(text) {
    if (!text) return '';
    text = escapeHtml(text);
    text = text.replace(/\*\*([^*]+)\*\*/g, '<strong class="cq-analysis-highlight">$1</strong>');
    text = text.replace(/\*+/g, '');
    return text.replace(/\n/g, '<br>');
  }

  function reqLabel(req) {
    return String(req).replace(/^i need\s+/i, '');
  }

  function uniqueRequirements() {
    const reqs = data.requirements || {};
    const out  = [];
    (state.junk.ranking || []).forEach(function (j) {
      const r = reqs[j];
      if (r && out.indexOf(r) === -1) out.push(r);
    });
    return out;
  }

  function header(card, title, pill) {
    const head = el('div', 'cq-header');
    head.appendChild(el('h2', 'cq-title', title));
    if (pill) head.appendChild(el('span', 'cq-half-pill', pill));
    card.appendChild(head);
  }

  function applyData(d) {
    data = d || {};
    state.dream.items   = d.dream_items   || [];
    state.dream.ranking = (d.dream_ranking && d.dream_ranking.length) ? d.dream_ranking : state.dream.items.slice();
    state.dream.reasons = d.dream_reasons || {};
    state.junk.items    = d.jobs    || [];
    state.junk.ranking  = (d.ranking && d.ranking.length) ? d.ranking : state.junk.items.slice();
    state.junk.reasons  = d.reasons || {};
  }

  /* ================================================================
     STATUS CHECK
     ================================================================ */
  async function checkStatus() {
    try {
      const url = cfg.restUrlStatus + '?_=' + Date.now() + (PARENT_VIEW ? '&student_id=' + encodeURIComponent(cfg.studentId) : '');
      const d   = await apiFetch(url);
      applyData(d);
      isLegacy = !!d.needs_dream_half;

      if (PARENT_VIEW) {
        // The server sends no jobs to a parent until they have played (spec §16.4).
        step = (d.status === 'completed' && !isLegacy && !d.gated) ? 'results' : 'parent_waiting';
      } else if (isLegacy || d.status === 'not_started') {
        if (!state.dream.items.length && d.prefill) {
          state.dream.items   = d.prefill.items   || [];
          state.dream.ranking = d.prefill.ranking || state.dream.items.slice();
        }
        step = 'dream_input';
      } else if (d.status === 'dream_in_progress') {
        step = 'dream_rank';
      } else if (d.status === 'dream_reasons') {
        step = 'dream_reasons';
      } else if (d.status === 'dream_done') {
        step = d.junk_ready ? 'auto_analysis' : 'halfway';
      } else if (d.status === 'in_progress') {
        step = 'junk_rank';
      } else if (d.status === 'reasons') {
        step = 'junk_reasons';
      } else if (d.status === 'completed' || d.status === 'commit') {
        step = d.needs_regeneration ? 'regenerating' : 'results';
      } else {
        step = 'dream_input';
      }
    } catch (err) {
      console.error('Status check error:', err);
      if (PARENT_VIEW) { root.textContent = "Sorry, we couldn't load these results."; return; }
      step = 'dream_input';
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
     SCREEN — Input (both halves)
     ================================================================ */
  function renderInput(half) {
    const H    = HALVES[half];
    const S    = state[half];
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card');
    header(card, H.inputTitle, H.label);
    card.appendChild(el('p', 'cq-sub', H.inputSub));
    if (half === 'dream' && isLegacy) {
      card.appendChild(el('p', 'cq-note', "Junk Jobs is now Dream & Junk Jobs! We've kept your junk jobs — just add your dream jobs and Steve will put it all together."));
    }

    const inputsWrap = el('div', 'cq-inputs-vertical');
    const existing   = S.items.length ? S.items : Array(5).fill('');
    for (let i = 0; i < 5; i++) {
      const row   = el('div', 'cq-input-row');
      const label = el('label', '', 'Job ' + (i + 1));
      const input = document.createElement('input');
      input.type        = 'text';
      input.maxLength   = 80;
      input.placeholder = H.placeholder;
      input.value       = existing[i] || '';
      input.id          = 'cq-' + half + '-job-' + i;
      label.htmlFor     = input.id;
      row.appendChild(label);
      row.appendChild(input);
      inputsWrap.appendChild(row);
    }
    card.appendChild(inputsWrap);

    const actions = el('div', 'cq-actions');
    if (half === 'junk') {
      const backBtn = el('button', 'cq-btn cq-btn-back', '← Back');
      backBtn.onclick = function () { step = 'halfway'; mount(); };
      actions.appendChild(backBtn);
    }
    const nextBtn = el('button', 'cq-btn', 'Next: Rank them');
    nextBtn.disabled = true;
    actions.appendChild(nextBtn);
    card.appendChild(actions);

    function values() {
      return Array.from(inputsWrap.querySelectorAll('input')).map(function (i) { return i.value.trim(); });
    }

    function updateCanProceed() {
      nextBtn.disabled = values().filter(function (v) { return v !== ''; }).length < 5;
    }
    inputsWrap.addEventListener('input', updateCanProceed);
    updateCanProceed();

    nextBtn.onclick = async function () {
      const vals  = values().filter(function (v) { return v !== ''; }).slice(0, 5);
      const lower = vals.map(function (v) { return v.toLowerCase(); });
      if (new Set(lower).size !== lower.length) { notice(card, 'Each job must be different.', 'warn'); return; }
      if (half === 'junk') {
        const dreams = state.dream.items.map(function (v) { return v.toLowerCase(); });
        const clash  = vals.find(function (v) { return dreams.indexOf(v.toLowerCase()) !== -1; });
        if (clash) { notice(card, '"' + clash + '" is one of your dream jobs — pick a job you would NOT want to do.', 'warn'); return; }
      }
      try {
        nextBtn.disabled    = true;
        nextBtn.textContent = 'Saving...';
        const body = { step: H.saveStep };
        body[H.payloadKey] = vals;
        const res = await post(body);
        if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Save failed');
        const saved = res.items || res.jobs || vals;
        if (JSON.stringify(saved) !== JSON.stringify(S.items)) S.reasons = {};
        S.items   = saved;
        S.ranking = saved.slice();
        step = half + '_rank';
        mount();
      } catch (err) {
        console.error('Save error:', err);
        notice(card, err.message, 'warn');
        nextBtn.disabled    = false;
        nextBtn.textContent = 'Next: Rank them';
      }
    };

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  /* ================================================================
     SCREEN — Rank (both halves)
     ================================================================ */
  function renderRank(half) {
    const H    = HALVES[half];
    const S    = state[half];
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card');
    header(card, H.rankTitle, H.label);
    card.appendChild(el('p', 'cq-sub', H.rankSub));

    const list = el('ul', 'cq-list');
    S.ranking.forEach(function (job) { list.appendChild(makeItem(job)); });
    card.appendChild(list);
    enableDnD(list);

    const actions = el('div', 'cq-actions');
    const backBtn = el('button', 'cq-btn cq-btn-back', '← Back');
    const nextBtn = el('button', 'cq-btn', 'Next: Explain why');
    actions.appendChild(backBtn);
    actions.appendChild(nextBtn);
    card.appendChild(actions);

    backBtn.onclick = async function () {
      try { await post({ step: H.backToInput }); } catch (err) { console.error('Back error:', err); }
      step = half + '_input';
      mount();
    };

    nextBtn.onclick = async function () {
      const ranking = Array.from(list.querySelectorAll('.cq-item')).map(function (li) { return li.dataset.job; });
      try {
        nextBtn.disabled    = true;
        nextBtn.textContent = 'Saving…';
        const res = await post({ step: H.rankStep, ranking: ranking });
        if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Save failed');
        S.ranking = ranking;
        step = half + '_reasons';
        mount();
      } catch (err) {
        console.error('Ranking save error:', err);
        notice(card, 'Failed to save ranking: ' + err.message, 'warn');
        nextBtn.disabled    = false;
        nextBtn.textContent = 'Next: Explain why';
      }
    };

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  /* ================================================================
     SCREEN — Reasons (both halves)
     ================================================================ */
  function renderReasons(half) {
    const H    = HALVES[half];
    const S    = state[half];
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card');
    header(card, H.reasonsTitle, H.label);
    card.appendChild(el('p', 'cq-sub', H.reasonsSub));

    const reasonsWrap = el('div', 'cq-reasons-wrap');
    const actions     = el('div', 'cq-actions');
    const backBtn     = el('button', 'cq-btn cq-btn-back', '← Back');
    const nextBtn     = el('button', 'cq-btn', H.genLabel);
    nextBtn.disabled  = true;

    function updateCanProceed() {
      const textareas = Array.from(reasonsWrap.querySelectorAll('textarea'));
      nextBtn.disabled = textareas.length < S.ranking.length || !textareas.every(function (ta) {
        return wordCount(ta.value) >= MIN_WORDS;
      });
    }

    S.ranking.forEach(function (job, idx) {
      const jobCard  = el('div', 'cq-reason-card');
      const jobTitle = el('h4', '', (idx + 1) + '. ' + job);
      const textarea = document.createElement('textarea');
      textarea.placeholder = H.reasonsPh;
      textarea.value       = S.reasons[job] || '';
      textarea.dataset.job = job;
      textarea.setAttribute('aria-label', 'Reason for ' + job);
      const counter = el('div', 'cq-word-count', '0 words');

      textarea.addEventListener('input', function () {
        const wc = wordCount(textarea.value);
        counter.textContent = wc + ' / ' + MIN_WORDS + ' words';
        counter.style.color = wc >= MIN_WORDS ? '#00FF88' : (wc > 0 ? '#00D4FF' : '#888888');
        updateCanProceed();
      });

      jobCard.appendChild(jobTitle);
      jobCard.appendChild(textarea);
      jobCard.appendChild(counter);
      reasonsWrap.appendChild(jobCard);
      textarea.dispatchEvent(new Event('input'));
    });
    card.appendChild(reasonsWrap);

    actions.appendChild(backBtn);
    actions.appendChild(nextBtn);
    card.appendChild(actions);
    updateCanProceed();

    function collect() {
      Array.from(reasonsWrap.querySelectorAll('textarea')).forEach(function (ta) {
        S.reasons[ta.dataset.job] = ta.value.trim();
      });
    }

    backBtn.onclick = async function () {
      collect();
      try { await post({ step: H.backToRank }); } catch (err) { console.error('Back error:', err); }
      step = half + '_rank';
      mount();
    };

    nextBtn.onclick = async function () {
      collect();
      nextBtn.disabled    = true;
      nextBtn.textContent = 'Steve is thinking…';
      try {
        const res = await runGeneration(H.genStep, { ranking: S.ranking, reasons: S.reasons }, H.loading);
        if (isUnavailable(res)) {
          notice(card, res.message || cfg.unavailableMsg, 'break');
          nextBtn.disabled    = false;
          nextBtn.textContent = H.genLabel;
          return;
        }
        afterGeneration(H.genStep, res);
      } catch (err) {
        console.error('Analysis error:', err);
        notice(card, 'Something went wrong: ' + err.message, 'warn');
        nextBtn.disabled    = false;
        nextBtn.textContent = H.genLabel;
      }
    };

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  async function runGeneration(stepName, extra, messages) {
    showLoadingOverlay(messages[0]);
    let idx = 0;
    const timer = setInterval(function () {
      idx++;
      if (idx < messages.length) updateLoadingText(messages[idx]);
    }, 3500);
    try {
      return await post(Object.assign({ step: stepName }, extra || {}));
    } finally {
      clearInterval(timer);
      hideLoadingOverlay();
    }
  }

  // Routes the flow after either AI step returns successfully.
  function afterGeneration(stepName, res) {
    if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Analysis failed');
    if (stepName === 'generate_dream_analysis') {
      data.dream_summaries = res.dream_summaries || [];
      data.fit             = res.fit || {};
      state.dream.ranking  = res.dream_ranking || state.dream.ranking;
      step = res.next === 'generate_analysis' ? 'auto_analysis' : 'halfway';
    } else {
      const keptDream = data.dream_summaries || [];
      applyData(res);
      // "Save AI summary" off: this session's dream texts weren't saved, so keep them.
      if (!(data.dream_summaries || []).some(Boolean) && keptDream.some(Boolean)) data.dream_summaries = keptDream;
      isLegacy    = false;
      chatContext = res.chat_context || '';
      step        = 'results';
    }
    mount();
  }

  /* ================================================================
     SCREEN — Halfway card
     ================================================================ */
  function renderHalfway() {
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card cq-halfway');
    card.appendChild(el('div', 'cq-halfway-emoji', '🎉'));
    card.appendChild(el('h2', 'cq-title', "Nice one, Steve's got your dream jobs."));
    card.appendChild(el('p', 'cq-sub', "Now the fun bit: jobs you'd hate!"));
    const go = el('button', 'cq-btn', "Let's go →");
    go.type = 'button';
    // §2.1: optional video; the button is always visible, so the video never blocks.
    const hv = cfg.halfwayVideo;
    if (hv && hv.url) {
      const v = document.createElement('video');
      v.className = 'cq-halfway-video';
      v.controls = true;
      v.setAttribute('playsinline', '');
      v.preload = 'metadata';
      if (hv.poster) v.poster = hv.poster;
      v.src = hv.url;
      v.addEventListener('ended', function () { go.focus(); });
      card.appendChild(v);
    }
    card.appendChild(el('p', 'cq-muted', "Next you'll pick 5 jobs you would NOT want to do. Your dream job results are saved — you'll see everything together at the end."));
    const actions = el('div', 'cq-actions cq-actions-center');
    go.onclick = function () { step = 'junk_input'; mount(); };
    actions.appendChild(go);
    card.appendChild(actions);
    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  /* ================================================================
     AUTO ANALYSIS / REGENERATION (legacy rows, "Save AI summary" off)
     ================================================================ */
  async function renderAutoAnalysis(regenerateDream) {
    root.replaceChildren(el('div', 'cq-wrap', ''));
    try {
      if (regenerateDream) {
        const d = await runGeneration('generate_dream_analysis', {}, HALVES.dream.loading);
        if (isUnavailable(d)) return renderBreak();
        if (!d || !d.ok) throw new Error((d && d.error) || 'Generation failed');
      }
      const res = await runGeneration('generate_analysis', {}, HALVES.junk.loading);
      if (isUnavailable(res)) return renderBreak();
      afterGeneration('generate_analysis', res);
    } catch (err) {
      console.error('Generation error:', err);
      renderBreak(err.message);
    }
  }

  function renderBreak(msg) {
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card');
    header(card, 'Dream & Junk Jobs');
    card.appendChild(el('div', 'cq-notice cq-notice--break', msg || cfg.unavailableMsg));
    const actions = el('div', 'cq-actions cq-actions-center');
    const retry = el('button', 'cq-btn', 'Try again');
    retry.onclick = function () { step = 'loading'; mount(); checkStatus(); };
    actions.appendChild(retry);
    card.appendChild(actions);
    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  /* ================================================================
     PARENT — child hasn't finished
     ================================================================ */
  function renderParentWaiting() {
    const name   = cfg.childName || 'Your child';
    const labels = {
      not_started: 'Not started yet', dream_in_progress: 'Entering dream jobs', dream_reasons: 'Writing dream job reasons',
      dream_done: 'Dream half done', in_progress: 'Entering junk jobs', reasons: 'Writing junk job reasons',
      commit: 'Choosing their action', completed: 'Dream half still to do'
    };
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card');
    header(card, 'Dream & Junk Jobs');
    card.appendChild(el('p', 'cq-sub', name + " hasn't finished Dream & Junk Jobs yet."));
    card.appendChild(el('p', 'cq-muted', 'Progress: ' + (labels[data.status] || 'In progress')));
    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  /* ================================================================
     SCREEN — Results (3 tabs)
     ================================================================ */
  function bandBadge(fit) {
    const b = fit && BANDS[fit.fit];
    if (!b) return null;
    return el('span', 'cq-band cq-band--' + fit.fit, b.icon + ' ' + b.label);
  }

  function ticksRow(job, reqs) {
    const m = (data.match || {})[job];
    if (!m || !reqs.length) return null;
    const row = el('div', 'cq-ticks');
    reqs.forEach(function (r) {
      if (m.met.indexOf(r) !== -1)          row.appendChild(el('span', 'cq-tick cq-tick--met', '✓ ' + reqLabel(r)));
      else if (m.missing.indexOf(r) !== -1) row.appendChild(el('span', 'cq-tick cq-tick--miss', '✗ ' + reqLabel(r)));
    });
    row.appendChild(el('span', 'cq-tick-count', m.met.length + ' of ' + reqs.length));
    return row;
  }

  function steveBox(text, cls) {
    const box = el('div', cls || 'cq-job-result-ai');
    box.innerHTML = formatInline(text);
    return box;
  }

  function renderBigPicture(panel, reqs) {
    if (needsCommitment()) {
      const banner = el('button', 'cq-commit-banner');
      banner.type = 'button';
      banner.appendChild(el('strong', null, 'One last step to finish: '));
      banner.appendChild(document.createTextNode('pick your one thing to do this week ↓'));
      banner.onclick = function () {
        const t = document.getElementById('cq-commit-panel');
        if (t) { t.scrollIntoView({ behavior: REDUCED ? 'auto' : 'smooth', block: 'start' }); const f = t.querySelector('button, textarea'); if (f) f.focus({ preventScroll: true }); }
      };
      panel.appendChild(banner);
    }
    if (data.intro_text) panel.appendChild(steveBox(data.intro_text, 'cq-analysis-opener-box'));

    // "You, the …" card
    if (data.profile) {
      const pc = el('div', 'cq-profile-card');
      if (data.profile.avatar_url) {
        const img = document.createElement('img');
        img.src = data.profile.avatar_url;
        img.alt = data.profile.name;
        img.onerror = function () { img.remove(); };
        pc.appendChild(img);
      }
      const txt = el('div', 'cq-profile-text');
      txt.appendChild(el('div', 'cq-profile-name', 'You, ' + data.profile.name.replace(/^The /, 'the ')));
      txt.appendChild(el('div', 'cq-profile-family', data.profile.family + ' family'));
      pc.appendChild(txt);
      panel.appendChild(pc);
    } else if (data.personality_state === 'none') {
      panel.appendChild(el('div', 'cq-unlock-card', '🔒 Complete Who Am I to unlock your Personality Fit'));
    } else if (data.personality_state === 'test_mode' && cfg.isAdmin) {
      panel.appendChild(el('div', 'cq-notice cq-notice--warn', 'Admin: Personality data off — Who Am I is in test mode.'));
    }

    if (reqs.length) {
      panel.appendChild(el('h3', 'cq-section-title', 'What you need from a career'));
      const chips = el('div', 'cq-chips');
      reqs.slice(0, 5).forEach(function (r) { chips.appendChild(el('span', 'cq-chip cq-chip--req', r)); });
      panel.appendChild(chips);
    }

    panel.appendChild(el('h3', 'cq-section-title', 'How your dream jobs stack up'));
    const stack = el('div', 'cq-stack');
    state.dream.ranking.forEach(function (job, i) {
      const row  = el('div', 'cq-stack-row');
      const top  = el('div', 'cq-stack-top');
      top.appendChild(el('span', 'cq-stack-name', '#' + (i + 1) + ' ' + job));
      const badge = bandBadge((data.fit || {})[job]);
      if (badge) top.appendChild(badge);
      row.appendChild(top);
      const t = ticksRow(job, reqs);
      if (t) row.appendChild(t);
      stack.appendChild(row);
    });
    panel.appendChild(stack);

    const twists = state.junk.ranking.filter(function (j) { return (data.check || {})[j] === 'twist'; });
    if (twists.length) {
      panel.appendChild(el('h3', 'cq-section-title', 'Plot twists'));
      twists.forEach(function (j) {
        panel.appendChild(el('div', 'cq-twist', '🟡 You avoided ' + j + ', but it uses some of your natural strengths. Is it the job, or the idea of it?'));
      });
    }

    if (data.conclusion) {
      panel.appendChild(el('h3', 'cq-section-title', "Steve's summary"));
      panel.appendChild(steveBox(data.conclusion, 'cq-conclusion-box'));
    }

    renderCommitment(panel);

    (data.guesses || []).forEach(function (g) {
      panel.appendChild(el('div', 'cq-guess-line', '🃏 ' + g.name + ' guessed ' + g.score + ' of ' + g.total + ' of your jobs.'
        + (g.coins ? ' You earned ' + g.coins + ' bonus coins!' : '')));
    });

    if (data.also_like && data.also_like.length) {
      panel.appendChild(el('h3', 'cq-section-title', 'Jobs people like you often enjoy'));
      const chips = el('div', 'cq-chips');
      data.also_like.forEach(function (c) { chips.appendChild(el('span', 'cq-chip', c)); });
      panel.appendChild(chips);
    }
  }

  function renderDreamTab(panel, reqs) {
    state.dream.ranking.forEach(function (job, idx) {
      const block = el('div', 'cq-job-result-block');
      const head  = el('div', 'cq-job-result-header');
      head.appendChild(el('span', 'cq-job-result-num', (idx + 1) + '.'));
      head.appendChild(el('span', 'cq-job-result-name', job));
      const fit   = (data.fit || {})[job];
      const badge = bandBadge(fit);
      if (badge) head.appendChild(badge);
      block.appendChild(head);

      if (state.dream.reasons[job]) block.appendChild(el('div', 'cq-job-result-reason', '"' + state.dream.reasons[job] + '"'));

      if (fit) {
        const fitBox = el('div', 'cq-fit');
        if (fit.helps && fit.helps.length) fitBox.appendChild(el('div', 'cq-fit-line', '✅ What helps you: ' + fit.helps.join(', ')));
        if (fit.stretch) fitBox.appendChild(el('div', 'cq-fit-line', '🌱 Your stretch: ' + fit.stretch));
        if (fit.bridge)  fitBox.appendChild(el('div', 'cq-fit-line', '🌉 Bridge it: ' + fit.bridge));
        if (fitBox.childNodes.length) block.appendChild(fitBox);
      }

      const summary = (data.dream_summaries || [])[idx];
      if (summary) {
        block.appendChild(el('div', 'cq-job-result-ai-label', 'Steve says:'));
        block.appendChild(steveBox(summary));
      }
      const t = ticksRow(job, reqs);
      if (t) block.appendChild(t);
      panel.appendChild(block);
    });

    // 7a. Careers chat — moved (never copied) so the widget keeps its event bindings.
    if (!PARENT_VIEW && cfg.hasChat && chatSource && data.status === 'completed') {
      chatWidget = chatWidget || chatSource.querySelector('.stevegpt-chatbot-container');
      const widget = chatWidget;
      if (widget) {
        if (chatContext) widget.setAttribute('data-context', chatContext);
        const chatCard = el('div', 'cq-chat-card');
        chatCard.appendChild(el('h3', 'cq-section-title', 'Ask Steve about your dream jobs'));
        const top   = state.dream.ranking[0] || 'my top dream job';
        const chips = el('div', 'cq-chips cq-starters');
        ['What GCSEs do I need for ' + top + '?', 'What could I do this year to get closer to ' + top + '?', 'Which of my dream jobs pays the most?']
          .forEach(function (q) {
            const b = el('button', 'cq-chip cq-chip--starter', q);
            b.type = 'button';
            b.onclick = function () { sendStarter(widget, q); };
            chips.appendChild(b);
          });
        chatCard.appendChild(chips);
        const chatWrap = el('div', 'cq-chatwrap');
        chatWrap.appendChild(widget);
        chatCard.appendChild(chatWrap);
        panel.appendChild(chatCard);
      }
    }
  }

  function sendStarter(widget, text) {
    const input = widget.querySelector('.stevegpt-input-field');
    const send  = widget.querySelector('.stevegpt-send-button');
    if (!input || !send) return;
    input.value = text;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    send.click();
  }

  function renderJunkTab(panel) {
    const jobSummaries = data.job_summaries || [];
    state.junk.ranking.forEach(function (job, idx) {
      const block = el('div', 'cq-job-result-block');
      const head  = el('div', 'cq-job-result-header');
      head.appendChild(el('span', 'cq-job-result-num', (idx + 1) + '.'));
      head.appendChild(el('span', 'cq-job-result-name', job));
      block.appendChild(head);

      const chips = el('div', 'cq-chips');
      const req   = (data.requirements || {})[job];
      if (req) chips.appendChild(el('span', 'cq-chip cq-chip--req', req));
      const chk = (data.check || {})[job];
      if (chk === 'agrees') chips.appendChild(el('span', 'cq-chip cq-chip--agree', '🟢 Your personality agrees'));
      if (chk === 'twist')  chips.appendChild(el('span', 'cq-chip cq-chip--twist', '🟡 Plot twist!'));
      if (chips.childNodes.length) block.appendChild(chips);

      if (state.junk.reasons[job]) block.appendChild(el('div', 'cq-job-result-reason', '"' + state.junk.reasons[job] + '"'));
      if (jobSummaries[idx]) {
        block.appendChild(el('div', 'cq-job-result-ai-label', 'Steve says:'));
        block.appendChild(steveBox(jobSummaries[idx]));
      }
      panel.appendChild(block);
    });
  }

  function renderResults() {
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card');
    header(card, 'Your Dream & Junk Jobs');
    const reqs = uniqueRequirements();

    const tabs   = el('div', 'cq-tabs');
    tabs.setAttribute('role', 'tablist');
    const panels = {};
    [['big', 'The Big Picture'], ['dream', 'Dream Jobs'], ['junk', 'Junk Jobs']].forEach(function (t, i) {
      const b = el('button', 'cq-tab' + (i === 0 ? ' is-active' : ''), t[1]);
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.dataset.tab = t[0];
      tabs.appendChild(b);
      panels[t[0]] = el('div', 'cq-tab-panel' + (i === 0 ? ' is-active' : ''));
    });
    tabs.addEventListener('click', function (e) {
      const b = e.target.closest('.cq-tab');
      if (!b) return;
      tabs.querySelectorAll('.cq-tab').forEach(function (x) { x.classList.toggle('is-active', x === b); });
      Object.keys(panels).forEach(function (k) { panels[k].classList.toggle('is-active', k === b.dataset.tab); });
    });
    card.appendChild(tabs);

    renderBigPicture(panels.big, reqs);
    renderDreamTab(panels.dream, reqs);
    renderJunkTab(panels.junk);
    Object.keys(panels).forEach(function (k) { card.appendChild(panels[k]); });

    if (!PARENT_VIEW) {
      const navWrap   = el('div', 'cq-nav-actions');
      const badgesBtn = document.createElement('a');
      badgesBtn.className   = 'cq-btn cq-btn-back';
      badgesBtn.href        = cfg.urlBadges || 'https://mfsd.me/badges/';
      badgesBtn.textContent = 'View My Badges';
      const courseBtn = document.createElement('a');
      courseBtn.className   = 'cq-btn';
      courseBtn.href        = cfg.urlCourse || 'https://mfsd.me/about/parent-portal-home/?course_id=1';
      courseBtn.textContent = 'Back to Course';
      navWrap.appendChild(badgesBtn);
      navWrap.appendChild(courseBtn);
      card.appendChild(navWrap);
    }

    wrap.appendChild(card);
    root.replaceChildren(wrap);
  }

  /* ================================================================
     COMMITMENT (spec §15) — required; no "Not now"
     ================================================================ */
  function needsCommitment() {
    return !PARENT_VIEW && (data.status === 'commit' || (data.status === 'completed' && !data.chosen_action));
  }

  const CARD_STATUS = { open: 'On your home screen', checked_in: 'Waiting for a grown-up to confirm', validated: 'Done — confirmed!',
    carried: 'Carried into this week', not_yet: 'Not yet', dropped: 'Dropped', expired: 'Expired' };

  function renderCommitment(panel) {
    const box = el('div', 'cq-commit');
    box.id = 'cq-commit-panel';

    if (!needsCommitment()) {
      if (!data.chosen_action) return;
      box.appendChild(el('h3', 'cq-section-title', PARENT_VIEW ? 'Their action' : 'Your action'));
      const line = el('div', 'cq-commit-done');
      line.appendChild(el('span', 'cq-commit-kind', KINDS[data.chosen_action] || 'Action'));
      line.appendChild(el('span', 'cq-commit-text', data.commitment_text || ''));
      box.appendChild(line);
      if (data.commitment) box.appendChild(el('div', 'cq-muted', CARD_STATUS[data.commitment.status] || data.commitment.status));
      else if (data.commitments_active === false) box.appendChild(el('div', 'cq-muted', 'Saved'));
      panel.appendChild(box);
      return;
    }

    const finishing = data.status === 'commit';
    box.appendChild(el('h3', 'cq-section-title', 'Pick your one thing'));
    box.appendChild(el('p', 'cq-sub', 'Choose one small step for this week. You can change the words to make it yours.'));

    const actions = data.actions || {};
    const kinds   = ['skill', 'explore', 'habit'].filter(function (k) { return actions[k]; }).concat(['own']);
    const group   = el('div', 'cq-commit-options');
    group.setAttribute('role', 'radiogroup');
    group.setAttribute('aria-label', 'Your action');
    let chosen = '';

    const field = document.createElement('textarea');
    field.className = 'cq-textarea cq-commit-input';
    field.maxLength = 140;
    field.rows = 3;
    field.setAttribute('aria-label', 'Your action in your own words');
    field.placeholder = 'Write your one thing (up to 140 characters)';
    const counter = el('div', 'cq-commit-count', '0 / 140');
    const editWrap = el('div', 'cq-commit-edit');
    editWrap.hidden = true;
    editWrap.appendChild(field);
    editWrap.appendChild(counter);

    const save = el('button', 'cq-btn cq-commit-save', finishing ? 'Add to my home screen and finish' : 'Add to my home screen');
    save.type = 'button';
    save.disabled = true;

    function sync() {
      counter.textContent = field.value.length + ' / 140';
      save.disabled = !chosen || field.value.trim() === '';
    }
    field.addEventListener('input', sync);

    kinds.forEach(function (k) {
      const b = el('button', 'cq-commit-option');
      b.type = 'button';
      b.setAttribute('role', 'radio');
      b.setAttribute('aria-checked', 'false');
      b.dataset.kind = k;
      b.appendChild(el('span', 'cq-commit-kind', KINDS[k]));
      if (k !== 'own') b.appendChild(el('span', 'cq-commit-text', actions[k]));
      b.onclick = function () {
        chosen = k;
        group.querySelectorAll('.cq-commit-option').forEach(function (x) {
          x.classList.toggle('is-on', x === b);
          x.setAttribute('aria-checked', x === b ? 'true' : 'false');
        });
        field.value = k === 'own' ? '' : String(actions[k]).slice(0, 140);
        editWrap.hidden = false;
        sync();
        field.focus();
      };
      group.appendChild(b);
    });
    box.appendChild(group);
    box.appendChild(editWrap);

    const act = el('div', 'cq-actions');
    act.appendChild(save);
    box.appendChild(act);

    save.onclick = async function () {
      save.disabled = true;
      try {
        const res = await post({ step: 'save_commitment', kind: chosen, text: field.value.trim() });
        if (!res || res.ok === false) throw new Error((res && res.error) || 'Could not save');
        applyData(res);
        if (res.chat_context) chatContext = res.chat_context;
        mount();
        const done = document.getElementById('cq-commit-panel');
        if (done) {
          done.insertBefore(el('div', 'cq-notice cq-notice--ok', finishing ? '🎉 Brilliant — that\'s Dream & Junk Jobs finished! Your badges are on their way.' : '🎉 Added to your home screen!'), done.firstChild);
          done.scrollIntoView({ behavior: REDUCED ? 'auto' : 'smooth', block: 'start' });
        }
      } catch (err) {
        notice(box, err.message || 'Something went wrong. Please try again.', 'warn');
        save.disabled = false;
      }
    };
    panel.appendChild(box);
  }

  /* ================================================================
     PARENT GUESSING GAME (spec §16)
     ================================================================ */
  const ANSWERS = {
    love:    { label: 'Love it', icon: '❤️', key: 'ArrowRight' },
    neither: { label: 'Neither', icon: '🤷', key: 'ArrowUp' },
    hate:    { label: 'Hate it', icon: '👎', key: 'ArrowLeft' }
  };
  let game = null;
  let gameKeys = null;

  function guessUrl(path) {
    return cfg.restUrlGuess + (path || '') + (path ? '' : '?student_id=' + encodeURIComponent(cfg.studentId) + '&_=' + Date.now());
  }

  function guessPost(path, body) {
    return apiFetch(cfg.restUrlGuess + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body: JSON.stringify(Object.assign({ student_id: cfg.studentId }, body || {}))
    });
  }

  // Answers are saved one at a time, in order, with retries (re-review: a busy server returned 409
  // and the answer was silently dropped, leaving the parent stuck on "Answer every card first").
  let saveChain = Promise.resolve();
  function saveAnswer(job, a) {
    const attempt = async function (n) {
      try { await guessPost('/answer', { job: job, answer: a }); }
      catch (err) {
        if (n >= 3 || /Already finished/i.test(err.message)) { console.error('Answer not saved:', err); return; }
        await new Promise(function (r) { setTimeout(r, 400 * (n + 1)); });
        return attempt(n + 1);
      }
    };
    saveChain = saveChain.then(function () { return attempt(0); });
    return saveChain;
  }
  /** Before finishing: wait for queued saves, then re-send every answer once if the server is missing any. */
  async function finishGame() {
    await saveChain;
    try { return await guessPost('/finish'); }
    catch (err) {
      if (!/Answer every card/i.test(err.message)) throw err;
      Object.keys(game.answers).forEach(function (job) { saveAnswer(job, game.answers[job]); });
      await saveChain;
      return guessPost('/finish');
    }
  }

  async function renderGame() {
    root.replaceChildren(el('div', 'cq-wrap', 'Loading...'));
    try {
      const res = await apiFetch(guessUrl(''));
      if (res.done) return renderReveal(res);
      game = { deck: res.deck || [], answers: res.answers || {}, history: [], child: res.child_name || cfg.childName || 'your child' };
      drawGameCard();
    } catch (err) {
      console.error('Guess error:', err);
      root.replaceChildren(el('div', 'cq-wrap', "Sorry, the guessing game couldn't load. Please try again in a moment."));
    }
  }

  function nextIndex() {
    for (let i = 0; i < game.deck.length; i++) if (!game.answers[game.deck[i]]) return i;
    return -1;
  }

  function drawGameCard() {
    const wrap = el('div', 'cq-wrap');
    const card = el('div', 'cq-card cq-game');
    header(card, 'Love it, hate it or neither?');
    const idx = nextIndex();
    const answered = game.deck.length - game.deck.filter(function (j) { return !game.answers[j]; }).length;

    const undo = el('button', 'cq-btn cq-btn-back cq-game-undo', '↶ Undo');
    undo.type = 'button';
    undo.disabled = !game.history.length;
    undo.onclick = undoLast;

    if (idx === -1) {
      card.appendChild(el('p', 'cq-sub', "That's all " + game.deck.length + ' cards!'));
      const actions = el('div', 'cq-actions');
      actions.appendChild(undo);
      const fin = el('button', 'cq-btn', 'See how I did →');
      fin.type = 'button';
      fin.onclick = async function () {
        fin.disabled = true;
        try { renderReveal(await finishGame()); }
        catch (err) { notice(card, err.message, 'warn'); fin.disabled = false; }
      };
      actions.appendChild(fin);
      card.appendChild(actions);
      wrap.appendChild(card);
      root.replaceChildren(wrap);
      fin.focus();
      return;
    }

    const job = game.deck[idx];
    const q = el('p', 'cq-sub');
    q.appendChild(document.createTextNode('Would ' + game.child + ' '));
    q.appendChild(el('strong', null, 'love'));
    q.appendChild(document.createTextNode(' this job, '));
    q.appendChild(el('strong', null, 'hate'));
    q.appendChild(document.createTextNode(' it, or is it '));
    q.appendChild(el('strong', null, 'neither'));
    q.appendChild(document.createTextNode('?'));
    card.appendChild(q);
    card.appendChild(el('div', 'cq-game-progress', (answered + 1) + ' / ' + game.deck.length));

    const stage = el('div', 'cq-game-stage');
    const jc = el('div', 'cq-game-card', job);
    jc.setAttribute('aria-live', 'polite');
    stage.appendChild(jc);
    card.appendChild(stage);
    enableSwipe(jc);

    const btns = el('div', 'cq-game-buttons');
    ['hate', 'neither', 'love'].forEach(function (a) {
      const b = el('button', 'cq-game-btn cq-game-btn--' + a);
      b.type = 'button';
      b.appendChild(el('span', 'cq-game-icon', ANSWERS[a].icon));
      b.appendChild(el('span', null, ANSWERS[a].label));
      b.onclick = function () { answer(a); };
      btns.appendChild(b);
    });
    card.appendChild(btns);
    card.appendChild(el('p', 'cq-muted cq-game-hint', 'Swipe right for love, left for hate, up for neither — or use the buttons or arrow keys.'));
    const actions = el('div', 'cq-actions');
    actions.appendChild(undo);
    card.appendChild(actions);
    wrap.appendChild(card);
    root.replaceChildren(wrap);

    if (!gameKeys) {
      gameKeys = function (e) {
        if (step !== 'game' || !game || e.target.closest('input, textarea')) return;
        const a = Object.keys(ANSWERS).find(function (k) { return ANSWERS[k].key === e.key; });
        if (a && nextIndex() !== -1) { e.preventDefault(); answer(a); }
      };
      document.addEventListener('keydown', gameKeys);
    }
  }

  function fling(a, done) {
    const c = root.querySelector('.cq-game-card');
    if (!c || REDUCED) return done();
    c.classList.add('is-fling-' + a);
    setTimeout(done, 220);
  }

  let busy = false;
  function answer(a) {
    if (busy) return;
    const idx = nextIndex();
    if (idx === -1) return;
    const job = game.deck[idx];
    busy = true;
    fling(a, async function () {
      game.answers[job] = a;
      game.history.push(job);
      drawGameCard();
      busy = false;
      saveAnswer(job, a);
    });
  }

  async function undoLast() {
    const job = game.history.pop();
    if (!job) return;
    delete game.answers[job];
    drawGameCard();
    saveAnswer(job, '');
  }

  function enableSwipe(card) {
    let sx = 0, sy = 0, dx = 0, dy = 0, active = false;
    card.addEventListener('pointerdown', function (e) {
      active = true; sx = e.clientX; sy = e.clientY; dx = dy = 0;
      try { card.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ }
    });
    card.addEventListener('pointermove', function (e) {
      if (!active) return;
      dx = e.clientX - sx; dy = e.clientY - sy;
      if (!REDUCED) card.style.transform = 'translate(' + dx + 'px,' + Math.min(dy, 0) + 'px) rotate(' + (dx / 20) + 'deg)';
    });
    function end() {
      if (!active) return;
      active = false;
      card.style.transform = '';
      if (dx > 80 && Math.abs(dx) > Math.abs(dy)) answer('love');
      else if (dx < -80 && Math.abs(dx) > Math.abs(dy)) answer('hate');
      else if (dy < -80) answer('neither');
    }
    card.addEventListener('pointerup', end);
    card.addEventListener('pointercancel', function () { active = false; card.style.transform = ''; });
  }

  function truthLabel(c) {
    if (c.list === 'dream') return 'Dream job #' + c.rank;
    if (c.list === 'junk')  return 'Junk job #' + c.rank;
    return 'Not on their lists';
  }

  function renderReveal(res) {
    const child = res.child_name || cfg.childName || 'Your child';
    const wrap  = el('div', 'cq-wrap');
    const card  = el('div', 'cq-card cq-reveal');
    header(card, 'You got ' + res.score + ' of ' + res.total + '!');
    if (res.coins) card.appendChild(el('p', 'cq-sub', child + ' earned ' + res.coins + ' bonus coins from your guesses.'));

    const surprises = (res.cards || []).filter(function (c) { return c.list !== 'decoy' && c.answer && c.answer !== c.truth; }).slice(0, 3);
    surprises.forEach(function (c) {
      const verb = c.answer === 'neither' ? 'not mind either way about' : c.answer;
      card.appendChild(el('div', 'cq-twist', '😮 You thought ' + child + ' would ' + verb + ' ' + c.job + '… it\'s their #' + c.rank + ' ' + (c.list === 'dream' ? 'dream' : 'junk') + ' job!'));
    });

    const list = el('ul', 'cq-reveal-list');
    (res.cards || []).forEach(function (c) {
      const li = el('li', 'cq-reveal-row ' + (c.answer === c.truth ? 'is-right' : 'is-wrong'));
      li.appendChild(el('span', 'cq-reveal-mark', c.answer === c.truth ? '✓' : '✗'));
      li.appendChild(el('span', 'cq-reveal-job', c.job));
      li.appendChild(el('span', 'cq-reveal-ans', 'You: ' + ((ANSWERS[c.answer] || {}).label || '—')));
      li.appendChild(el('span', 'cq-reveal-truth', truthLabel(c)));
      list.appendChild(li);
    });
    card.appendChild(list);

    const actions = el('div', 'cq-actions cq-actions-center');
    const go = el('button', 'cq-btn', 'See ' + child + "'s results →");
    go.type = 'button';
    go.onclick = function () { window.location.reload(); };
    actions.appendChild(go);
    card.appendChild(actions);
    wrap.appendChild(card);
    root.replaceChildren(wrap);
    go.focus();
  }

  /* ================================================================
     PARENT PANEL (lazy — may call SteveGPT on first open)
     ================================================================ */
  async function loadParentPanel() {
    const target = document.getElementById('ai-junk-jobs-parent-root');
    if (!PARENT_VIEW || !target || !cfg.restUrlParent) return;
    try {
      const res = await apiFetch(cfg.restUrlParent + '?student_id=' + encodeURIComponent(cfg.studentId) + '&_=' + Date.now());
      const tmp = document.createElement('div');
      tmp.innerHTML = res.html || ''; // server-side kses'd
      target.replaceWith.apply(target, Array.from(tmp.childNodes));
    } catch (err) {
      console.error('Parent summary error:', err);
      target.textContent = "Steve's parent summary isn't available right now. Please try again in a few minutes.";
    }
  }

  /* ================================================================
     MOUNT
     ================================================================ */
  function mount() {
    if      (step === 'loading')        root.textContent = 'Loading...';
    else if (step === 'dream_input')    renderInput('dream');
    else if (step === 'dream_rank')     renderRank('dream');
    else if (step === 'dream_reasons')  renderReasons('dream');
    else if (step === 'halfway')        renderHalfway();
    else if (step === 'junk_input')     renderInput('junk');
    else if (step === 'junk_rank')      renderRank('junk');
    else if (step === 'junk_reasons')   renderReasons('junk');
    else if (step === 'auto_analysis')  renderAutoAnalysis(false);
    else if (step === 'regenerating')   renderAutoAnalysis(data.needs_regeneration === 'dream');
    else if (step === 'parent_waiting') renderParentWaiting();
    else if (step === 'game')           renderGame();
    else                                renderResults();
  }

  if (PARENT_VIEW && cfg.parentGame) { step = 'game'; mount(); }
  else { checkStatus(); loadParentPanel(); }
})();
