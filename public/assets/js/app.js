
(() => {
  'use strict';

  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const csrf = qs('meta[name="csrf-token"]')?.content || '';

  const state = {
    language: localStorage.getItem('checklist_locale') || 'en',
    dictionary: {},
    languages: [],
    checklists: [],
    current: null,
    states: {},
    openSectionId: null,
    drawerOpen: false,
    menuOpen: { workspace: false, blocks: false, issues: false, tools: false },
    query: '',
    priorityFilter: 'all',
    statusFilter: 'all',
    modal: null,
    form: {},
    selectedFile: null,
    toastTimer: null,
    saveTimers: {},
    loading: true,
    error: '',
    exportForm: { scope: 'current', type: 'complete', filename: '' },
    importForm: { mode: 'new' },
  };

  function t(path) {
    const value = path.split('.').reduce((carry, key) => carry && carry[key] !== undefined ? carry[key] : null, state.dictionary);
    return value === null || value === undefined ? path : value;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  async function request(url, options = {}) {
    const headers = { 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) };
    if (!(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    const response = await fetch(url, { ...options, headers });
    let json = null;
    try { json = await response.json(); } catch (_) {}
    if (!response.ok || !json || json.ok === false) {
      throw new Error(json?.message || `Request failed (${response.status})`);
    }
    return json;
  }

  function taskState(taskId) {
    return state.states[taskId] || { done: false, problem: false, note: '' };
  }

  function totalTasks() {
    return (state.current?.sections || []).reduce((sum, section) => sum + (section.tasks || []).length, 0);
  }

  function doneCount() {
    return Object.values(state.states).filter(item => item.done).length;
  }

  function problemCount() {
    return Object.values(state.states).filter(item => item.problem).length;
  }

  function globalPercent() {
    const total = totalTasks();
    return total ? Math.round((doneCount() / total) * 100) : 0;
  }

  function sectionDoneCount(section) {
    return (section.tasks || []).filter(task => taskState(task.id).done).length;
  }

  function sectionProblemCount(section) {
    return (section.tasks || []).filter(task => taskState(task.id).problem).length;
  }

  function issuesList() {
    const issues = [];
    for (const section of state.current?.sections || []) {
      for (const task of section.tasks || []) {
        if (taskState(task.id).problem) issues.push({ section, task });
      }
    }
    return issues;
  }

  function priorityLabel(priority) {
    return t(`priorities.${priority}`);
  }

  function priorityClass(priority) {
    return `priority priority-${['critical','high','medium','low'].includes(priority) ? priority : 'medium'}`;
  }

  function defaultProblemText(task, section) {
    return `${t('problemTemplate.title')} — ${task.title}\n\n${t('problemTemplate.block')} : ${section.title}\n${t('problemTemplate.status')} : ${t('problemTemplate.statusValue')}\n\n${t('problemTemplate.description')} :\n- \n\n${t('problemTemplate.expected')} :\n- \n\n${t('problemTemplate.current')} :\n- \n\n${t('problemTemplate.files')} :\n- \n\n${t('problemTemplate.priority')} :\n- ${priorityLabel(task.priority)}\n\n${t('problemTemplate.notes')} :\n- `;
  }

  function taskMatches(task) {
    const s = taskState(task.id);
    const q = state.query.trim().toLowerCase();
    const haystack = `${task.title || ''} ${task.description || ''} ${s.note || ''}`.toLowerCase();
    const matchesQuery = !q || haystack.includes(q);
    const matchesPriority = state.priorityFilter === 'all' || task.priority === state.priorityFilter;
    const matchesStatus = state.statusFilter === 'all' ||
      (state.statusFilter === 'done' && s.done) ||
      (state.statusFilter === 'todo' && !s.done) ||
      (state.statusFilter === 'problem' && s.problem);
    return matchesQuery && matchesPriority && matchesStatus;
  }

  function filteredSections() {
    return (state.current?.sections || []).filter(section => {
      if (state.query.trim() === '' && state.priorityFilter === 'all' && state.statusFilter === 'all') return true;
      return (section.tasks || []).some(taskMatches);
    });
  }

  function showToast(message) {
    const toast = qs('#toast');
    toast.textContent = message;
    toast.hidden = false;
    clearTimeout(state.toastTimer);
    state.toastTimer = setTimeout(() => { toast.hidden = true; toast.textContent = ''; }, 3000);
  }

  function defaultExportName() {
    return `${state.current?.slug || 'checklist'}-${state.exportForm.type || 'complete'}-${new Date().toISOString().slice(0, 10)}.json`;
  }

  function setDrawer(open) {
    state.drawerOpen = open;
    document.body.style.overflow = open ? 'hidden' : '';
    renderDrawer();
  }

  function openSection(sectionId) {
    state.openSectionId = state.openSectionId === sectionId ? null : sectionId;
    renderSections();
  }

  function openAndScroll(taskOrSectionId, sectionId = null) {
    if (sectionId) state.openSectionId = sectionId;
    else state.openSectionId = taskOrSectionId;
    renderAll();
    requestAnimationFrame(() => {
      qs(`#${CSS.escape(taskOrSectionId)}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }

  async function bootstrap() {
    state.loading = true;
    state.error = '';
    renderAll();
    try {
      const data = await request(`/api/bootstrap?lang=${encodeURIComponent(state.language)}`);
      state.dictionary = data.dictionary || {};
      state.languages = data.languages || [];
      state.checklists = data.checklists || [];
      state.current = data.current || null;
      state.states = data.states || {};
      state.openSectionId = state.current?.sections?.[0]?.id || null;
      state.exportForm.filename = defaultExportName();
    } catch (error) {
      state.error = error.message || t('messages.loadingFailed');
    } finally {
      state.loading = false;
      renderAll();
    }
  }

  async function changeLanguage(lang) {
    state.language = lang;
    localStorage.setItem('checklist_locale', lang);
    await loadChecklist(state.current?.slug || state.checklists[0]?.slug || '');
  }

  async function loadChecklist(slug) {
    if (!slug) return;
    state.loading = true;
    renderAll();
    try {
      const data = await request(`/api/checklist?checklist=${encodeURIComponent(slug)}&lang=${encodeURIComponent(state.language)}`);
      state.dictionary = data.dictionary || state.dictionary;
      state.current = data.checklist;
      state.states = data.states || {};
      state.checklists = data.checklists || state.checklists;
      state.openSectionId = state.current?.sections?.[0]?.id || null;
      state.exportForm.filename = defaultExportName();
    } catch (error) {
      showToast(error.message);
    } finally {
      state.loading = false;
      renderAll();
    }
  }

  function renderHeader() {
    qs('#appKicker').textContent = t('app.kicker');
    qs('#appTitle').textContent = state.current?.title || t('app.title');
    qs('#menuButton').setAttribute('aria-label', t('actions.openMenu'));
    const languageSelect = qs('#languageSelect');
    languageSelect.innerHTML = state.languages.map(lang => `<option value="${escapeHtml(lang.code)}" ${lang.code === state.language ? 'selected' : ''}>${escapeHtml(lang.name)}</option>`).join('');
    qs('#exportTopButton').textContent = t('actions.export');
    qs('#newChecklistTopButton').textContent = t('actions.newChecklist');
  }

  function renderHero() {
    qs('#heroBadge').textContent = t('app.badge');
    qs('#heroTitle').textContent = state.current?.title || t('app.heroTitle');
    qs('#heroDescription').textContent = state.current?.description || t('app.heroDescription');
    qs('#offlineLabel').textContent = t('app.offline');
    qs('#statProgressLabel').textContent = t('stats.progress');
    qs('#statDoneLabel').textContent = t('stats.done');
    qs('#statIssuesLabel').textContent = t('stats.issues');
    qs('#statBlocksLabel').textContent = t('stats.blocks');
    qs('#statProgress').textContent = `${globalPercent()}%`;
    qs('#statDone').textContent = `${doneCount()}/${totalTasks()}`;
    qs('#statIssues').textContent = String(problemCount());
    qs('#statBlocks').textContent = String(state.current?.sections?.length || 0);
  }

  function renderControls() {
    const search = qs('#searchInput');
    search.placeholder = t('filters.search');
    if (document.activeElement !== search) search.value = state.query;
    qs('#priorityFilter').innerHTML = `
      <option value="all">${escapeHtml(t('filters.allPriorities'))}</option>
      <option value="critical">${escapeHtml(t('priorities.critical'))}</option>
      <option value="high">${escapeHtml(t('priorities.high'))}</option>
      <option value="medium">${escapeHtml(t('priorities.medium'))}</option>
      <option value="low">${escapeHtml(t('priorities.low'))}</option>`;
    qs('#priorityFilter').value = state.priorityFilter;
    qs('#statusFilter').innerHTML = `
      <option value="all">${escapeHtml(t('filters.allTasks'))}</option>
      <option value="todo">${escapeHtml(t('filters.todo'))}</option>
      <option value="done">${escapeHtml(t('filters.done'))}</option>
      <option value="problem">${escapeHtml(t('filters.problem'))}</option>`;
    qs('#statusFilter').value = state.statusFilter;
    qs('#addBlockButton').textContent = t('actions.addBlock');
  }

  function renderSections() {
    const root = qs('#sections');
    const empty = qs('#emptyState');

    if (state.loading) {
      root.innerHTML = `<div class="empty-card">${escapeHtml(t('app.loading'))}</div>`;
      empty.hidden = true;
      return;
    }
    if (state.error) {
      root.innerHTML = `<div class="error-box">${escapeHtml(state.error)}</div>`;
      empty.hidden = true;
      return;
    }
    if (!state.current) {
      root.innerHTML = `<div class="empty-card">${escapeHtml(t('app.noChecklist'))}</div>`;
      empty.hidden = true;
      return;
    }

    const sections = filteredSections();
    if (!sections.length) {
      root.innerHTML = '';
      empty.hidden = false;
      empty.textContent = t('labels.noResult');
      return;
    }
    empty.hidden = true;

    root.innerHTML = sections.map(section => {
      const done = sectionDoneCount(section);
      const problems = sectionProblemCount(section);
      const isOpen = state.openSectionId === section.id;
      const tasks = (section.tasks || []).filter(taskMatches);
      return `
        <article id="${escapeHtml(section.id)}" class="section-card ${isOpen ? 'open' : ''} ${problems > 0 ? 'has-issues' : ''}">
          <button type="button" class="section-header" data-action="toggle-section" data-section-id="${escapeHtml(section.id)}" aria-expanded="${isOpen ? 'true' : 'false'}">
            <span class="section-header-inner">
              <span>
                <h2 class="section-title">${escapeHtml(section.title)}</h2>
                <p class="section-description">${escapeHtml(section.description || '')}</p>
              </span>
              <span class="counters">
                <span class="counter done">${done}/${section.tasks.length} ${escapeHtml(t('labels.resolvedCounter'))}</span>
                <span class="counter issue">${problems}/${section.tasks.length} ${escapeHtml(t('labels.issueCounter'))}</span>
              </span>
              <span class="chevron" aria-hidden="true">⌄</span>
            </span>
          </button>
          <div class="section-body" ${isOpen ? '' : 'hidden'}>
            <div class="section-tools">
              <button class="btn btn-success btn-small" data-action="open-task-modal" data-section-id="${escapeHtml(section.id)}">${escapeHtml(t('actions.addTask'))}</button>
              <button class="btn btn-small" data-action="open-section-modal" data-section-id="${escapeHtml(section.id)}">${escapeHtml(t('actions.editBlock'))}</button>
              <button class="btn btn-small" data-action="move-section" data-section-id="${escapeHtml(section.id)}" data-direction="up" title="${escapeHtml(t('actions.moveUp'))}">↑</button>
              <button class="btn btn-small" data-action="move-section" data-section-id="${escapeHtml(section.id)}" data-direction="down" title="${escapeHtml(t('actions.moveDown'))}">↓</button>
              <button class="btn btn-danger btn-small" data-action="delete-section" data-section-id="${escapeHtml(section.id)}">${escapeHtml(t('actions.delete'))}</button>
            </div>
            <div class="task-list">
              ${tasks.length ? tasks.map(task => renderTask(section, task)).join('') : `<div class="empty-card">${escapeHtml(t('labels.emptyBlock'))}</div>`}
            </div>
          </div>
        </article>`;
    }).join('');
  }

  function renderTask(section, task) {
    const s = taskState(task.id);
    return `
      <article id="${escapeHtml(task.id)}" class="task-card ${s.done ? 'is-done' : ''} ${s.problem ? 'is-problem' : ''}">
        <div class="task-grid">
          <div>
            <span class="${priorityClass(task.priority)}">${escapeHtml(priorityLabel(task.priority))}</span>
            ${s.problem ? `<span class="priority priority-critical" style="margin-left:6px">${escapeHtml(t('labels.problemDetected'))}</span>` : ''}
            <h3 class="task-title">${escapeHtml(task.title)}</h3>
            ${task.description ? `<p class="task-description">${escapeHtml(task.description)}</p>` : ''}
          </div>
          <div class="task-actions">
            <label class="pill-check"><input type="checkbox" data-action="toggle-done" data-task-id="${escapeHtml(task.id)}" ${s.done ? 'checked' : ''}> ${escapeHtml(t('labels.resolved'))}</label>
            <label class="pill-check problem"><input type="checkbox" data-action="toggle-problem" data-task-id="${escapeHtml(task.id)}" data-section-id="${escapeHtml(section.id)}" ${s.problem ? 'checked' : ''}> ${escapeHtml(t('labels.problem'))}</label>
            <button class="btn btn-small" data-action="open-task-modal" data-section-id="${escapeHtml(section.id)}" data-task-id="${escapeHtml(task.id)}">${escapeHtml(t('actions.edit'))}</button>
            <button class="btn btn-small" data-action="move-task" data-task-id="${escapeHtml(task.id)}" data-direction="up" title="${escapeHtml(t('actions.moveUp'))}">↑</button>
            <button class="btn btn-small" data-action="move-task" data-task-id="${escapeHtml(task.id)}" data-direction="down" title="${escapeHtml(t('actions.moveDown'))}">↓</button>
            <button class="btn btn-danger btn-small" data-action="delete-task" data-task-id="${escapeHtml(task.id)}">×</button>
          </div>
        </div>
        <div class="problem-note" ${s.problem ? '' : 'hidden'}>
          <div class="problem-note-header">
            <p class="problem-note-title">${escapeHtml(t('labels.problemComment'))}</p>
            <button class="btn btn-small" data-action="copy-note" data-task-id="${escapeHtml(task.id)}">${escapeHtml(t('actions.copy'))}</button>
          </div>
          <textarea class="note-textarea" data-action="note-input" data-task-id="${escapeHtml(task.id)}">${escapeHtml(s.note || '')}</textarea>
        </div>
      </article>`;
  }

  function renderDrawer() {
    const backdrop = qs('#drawerBackdrop');
    const drawer = qs('#drawer');
    backdrop.hidden = !state.drawerOpen;
    drawer.classList.toggle('open', state.drawerOpen);
    drawer.setAttribute('aria-hidden', state.drawerOpen ? 'false' : 'true');
    if (!state.drawerOpen) return;

    qs('#drawerTitle').textContent = t('menu.menu');
    qs('#drawerSubtitle').textContent = t('app.subtitle');
    qs('#closeDrawerButton').setAttribute('aria-label', t('actions.closeMenu'));
    qs('#drawerMenu').innerHTML = renderMenuGroup('workspace', t('menu.workspace'), t('menu.checklistsHelp'), renderChecklistMenu()) +
      renderMenuGroup('blocks', t('menu.blocks'), t('menu.blocksHelp'), renderBlocksMenu(), 'trust') +
      renderMenuGroup('issues', t('menu.issues'), t('menu.issuesHelp'), renderIssuesMenu(), 'danger') +
      renderMenuGroup('tools', t('menu.tools'), t('menu.toolsHelp'), renderToolsMenu());
  }

  function renderMenuGroup(key, title, help, body, tone = '') {
    const open = !!state.menuOpen[key];
    return `
      <section class="menu-group ${tone}">
        <button type="button" class="menu-toggle" data-action="toggle-menu-group" data-group="${key}" aria-expanded="${open ? 'true' : 'false'}">
          <span><span class="menu-toggle-title">${escapeHtml(title)}</span><span class="menu-toggle-help">${escapeHtml(help)}</span></span>
          <span>${open ? '−' : '+'}</span>
        </button>
        <div class="menu-body" ${open ? '' : 'hidden'}>${body}</div>
      </section>`;
  }

  function renderChecklistMenu() {
    const list = state.checklists.map(item => `
      <button class="menu-link ${state.current?.slug === item.slug ? 'active' : ''}" data-action="load-checklist" data-slug="${escapeHtml(item.slug)}">
        ${escapeHtml(item.title)} <small style="display:block;opacity:.75;margin-top:2px">${item.tasks_count} ${escapeHtml(t('labels.tasks'))}</small>
      </button>`).join('');
    return list + `
      <button class="btn btn-primary" data-action="open-checklist-modal">${escapeHtml(t('actions.newChecklist'))}</button>
      <button class="btn" data-action="open-edit-checklist-modal">${escapeHtml(t('actions.editChecklist'))}</button>`;
  }

  function renderBlocksMenu() {
    return (state.current?.sections || []).map(section => `
      <button class="menu-link" data-action="scroll-section" data-section-id="${escapeHtml(section.id)}">
        ${escapeHtml(section.title)}
        <small style="display:block;opacity:.75;margin-top:2px">${sectionDoneCount(section)}/${section.tasks.length} ${escapeHtml(t('labels.resolvedCounter'))} · ${sectionProblemCount(section)} ${escapeHtml(t('labels.issueCounter'))}</small>
      </button>`).join('') || `<p class="menu-link">${escapeHtml(t('app.noChecklist'))}</p>`;
  }

  function renderIssuesMenu() {
    const issues = issuesList();
    if (!issues.length) return `<p class="menu-link">${escapeHtml(t('menu.noIssues'))}</p>`;
    const grouped = new Map();
    for (const issue of issues) {
      if (!grouped.has(issue.section.id)) grouped.set(issue.section.id, { section: issue.section, tasks: [] });
      grouped.get(issue.section.id).tasks.push(issue.task);
    }
    return Array.from(grouped.values()).map(group => `
      <div style="display:grid;gap:6px">
        <p style="margin:8px 4px 2px;color:var(--danger);font-weight:950;font-size:12px">${escapeHtml(group.section.title)}</p>
        ${group.tasks.map(task => `<button class="menu-link issue-link" data-action="scroll-task" data-section-id="${escapeHtml(group.section.id)}" data-task-id="${escapeHtml(task.id)}">${escapeHtml(task.title)}<small>${escapeHtml(t('labels.issueIn'))} ${escapeHtml(group.section.title)}</small></button>`).join('')}
      </div>`).join('');
  }

  function renderToolsMenu() {
    return `
      <button class="menu-link" data-action="open-export-modal">${escapeHtml(t('actions.export'))}</button>
      <button class="menu-link" data-action="open-import-modal">${escapeHtml(t('actions.import'))}</button>
      <button class="menu-link" data-action="reset-progress">${escapeHtml(t('actions.resetProgress'))}</button>
      <button class="menu-link issue-link" data-action="open-delete-checklist-modal">${escapeHtml(t('actions.deleteChecklist'))}</button>`;
  }

  function renderModal() {
    const root = qs('#modalRoot');
    if (!state.modal) { root.innerHTML = ''; return; }
    const type = state.modal.type;
    let body = '';
    let title = '';
    let kicker = '';

    if (type === 'checklist' || type === 'editChecklist') {
      kicker = t('modal.workspace');
      title = type === 'editChecklist' ? t('modal.editChecklist') : t('modal.newChecklist');
      body = formFields([
        { name: 'title', label: t('forms.checklistTitle'), value: state.form.title || '', required: true },
        { name: 'description', label: t('forms.description'), value: state.form.description || '', textarea: true }
      ], type === 'editChecklist' ? 'save-edit-checklist' : 'save-checklist');
    } else if (type === 'section') {
      kicker = t('modal.block'); title = state.form.id ? t('modal.editBlock') : t('modal.newBlock');
      body = formFields([
        { name: 'title', label: t('forms.blockTitle'), value: state.form.title || '', required: true },
        { name: 'description', label: t('forms.description'), value: state.form.description || '', textarea: true }
      ], 'save-section');
    } else if (type === 'task') {
      kicker = t('modal.objective'); title = state.form.id ? t('modal.editTask') : t('modal.newTask');
      body = formFields([
        { name: 'title', label: t('forms.taskTitle'), value: state.form.title || '', required: true },
        { name: 'description', label: t('forms.taskDescription'), value: state.form.description || '', textarea: true },
        { name: 'priority', label: t('forms.priority'), value: state.form.priority || 'medium', select: [ ['critical', t('priorities.critical')], ['high', t('priorities.high')], ['medium', t('priorities.medium')], ['low', t('priorities.low')] ] }
      ], 'save-task');
    } else if (type === 'export') {
      kicker = t('modal.export'); title = t('modal.exportTitle');
      body = `
        <div class="form-grid">
          <div class="form-row"><label class="form-label">${escapeHtml(t('forms.selectExportScope'))}</label><select class="select" id="exportScope"><option value="current">${escapeHtml(t('export.current'))}</option><option value="all">${escapeHtml(t('export.all'))}</option></select></div>
          <div class="form-row"><label class="form-label">${escapeHtml(t('forms.selectExportType'))}</label><select class="select" id="exportType"><option value="complete">${escapeHtml(t('export.complete'))}</option><option value="definition">${escapeHtml(t('export.definition'))}</option><option value="progress">${escapeHtml(t('export.progress'))}</option><option value="issues">${escapeHtml(t('export.issues'))}</option></select></div>
          <div class="form-row"><label class="form-label">${escapeHtml(t('export.filename'))}</label><input class="field" id="exportFilename" value="${escapeHtml(state.exportForm.filename || defaultExportName())}"></div>
          <div class="modal-actions"><button class="btn btn-ghost" data-action="close-modal">${escapeHtml(t('actions.cancel'))}</button><button class="btn btn-primary" data-action="do-export">${escapeHtml(t('actions.saveExport'))}</button></div>
        </div>`;
    } else if (type === 'import') {
      kicker = t('modal.import'); title = t('modal.importTitle');
      body = `
        <div class="form-grid">
          <p class="task-description">${escapeHtml(t('import.help'))}</p>
          <div class="form-row"><label class="form-label">${escapeHtml(t('actions.chooseFile'))}</label><input class="field" type="file" id="importFile" accept="application/json"></div>
          <div class="form-row"><label class="form-label">${escapeHtml(t('modal.import'))}</label><select class="select" id="importMode"><option value="new">${escapeHtml(t('import.createNew'))}</option><option value="current">${escapeHtml(t('import.mergeCurrent'))}</option></select></div>
          <div class="modal-actions"><button class="btn btn-ghost" data-action="close-modal">${escapeHtml(t('actions.cancel'))}</button><button class="btn btn-primary" data-action="do-import">${escapeHtml(t('actions.saveImport'))}</button></div>
        </div>`;
    } else if (type === 'deleteChecklist') {
      kicker = t('labels.deleteChecklist'); title = t('modal.deleteChecklistTitle');
      body = `
        <div class="form-grid">
          <div class="error-box">${escapeHtml(t('messages.confirmDeleteChecklist'))}<br><strong>${escapeHtml(state.current?.slug || '')}</strong></div>
          <div class="form-row"><label class="form-label">${escapeHtml(t('forms.confirmSlug'))}</label><input class="field" id="deleteConfirm" placeholder="${escapeHtml(state.current?.slug || '')}"></div>
          <div class="modal-actions"><button class="btn btn-ghost" data-action="close-modal">${escapeHtml(t('actions.cancel'))}</button><button class="btn btn-danger" data-action="do-delete-checklist">${escapeHtml(t('actions.confirmDelete'))}</button></div>
        </div>`;
    }

    root.innerHTML = `
      <div class="modal-backdrop" data-action="modal-backdrop">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
          <div class="modal-head"><div><p class="modal-kicker">${escapeHtml(kicker)}</p><h2 id="modalTitle" class="modal-title">${escapeHtml(title)}</h2></div><button class="icon-btn" data-action="close-modal" aria-label="${escapeHtml(t('actions.close'))}">×</button></div>
          <div class="modal-body">${body}</div>
        </section>
      </div>`;
    if (type === 'export') {
      qs('#exportScope').value = state.exportForm.scope;
      qs('#exportType').value = state.exportForm.type;
    }
  }

  function formFields(fields, action) {
    return `<div class="form-grid">${fields.map(field => {
      if (field.select) {
        return `<div class="form-row"><label class="form-label">${escapeHtml(field.label)}</label><select class="select" data-field="${escapeHtml(field.name)}">${field.select.map(([value,label]) => `<option value="${escapeHtml(value)}" ${value === field.value ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}</select></div>`;
      }
      if (field.textarea) {
        return `<div class="form-row"><label class="form-label">${escapeHtml(field.label)}</label><textarea data-field="${escapeHtml(field.name)}" ${field.required ? 'required' : ''}>${escapeHtml(field.value)}</textarea></div>`;
      }
      return `<div class="form-row"><label class="form-label">${escapeHtml(field.label)}</label><input class="field" data-field="${escapeHtml(field.name)}" value="${escapeHtml(field.value)}" ${field.required ? 'required' : ''}></div>`;
    }).join('')}<div class="modal-actions"><button class="btn btn-ghost" data-action="close-modal">${escapeHtml(t('actions.cancel'))}</button><button class="btn btn-primary" data-action="${action}">${escapeHtml(t('actions.save'))}</button></div></div>`;
  }

  function collectForm() {
    qsa('[data-field]').forEach(el => { state.form[el.dataset.field] = el.value.trim(); });
  }

  function renderAll() {
    renderHeader();
    renderHero();
    renderControls();
    renderSections();
    renderDrawer();
    renderModal();
  }

  function findSection(id) { return (state.current?.sections || []).find(section => section.id === id) || null; }
  function findTask(id) { for (const section of state.current?.sections || []) for (const task of section.tasks || []) if (task.id === id) return { section, task }; return null; }

  async function saveTaskState(taskId) {
    clearTimeout(state.saveTimers[taskId]);
    state.saveTimers[taskId] = setTimeout(async () => {
      try {
        const s = taskState(taskId);
        await request('/api/state', { method: 'POST', body: JSON.stringify({ checklist: state.current.slug, lang: state.language, task_id: taskId, done: !!s.done, problem: !!s.problem, note: s.note || '' }) });
      } catch (error) { showToast(error.message); }
    }, 250);
  }

  async function refreshChecklist(data) {
    if (data.checklist) state.current = data.checklist;
    if (data.checklists) state.checklists = data.checklists;
    if (!state.openSectionId && state.current?.sections?.[0]) state.openSectionId = state.current.sections[0].id;
    renderAll();
  }

  async function handleClick(event) {
    const target = event.target.closest('[data-action]');
    if (!target) return;
    const action = target.dataset.action;

    if (action === 'open-menu') return setDrawer(true);
    if (action === 'close-menu') return setDrawer(false);
    if (action === 'drawer-backdrop') return setDrawer(false);
    if (action === 'toggle-menu-group') { state.menuOpen[target.dataset.group] = !state.menuOpen[target.dataset.group]; return renderDrawer(); }
    if (action === 'toggle-section') return openSection(target.dataset.sectionId);
    if (action === 'scroll-section') { setDrawer(false); return openAndScroll(target.dataset.sectionId); }
    if (action === 'scroll-task') { setDrawer(false); return openAndScroll(target.dataset.taskId, target.dataset.sectionId); }
    if (action === 'load-checklist') { setDrawer(false); return loadChecklist(target.dataset.slug); }
    if (action === 'close-modal' || action === 'modal-backdrop') { if (action === 'modal-backdrop' && event.target !== target) return; state.modal = null; return renderModal(); }
    if (action === 'open-checklist-modal') { state.modal = { type: 'checklist' }; state.form = {}; setDrawer(false); return renderModal(); }
    if (action === 'open-edit-checklist-modal') { state.modal = { type: 'editChecklist' }; state.form = { title: state.current?.title || '', description: state.current?.description || '' }; setDrawer(false); return renderModal(); }
    if (action === 'open-delete-checklist-modal') { state.modal = { type: 'deleteChecklist' }; setDrawer(false); return renderModal(); }
    if (action === 'open-export-modal') { state.modal = { type: 'export' }; state.exportForm.filename = defaultExportName(); setDrawer(false); return renderModal(); }
    if (action === 'open-import-modal') { state.modal = { type: 'import' }; setDrawer(false); return renderModal(); }
    if (action === 'open-section-modal') { const section = findSection(target.dataset.sectionId); state.modal = { type: 'section' }; state.form = section ? { id: section.id, title: section.title, description: section.description || '' } : {}; return renderModal(); }
    if (action === 'open-task-modal') { const section = findSection(target.dataset.sectionId); const found = target.dataset.taskId ? findTask(target.dataset.taskId) : null; state.modal = { type: 'task', sectionId: section?.id || found?.section.id }; state.form = found ? { id: found.task.id, title: found.task.title, description: found.task.description || '', priority: found.task.priority || 'medium' } : { priority: 'medium' }; return renderModal(); }

    try {
      if (action === 'save-checklist') { collectForm(); const data = await request('/api/checklists', { method: 'POST', body: JSON.stringify({ lang: state.language, title: state.form.title, description: state.form.description }) }); state.modal = null; state.checklists = data.checklists || state.checklists; return loadChecklist(data.checklist.slug); }
      if (action === 'save-edit-checklist') { collectForm(); const data = await request('/api/checklists', { method: 'PATCH', body: JSON.stringify({ checklist: state.current.slug, lang: state.language, title: state.form.title, description: state.form.description }) }); state.modal = null; return refreshChecklist(data); }
      if (action === 'save-section') { collectForm(); const method = state.form.id ? 'PATCH' : 'POST'; const data = await request('/api/sections', { method, body: JSON.stringify({ checklist: state.current.slug, lang: state.language, section_id: state.form.id, title: state.form.title, description: state.form.description }) }); state.modal = null; return refreshChecklist(data); }
      if (action === 'save-task') { collectForm(); const method = state.form.id ? 'PATCH' : 'POST'; const data = await request('/api/tasks', { method, body: JSON.stringify({ checklist: state.current.slug, lang: state.language, section_id: state.modal.sectionId, task_id: state.form.id, title: state.form.title, description: state.form.description, priority: state.form.priority }) }); state.modal = null; return refreshChecklist(data); }
      if (action === 'delete-section') { if (!confirm(t('messages.confirmDeleteBlock'))) return; const data = await request('/api/sections', { method: 'DELETE', body: JSON.stringify({ checklist: state.current.slug, lang: state.language, section_id: target.dataset.sectionId }) }); return refreshChecklist(data); }
      if (action === 'delete-task') { if (!confirm(t('messages.confirmDeleteTask'))) return; const data = await request('/api/tasks', { method: 'DELETE', body: JSON.stringify({ checklist: state.current.slug, lang: state.language, task_id: target.dataset.taskId }) }); return refreshChecklist(data); }
      if (action === 'move-section') { const data = await request('/api/sections/move', { method: 'POST', body: JSON.stringify({ checklist: state.current.slug, lang: state.language, section_id: target.dataset.sectionId, direction: target.dataset.direction }) }); return refreshChecklist(data); }
      if (action === 'move-task') { const data = await request('/api/tasks/move', { method: 'POST', body: JSON.stringify({ checklist: state.current.slug, lang: state.language, task_id: target.dataset.taskId, direction: target.dataset.direction }) }); return refreshChecklist(data); }
      if (action === 'copy-note') { await navigator.clipboard.writeText(taskState(target.dataset.taskId).note || ''); return showToast(t('messages.copied')); }
      if (action === 'reset-progress') { setDrawer(false); if (!confirm(t('messages.confirmReset'))) return; const data = await request('/api/reset', { method: 'POST', body: JSON.stringify({ checklist: state.current.slug }) }); state.states = data.states || {}; renderAll(); return; }
      if (action === 'do-delete-checklist') { const confirmValue = qs('#deleteConfirm')?.value.trim() || ''; const data = await request('/api/checklists', { method: 'DELETE', body: JSON.stringify({ checklist: state.current.slug, lang: state.language, confirm: confirmValue }) }); state.modal = null; state.checklists = data.checklists || []; state.current = null; state.states = {}; renderAll(); if (state.checklists[0]) await loadChecklist(state.checklists[0].slug); return showToast(t('messages.checklistDeleted')); }
      if (action === 'do-export') return exportData();
      if (action === 'do-import') return importData();
      if (action === 'clear-filters') { state.query = ''; state.priorityFilter = 'all'; state.statusFilter = 'all'; renderAll(); }
    } catch (error) { showToast(error.message); }
  }

  async function exportData() {
    state.exportForm.scope = qs('#exportScope')?.value || 'current';
    state.exportForm.type = qs('#exportType')?.value || 'complete';
    state.exportForm.filename = (qs('#exportFilename')?.value || defaultExportName()).replace(/[^a-zA-Z0-9._-]+/g, '-');
    const params = new URLSearchParams({ checklist: state.current?.slug || '', lang: state.language, scope: state.exportForm.scope, type: state.exportForm.type });
    const data = await request(`/api/export?${params.toString()}`);
    const text = JSON.stringify(data, null, 2);
    if (window.showSaveFilePicker) {
      const handle = await window.showSaveFilePicker({ suggestedName: state.exportForm.filename, types: [{ description: 'JSON', accept: { 'application/json': ['.json'] } }] });
      const writable = await handle.createWritable(); await writable.write(text); await writable.close();
    } else {
      const blob = new Blob([text], { type: 'application/json' });
      const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = state.exportForm.filename; a.click(); URL.revokeObjectURL(url);
    }
    state.modal = null; renderModal(); showToast(t('messages.exported'));
  }

  async function importData() {
    const file = qs('#importFile')?.files?.[0];
    if (!file) return showToast(t('messages.chooseFile'));
    const parsed = JSON.parse(await file.text());
    const mode = qs('#importMode')?.value || 'new';
    const data = await request('/api/import', { method: 'POST', body: JSON.stringify({ lang: state.language, mode, import: parsed }) });
    state.checklists = data.checklists || state.checklists;
    state.modal = null; await bootstrap(); showToast(t('messages.imported'));
  }

  function handleChange(event) {
    const target = event.target;
    if (target.id === 'languageSelect') return changeLanguage(target.value);
    if (target.id === 'priorityFilter') { state.priorityFilter = target.value; return renderSections(); }
    if (target.id === 'statusFilter') { state.statusFilter = target.value; return renderSections(); }
    if (target.dataset.action === 'toggle-done') {
      const taskId = target.dataset.taskId; state.states[taskId] = { ...taskState(taskId), done: target.checked }; saveTaskState(taskId); return renderAll();
    }
    if (target.dataset.action === 'toggle-problem') {
      const found = findTask(target.dataset.taskId); if (!found) return;
      const current = taskState(found.task.id);
      state.states[found.task.id] = { ...current, problem: target.checked, note: target.checked && !current.note ? defaultProblemText(found.task, found.section) : current.note };
      saveTaskState(found.task.id); return renderAll();
    }
  }

  function handleInput(event) {
    const target = event.target;
    if (target.id === 'searchInput') { state.query = target.value; return renderSections(); }
    if (target.dataset.action === 'note-input') {
      const taskId = target.dataset.taskId; state.states[taskId] = { ...taskState(taskId), note: target.value }; saveTaskState(taskId); return;
    }
  }

  document.addEventListener('click', handleClick);
  document.addEventListener('change', handleChange);
  document.addEventListener('input', handleInput);
  document.addEventListener('keydown', event => { if (event.key === 'Escape') { state.modal = null; setDrawer(false); renderModal(); } });
  window.addEventListener('DOMContentLoaded', bootstrap);
})();
