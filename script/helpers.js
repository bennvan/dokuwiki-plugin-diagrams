const doctypeXML = '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">';

/** Reject executable/data URLs before creating an editor iframe. */
function diagramServiceUrl() {
    try {
        const url = new URL(JSINFO.plugins.diagrams.service_url, location.href);
        return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url : null;
    } catch (e) { return null; }
}

/**
 * Return the diagrams editor object or false
 * if it does not exist.
 *
 * @returns {boolean|WindowProxy}
 */
function getDiagramsEditor() {
    const diagramsFrame = jQuery('#diagrams-frame');
    if (diagramsFrame  && typeof diagramsFrame[0] !== 'undefined') {
        return diagramsFrame[0].contentWindow;
    }
    return false;
}

/**
 * Detach the message event handler and remove the editor
 *
 * @param handler
 */
function removeDiagramsEditor(handler) {
    jQuery(window).off( 'message', handler );
    jQuery('#diagrams-frame').remove();
}

/**
 * check if name/id of new diagram is valid
 *
 * @param id
 * @returns {boolean}
 */
function validId(id) {
    return /^:?[\w][\w.\-]*(?::[\w][\w.\-]*)*$/.test(id);
}

/**
 * Media Manager encloses the root dir in [] so let's strip that
 * because it is not a real namespace
 */
function cleanNs (text) {
    return text.trim().replace(/^\[.*\]$/, '').replace(/^:+|:+$/g, '');
}

/** Read the file-list namespace, never the neighbouring file-details heading. */
function currentMediaNamespace() {
    const $files = jQuery('#mediamanager__page div.filelist').first();
    const $context = $files.length ? $files : jQuery('#media__content');
    const $nsInput = $context.find('input[name="ns"]').first();
    if ($nsInput.length) return cleanNs($nsInput.val());

    const $tab = $files.children('ul.tabs').find('a[href*="tab_files="]').first();
    if ($tab.length) {
        const url = new URL($tab[0].href, location.href);
        if (url.searchParams.has('ns')) return cleanNs(url.searchParams.get('ns'));
    }

    return cleanNs($files.length
        ? $files.children('.panelHeader').find('h3 strong').first().text()
        : jQuery('#media__ns').text());
}

/**
 * return URL of an existing diagram
 *
 * @param ns
 * @param id
 * @returns {string}
 */
function getLocalDiagramUrl(ns, id) {
    return DOKU_BASE + 'lib/exe/ajax.php?' + jQuery.param({
        call: 'mediaupload', ow: true, ns: ns, qqfile: id, sectok: JSINFO.sectok
    });
}

/**
 * split full id into ns and id parts
 *
 * @param fullId
 * @returns {{ns: string, id: *}}
 */
function splitFullId(fullId) {
    let id = fullId;
    let ns = '';
    const idParts = id.split(':');
    if (idParts.length > 1) {
        id = idParts.pop();
        ns = idParts.join(':');
    }
    return {ns: ns, id: id};
}

/**
 * extract image id from media URL
 *
 * @param {string} url
 * @returns {string}
 */
function extractIdFromMediaUrl(url) {
    try {
        const urlObj = new URL(url, location.href);
        const base = new URL(DOKU_BASE, location.href);
        if (urlObj.origin !== base.origin || !urlObj.pathname.startsWith(base.pathname)) return null;
        const basePath = base.pathname;
        let path = urlObj.pathname;
        if (path.indexOf(basePath) === 0) path = path.slice(basePath.length);
        path = path.replace(/^\//, '');

        if (urlObj.searchParams.get('media') !== null &&
            ['lib/exe/detail.php', 'lib/exe/fetch.php', 'doku.php', ''].includes(path)) {
            // no rewriting
            const id = urlObj.searchParams.get('media');
            if (!id || /^(?:[a-z][a-z\d+.-]*:\/\/|\/\/)/i.test(id)) return null;
            return normalizeId(id);
        } else if (
            path.indexOf('lib/exe/detail.php/') === 0 ||
            path.indexOf('lib/exe/fetch.php/') === 0
        ) {
            // internally rewritten URL, cut off three segments
            return normalizeId(decodeURIComponent(path.split('/').slice(3).join(':')));
        } else if (
            path.indexOf('_media/') === 0 ||
            path.indexOf('_detail/') === 0
        ) {
            // .htaccess rewritten URL, cut off one segment
            return normalizeId(decodeURIComponent(path.split('/').slice(1).join(':')));
        }
    } catch (e) { /* Malformed URLs are not editable local media. */ }
    return null;
}

/**
 * Handles IDs with useslash enabled
 *
 * @param {string} id
 * @return {string}
 */
function normalizeId(id) {
    return ':' + id.replace(/\//g, ':').replace(/^:+/, '');
}
