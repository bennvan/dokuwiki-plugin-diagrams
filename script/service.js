/** Handle the configured editor's messages and save SVG before its PNG preview. */
const handleServiceMessages = function (event) {
    const diagrams = getDiagramsEditor();
    const serviceUrl = diagramServiceUrl();
    if (!diagrams || !serviceUrl || !event.originalEvent ||
        event.originalEvent.source !== diagrams || event.originalEvent.origin !== serviceUrl.origin) return;
    const origin = serviceUrl.origin;
    let msg;
    try { msg = JSON.parse(event.originalEvent.data); } catch (e) { return; }
    if (!msg || typeof msg !== 'object') return;
    const state = event.data;
    const {ns, id} = splitFullId(state.fullId);
    const send = function (message) { diagrams.postMessage(JSON.stringify(message), origin); };
    const reload = function () {
        removeDiagramsEditor(handleServiceMessages);
        const url = new URL(location.href);
        if (jQuery('#mediamanager__page, #media__manager').length) {
            url.searchParams.set('ns', splitFullId(state.fullId).ns);
            url.searchParams.delete('image');
        }
        location.assign(url);
    };
    const failed = function () {
        state.saving = false;
        state.expected = null;
        send({action: 'spinner', show: false});
        alert(LANG.plugins.diagrams.errorSaving);
    };
    const saved = function () {
        state.saving = false;
        state.expected = null;
        send({action: 'spinner', show: false});
        if (confirm(LANG.plugins.diagrams.saveSuccess)) reload();
    };
    if (msg.error && state.saving) { failed(); return; }
    if (msg.event === 'init' && !state.initialized) {
        state.initialized = true;
        jQuery.ajax({url: DOKU_BASE + 'lib/exe/fetch.php', data: {media: state.fullId}, cache: false, dataType: 'text'})
            .done(function (data) { send({action: 'load', xml: data}); })
            .fail(function (xhr) {
                if (xhr.status === 404) send({action: 'load', xml: ''});
                else failed();
            });
    } else if (msg.event === 'save' && !state.saving) {
        state.saving = true;
        state.expected = 'svg';
        send({action: 'export', format: 'xmlsvg', theme: 'light', spin: LANG.plugins.diagrams.saving});
    } else if (msg.event === 'export' && state.saving) {
        if ((msg.format === 'svg' || msg.format === 'xmlsvg') && state.expected === 'svg') {
            state.expected = null;
            let svg;
            try {
                if (typeof msg.data !== 'string' || !/^data:image\/svg\+xml(?:;charset=[\w-]+)?;base64,/i.test(msg.data)) {
                    throw new Error('Invalid SVG export');
                }
                const data = msg.data.split(',')[1];
                svg = doctypeXML + '\n' + decodeURIComponent(Array.from(atob(data), c =>
                    '%' + c.charCodeAt(0).toString(16).padStart(2, '0')).join(''));
            } catch (e) { failed(); return; }
            jQuery.ajax({url: getLocalDiagramUrl(ns, id), method: 'POST', data: svg, dataType: 'json'})
                .done(function (result) {
                    // DokuWiki reports upload errors in JSON even with HTTP 200.
                    if (!result || result.success !== true) { failed(); return; }
                    if (typeof result.id === 'string') state.fullId = normalizeId(result.id).replace(/^:/, '');
                    if (!JSINFO.plugins.diagrams.exportPng) { saved(); return; }
                    state.expected = 'png';
                    send({action: 'export', format: 'png', theme: 'light', scale: 1.5, spin: LANG.plugins.diagrams.saving});
                }).fail(failed);
        } else if (msg.format === 'png' && state.expected === 'png') {
            state.expected = null;
            if (!/\.svg$/i.test(state.fullId) || typeof msg.data !== 'string' ||
                !msg.data.startsWith('data:image/png;base64,')) { failed(); return; }
            jQuery.post(DOKU_BASE + 'lib/exe/ajax.php', {
                call: 'plugin_diagrams', action: 'savepng',
                id: state.fullId.replace(/\.svg$/i, '.png'),
                content: msg.data, sectok: JSINFO.sectok
            }).done(saved).fail(failed);
        } else if (state.expected) {
            failed();
        }
    } else if (msg.event === 'exit' && !state.saving) {
        reload();
    }
};
