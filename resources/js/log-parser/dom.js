/**
 * DOM layer: decorates the server-rendered log lines of a GetLogs panel in place.
 *
 * Server markup per line (get-logs.blade.php):
 *   div[data-log-line] > span.logs-viewer-timestamp? + span[data-line-text]
 *
 * A decorated line becomes:
 *   span.logs-viewer-timestamp? + span.lp-meta + span[data-line-text]=message
 *   + button.lp-toggle + pre.lp-json (created on first expand)
 *
 * Processed lines are skipped by Livewire's morph (see installLivewireHooks).
 * That is safe because lines are keyed by md5 of their content, so a keyed
 * line's server markup never changes.
 */
import { compilePreset, formatTime, hashString, parseLine } from './core.js';
import { appendHighlighted, renderJson, tokenizeJson } from './pretty-json.js';

const FRAME_BUDGET_MS = 12;

function stateFor(logsEl) {
    if (!logsEl.__lp) {
        logsEl.__lp = { hash: '', preset: null, query: '', queue: [], open: new Set(), frame: 0 };
    }

    return logsEl.__lp;
}

/**
 * Parse every not-yet-processed line (cheap, synchronous: sets the level used by
 * the filter/colour code), then build entry DOM in time-sliced frames, newest
 * lines first. Pass { sync: true } to build everything immediately.
 */
export function decorateLogs(logsEl, { sync = false } = {}) {
    if (!logsEl) {
        return;
    }

    const source = logsEl.dataset.logParser || '';
    const state = stateFor(logsEl);
    const hash = source === '' ? '' : hashString(source);

    if (state.hash !== hash) {
        undecorateAll(logsEl);
        state.hash = hash;
        state.preset = null;
        if (source !== '') {
            const compiled = compilePreset(source);
            if (compiled.ok) {
                state.preset = compiled.preset;
            } else {
                console.warn('[log-parser] invalid preset, showing raw logs:', compiled.error);
            }
        }
    }

    const preset = state.preset;
    if (!preset) {
        return;
    }

    let inherited = null;
    const pending = [];

    for (const line of logsEl.querySelectorAll('[data-log-line]')) {
        if (line.__lpEntry !== undefined) {
            if (line.__lpEntry) {
                inherited = line.__lpEntry.level;
            }
            continue;
        }

        const textSpan = line.querySelector('[data-line-text]');
        const entry = textSpan ? parseLine(preset, textSpan.dataset.lineText ?? '') : null;
        line.__lpEntry = entry;

        if (entry) {
            inherited = entry.level;
            line.dataset.lpLevel = entry.level;
            line.dataset.logLevel = entry.level;
            pending.push(line);
        } else if (preset.unmatched === 'inherit' && inherited) {
            // e.g. stack trace lines printed after an ERROR entry
            line.dataset.lpLevel = inherited;
            line.dataset.logLevel = inherited;
        }
    }

    if (sync) {
        pending.forEach((line) => decorateLine(line, state));
        return;
    }

    if (pending.length > 0) {
        state.queue = state.queue.concat(pending);
        scheduleFrame(state);
    }
}

function scheduleFrame(state) {
    if (state.frame) {
        return;
    }

    const requestFrame = globalThis.requestAnimationFrame ?? ((callback) => setTimeout(callback, 16));
    state.frame = requestFrame(() => {
        state.frame = 0;
        const deadline = performance.now() + FRAME_BUDGET_MS;
        while (state.queue.length > 0 && performance.now() < deadline) {
            // Newest lines first: that is where the viewport usually is.
            decorateLine(state.queue.pop(), state);
        }
        if (state.queue.length > 0) {
            scheduleFrame(state);
        }
    });
}

function decorateLine(line, state) {
    const entry = line.__lpEntry;
    const preset = state.preset;
    if (!entry || line.__lpDecorated || !line.isConnected || !preset) {
        return;
    }

    const textSpan = line.querySelector('[data-line-text]');
    if (!textSpan) {
        return;
    }

    const doc = line.ownerDocument;
    line.__lpDecorated = true;
    line.dataset.lpText = textSpan.dataset.lineText ?? '';
    line.classList.add('lp-parsed');

    const meta = doc.createElement('span');
    meta.className = 'lp-meta';
    if (preset.display.showTime && entry.time) {
        meta.appendChild(textElement(doc, 'span', 'lp-time', formatTime(entry.time, preset.display.timeFormat)));
    }
    if (preset.display.levelBadge) {
        const label = entry.levelRaw && Number.isNaN(Number(entry.levelRaw)) ? entry.levelRaw : entry.level;
        meta.appendChild(textElement(doc, 'span', `lp-badge lp-badge-${entry.level}`, label.toUpperCase()));
    }
    if (preset.display.extraFields) {
        for (const [name, value] of Object.entries(entry.fields)) {
            meta.appendChild(textElement(doc, 'span', 'lp-field', `${name}=${value}`));
        }
    }
    if (meta.childNodes.length > 0) {
        line.insertBefore(meta, textSpan);
    }

    // Upstream search highlighting resets the span from data-line-text,
    // so pointing it at the message keeps highlightText() working unchanged.
    textSpan.dataset.lineText = entry.message;
    textSpan.textContent = '';
    appendHighlighted(textSpan, entry.message, state.query, doc);

    if (entry.json === null && entry.data === undefined) {
        return;
    }

    line.classList.add('lp-has-details');
    const toggle = doc.createElement('button');
    toggle.type = 'button';
    toggle.className = 'lp-toggle';
    toggle.title = 'Show details';
    toggle.setAttribute('data-lp-toggle', '');
    toggle.setAttribute('aria-expanded', 'false');
    const chevron = textElement(doc, 'span', 'lp-chevron', '▸');
    chevron.setAttribute('aria-hidden', 'true');
    toggle.appendChild(chevron);
    if (preset.json.previewChars > 0) {
        const preview = entry.json ?? safeJson(entry.data);
        toggle.appendChild(textElement(doc, 'span', 'lp-preview', preview.slice(0, preset.json.previewChars)));
    }
    textSpan.after(toggle);

    if (!preset.json.collapsed) {
        setOpen(line, true, state);
    }
}

function renderDetails(line, state) {
    const entry = line.__lpEntry;
    const preset = state.preset;
    const doc = line.ownerDocument;

    let pre = line.__lpPre;
    if (!pre) {
        pre = doc.createElement('pre');
        pre.className = 'lp-json';
        line.appendChild(pre);
        line.__lpPre = pre;
    }
    pre.replaceChildren();

    let value = entry.data;
    let parsed = value !== undefined;
    if (!parsed && entry.json !== null) {
        try {
            value = JSON.parse(entry.json);
            parsed = true;
        } catch {
            parsed = false;
        }
    }

    if (parsed && preset.json.prettify) {
        const tokens = tokenizeJson(value, { maxDepth: preset.json.maxDepth, hiddenKeys: preset.detailHiddenKeys });
        pre.appendChild(renderJson(tokens, doc, state.query).fragment);
    } else {
        appendHighlighted(pre, entry.json ?? safeJson(value), state.query, doc);
    }
    pre.dataset.lpQuery = state.query;
}

function setOpen(line, open, state) {
    if (open) {
        if (!line.__lpPre || line.__lpPre.dataset.lpQuery !== state.query) {
            renderDetails(line, state);
        }
        line.__lpPre.hidden = false;
        state.open.add(line);
    } else {
        if (line.__lpPre) {
            line.__lpPre.hidden = true;
        }
        state.open.delete(line);
    }

    line.classList.toggle('lp-open', open);
    line.querySelector('[data-lp-toggle]')?.setAttribute('aria-expanded', String(open));
}

export function toggleEntry(line) {
    const logsEl = line?.closest('[data-log-parser]');
    const state = logsEl?.__lp;
    if (!state?.preset || !line.__lpDecorated) {
        return;
    }

    setOpen(line, !line.classList.contains('lp-open'), state);
}

/**
 * Called by the logs view after its own search pass (get-logs.blade.php applySearch).
 * Marks entries whose match is only inside the collapsed details and re-renders
 * expanded details with highlighting.
 */
export function afterSearch(logsEl, query) {
    const state = logsEl?.__lp;
    if (!state?.preset) {
        return;
    }

    const lowerQuery = String(query ?? '').trim().toLowerCase();
    const changed = state.query !== lowerQuery;
    state.query = lowerQuery;

    for (const line of logsEl.querySelectorAll('.lp-has-details')) {
        const entry = line.__lpEntry;
        let inDetails = false;
        if (lowerQuery !== '' && entry && !line.classList.contains('hidden')) {
            entry.lowerMessage ??= entry.message.toLowerCase();
            inDetails = !entry.lowerMessage.includes(lowerQuery)
                && (entry.json ?? safeJson(entry.data)).toLowerCase().includes(lowerQuery);
        }
        line.classList.toggle('lp-match-details', inDetails);
    }

    for (const line of state.open) {
        if (!line.isConnected) {
            state.open.delete(line);
        } else if (changed) {
            renderDetails(line, state);
        }
    }
}

/**
 * Plain-text export for "Download displayed logs": the original line, not the
 * decorated text content. Returns null for lines this module did not change.
 */
export function exportLine(line) {
    if (!line?.__lpDecorated) {
        return null;
    }

    const timestamp = line.querySelector('.logs-viewer-timestamp')?.textContent ?? '';

    return `${timestamp} ${line.dataset.lpText ?? ''}`;
}

function undecorateLine(line) {
    if (line.__lpDecorated) {
        line.querySelector('.lp-meta')?.remove();
        line.querySelector('[data-lp-toggle]')?.remove();
        line.__lpPre?.remove();
        const textSpan = line.querySelector('[data-line-text]');
        if (textSpan) {
            textSpan.dataset.lineText = line.dataset.lpText ?? '';
            textSpan.textContent = line.dataset.lpText ?? '';
        }
    }

    line.classList.remove('lp-parsed', 'lp-has-details', 'lp-open', 'lp-match-details');
    delete line.dataset.lpLevel;
    delete line.dataset.lpText;
    delete line.dataset.logLevel;
    delete line.__lpEntry;
    delete line.__lpDecorated;
    delete line.__lpPre;
}

function undecorateAll(logsEl) {
    const state = stateFor(logsEl);
    state.queue = [];
    state.open.clear();
    for (const line of logsEl.querySelectorAll('[data-log-line]')) {
        if (line.__lpEntry !== undefined) {
            undecorateLine(line);
        }
    }
}

/**
 * Render sample lines with a preset into `container` (preset editor preview).
 */
export function renderPreview(container, source, sample) {
    const doc = container.ownerDocument;
    container.replaceChildren();

    const compiled = compilePreset(source);
    if (!compiled.ok) {
        return { ok: false, error: compiled.error, parsed: 0, total: 0 };
    }

    const logsEl = doc.createElement('div');
    logsEl.className = 'lp-preview-logs';
    logsEl.dataset.logParser = source;
    for (const text of String(sample ?? '').split(/\r?\n/)) {
        if (text.trim() === '') {
            continue;
        }
        const line = doc.createElement('div');
        line.className = 'logs-viewer-line';
        line.setAttribute('data-log-line', '');
        const textSpan = textElement(doc, 'span', 'logs-viewer-line-text', text);
        textSpan.dataset.lineText = text;
        line.appendChild(textSpan);
        logsEl.appendChild(line);
    }
    container.appendChild(logsEl);

    decorateLogs(logsEl, { sync: true });

    let parsed = 0;
    let total = 0;
    for (const line of logsEl.querySelectorAll('[data-log-line]')) {
        total += 1;
        if (line.__lpEntry) {
            parsed += 1;
        }
        if (line.dataset.lpLevel) {
            line.classList.add(`log-${line.dataset.lpLevel}`);
        }
    }

    return { ok: true, error: null, parsed, total };
}

export function installLivewireHooks(Livewire) {
    if (!Livewire || Livewire.__lpHooksInstalled) {
        return;
    }
    Livewire.__lpHooksInstalled = true;

    // Morph patches a parent before its children, so decorate once the whole
    // morph is done. The microtask also runs before the logs view's own
    // $nextTick-deferred colour/search pass, which then sees the parsed levels.
    const pending = new Set();
    const schedule = (logsEl) => {
        pending.add(logsEl);
        if (pending.size === 1) {
            queueMicrotask(() => {
                const elements = [...pending];
                pending.clear();
                elements.forEach((element) => decorateLogs(element));
            });
        }
    };

    Livewire.hook('morph.updating', ({ el, skip }) => {
        if (el.__lpEntry !== undefined) {
            skip();
        }
    });
    Livewire.hook('morph.updated', ({ el }) => {
        if (el.id === 'logs') {
            schedule(el);
        }
    });
    Livewire.hook('morph.added', ({ el }) => {
        if (el.id === 'logs') {
            schedule(el);
        }
    });

    document.addEventListener('livewire:navigated', () => {
        document.querySelectorAll('#logs[data-log-parser]').forEach((element) => schedule(element));
    });

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const line = target.closest('.lp-has-details');
        if (!line || target.closest('.lp-json')) {
            return;
        }
        // Don't toggle when the user is selecting text to copy.
        const selection = window.getSelection?.();
        if (selection && !selection.isCollapsed && selection.toString().trim() !== '' && !target.closest('[data-lp-toggle]')) {
            return;
        }
        toggleEntry(line);
    });
}

function textElement(doc, tag, className, text) {
    const element = doc.createElement(tag);
    element.className = className;
    element.textContent = text;

    return element;
}

function safeJson(value) {
    try {
        return JSON.stringify(value) ?? '';
    } catch {
        return String(value);
    }
}
