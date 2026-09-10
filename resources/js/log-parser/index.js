/**
 * Fork patch "log-parser": structured, collapsible container log entries.
 * Imported from resources/js/app.js.
 */
import '../../css/log-parser.css';
import { compilePreset, parseLine, testPreset } from './core.js';
import { afterSearch, decorateLogs, exportLine, installLivewireHooks, renderPreview } from './dom.js';

window.CoolifyLogParser = { compilePreset, parseLine, testPreset, decorateLogs, afterSearch, exportLine, renderPreview };

document.addEventListener('livewire:init', () => installLivewireHooks(window.Livewire));

document.addEventListener('alpine:init', () => {
    // Preset editor preview: runs entirely in the browser against the editor's
    // current (unsaved) config, so no Livewire round trip is needed.
    window.Alpine.data('logParserPreview', (initialSample = '') => ({
        sample: initialSample,
        error: '',
        summary: '',
        init() {
            this.$nextTick(() => this.run());
        },
        run() {
            const result = renderPreview(this.$refs.output, String(this.$wire.config ?? ''), this.sample);
            this.error = result.ok ? '' : result.error;
            this.summary = result.ok ? `${result.parsed} of ${result.total} lines parsed` : '';
        },
    }));
});
