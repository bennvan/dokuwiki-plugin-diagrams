/** Preserve SVG links and custom styling without relaxing DokuWiki's media CSP. */
const $diagramObjects = jQuery('object.diagrams-svg');
let diagramStyles;
if ($diagramObjects.length && JSINFO.plugins.diagrams.xsvg_style) {
    diagramStyles = jQuery.ajax({
        url: DOKU_BASE + 'lib/plugins/diagrams/xsvg.css', dataType: 'text', cache: true
    });
}
function enhanceDiagramSvg() {
    const object = this;
    let doc;
    try { doc = object.contentDocument; } catch (e) { return; }
    // Sandboxed or external SVGs can be opaque to the parent page.
    if (!doc || !doc.documentElement || doc.documentElement.localName !== 'svg') return;
    // Adaptive draw.io exports otherwise follow the browser's dark preference,
    // independently of the wiki's light page and the editor's appearance.
    doc.documentElement.style.setProperty('color-scheme', 'only light');
    const $svg = jQuery(doc.documentElement);
    $svg.find('a').each(function () {
        const link = this;
        const href = link.getAttribute('href') || link.getAttribute('xlink:href');
        if (!href) return;
        try {
            const url = new URL(href, object.data);
            if (!['http:', 'https:', 'mailto:', 'tel:'].includes(url.protocol)) throw new Error('Unsafe link');
        } catch (e) {
            link.removeAttribute('href');
            link.removeAttributeNS('http://www.w3.org/1999/xlink', 'href');
            return;
        }
        if (!link.getAttribute('target')) link.setAttribute('target', '_parent');
        if (link.getAttribute('target') === '_blank') {
            link.setAttribute('rel', 'noopener noreferrer');
        }
        jQuery(link).off('click.diagrams').on('click.diagrams', function (event) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ||
                (event.button != null && event.button !== 0) ||
                !['_parent', '_top'].includes(link.getAttribute('target'))) return;
            const pageUrl = new URL(location.href);
            // Bare fragments refer to the wiki page; other links retain the
            // SVG document's normal URL resolution and navigation behaviour.
            const url = new URL(href, href.startsWith('#') ? pageUrl.href : doc.baseURI);
            if (!url.hash || url.origin !== pageUrl.origin || url.pathname !== pageUrl.pathname ||
                url.search !== pageUrl.search) return;
            let target;
            try { target = document.getElementById(decodeURIComponent(url.hash.slice(1))); } catch (e) { return; }
            if (!target) return; // Leave links to shapes inside the SVG alone.
            event.preventDefault();
            const navbar = document.getElementById('dw__navbar');
            history.replaceState({}, '', url);
            window.scrollTo({
                top: target.getBoundingClientRect().top + window.pageYOffset - (navbar ? navbar.offsetHeight : 0),
                behavior: 'smooth'
            });
        });
    });
    if (diagramStyles) diagramStyles.done(function (css) {
        if (object.contentDocument !== doc || doc.getElementById('diagrams-custom-style')) return;
        const style = doc.createElementNS('http://www.w3.org/2000/svg', 'style');
        style.id = 'diagrams-custom-style';
        style.textContent = css;
        doc.documentElement.appendChild(style);
    });
}
$diagramObjects.on('load.diagrams', enhanceDiagramSvg).each(enhanceDiagramSvg);
