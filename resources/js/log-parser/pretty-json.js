/**
 * Minimal JSON pretty-printer with syntax colouring.
 *
 * `tokenizeJson` is pure (unit tested); `renderJson` turns tokens into DOM nodes
 * using text nodes only, so log content can never inject markup.
 */

/**
 * @returns {{type: 'punct'|'key'|'string'|'number'|'bool'|'null'|'ellipsis', text: string}[]}
 */
export function tokenizeJson(value, { maxDepth = 8, hiddenKeys = [], indent = 2 } = {}) {
    const hidden = new Set(hiddenKeys);
    const seen = new WeakSet();
    const tokens = [];

    const push = (type, text) => {
        const last = tokens[tokens.length - 1];
        if (type === 'punct' && last?.type === 'punct') {
            last.text += text;
        } else {
            tokens.push({ type, text });
        }
    };

    const walk = (node, depth, path) => {
        if (node === null || node === undefined) {
            push('null', 'null');
            return;
        }

        switch (typeof node) {
            case 'string':
                push('string', JSON.stringify(node));
                return;
            case 'number':
                push(Number.isFinite(node) ? 'number' : 'null', Number.isFinite(node) ? String(node) : 'null');
                return;
            case 'bigint':
                push('number', String(node));
                return;
            case 'boolean':
                push('bool', String(node));
                return;
            case 'object':
                break;
            default:
                push('null', 'null');
                return;
        }

        if (seen.has(node)) {
            push('ellipsis', '[Circular]');
            return;
        }

        const isArray = Array.isArray(node);
        const entries = isArray
            ? node.map((item, index) => [index, item])
            : Object.entries(node).filter(([key]) => !hidden.has(path === '' ? key : `${path}.${key}`));
        const [open, close] = isArray ? ['[', ']'] : ['{', '}'];

        if (entries.length === 0) {
            push('punct', open + close);
            return;
        }

        if (depth >= maxDepth) {
            push('ellipsis', isArray ? `[… ${entries.length} items]` : `{… ${entries.length} keys}`);
            return;
        }

        seen.add(node);
        const padding = ' '.repeat(indent * (depth + 1));
        push('punct', `${open}\n`);
        entries.forEach(([key, item], index) => {
            push('punct', padding);
            if (!isArray) {
                push('key', JSON.stringify(key));
                push('punct', ': ');
            }
            // Array items keep the parent path so hiddenKeys like "items.secret" apply to every item.
            walk(item, depth + 1, isArray ? path : (path === '' ? key : `${path}.${key}`));
            push('punct', `${index < entries.length - 1 ? ',' : ''}\n`);
        });
        push('punct', ' '.repeat(indent * depth) + close);
        seen.delete(node);
    };

    walk(value, 0, '');

    return tokens;
}

/**
 * Append `text` to `element`, wrapping case-insensitive matches of `lowerQuery`
 * in `span.log-highlight` (the class the logs view already uses).
 *
 * @returns {boolean} whether anything matched
 */
export function appendHighlighted(element, text, lowerQuery, doc) {
    if (!lowerQuery) {
        element.appendChild(doc.createTextNode(text));
        return false;
    }

    const lowerText = text.toLowerCase();
    let lastIndex = 0;
    let index = lowerText.indexOf(lowerQuery);
    if (index === -1) {
        element.appendChild(doc.createTextNode(text));
        return false;
    }

    while (index !== -1) {
        if (index > lastIndex) {
            element.appendChild(doc.createTextNode(text.slice(lastIndex, index)));
        }
        const mark = doc.createElement('span');
        mark.className = 'log-highlight';
        mark.textContent = text.slice(index, index + lowerQuery.length);
        element.appendChild(mark);
        lastIndex = index + lowerQuery.length;
        index = lowerText.indexOf(lowerQuery, lastIndex);
    }

    if (lastIndex < text.length) {
        element.appendChild(doc.createTextNode(text.slice(lastIndex)));
    }

    return true;
}

/**
 * @returns {{ fragment: DocumentFragment, matched: boolean }}
 */
export function renderJson(tokens, doc, lowerQuery = '') {
    const fragment = doc.createDocumentFragment();
    let matched = false;

    for (const token of tokens) {
        if (token.type === 'punct') {
            fragment.appendChild(doc.createTextNode(token.text));
            continue;
        }

        const span = doc.createElement('span');
        span.className = `json-${token.type}`;
        matched = appendHighlighted(span, token.text, lowerQuery, doc) || matched;
        fragment.appendChild(span);
    }

    return { fragment, matched };
}
