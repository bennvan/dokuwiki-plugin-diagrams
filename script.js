jQuery(function () {
    /* DOKUWIKI:include script/helpers.js */
    /* DOKUWIKI:include script/service.js */
    /* DOKUWIKI:include script/elements.js */
    /* DOKUWIKI:include script/svg.js */

    // add diagram edit button to diagram SVGs included in wiki pages
    const $images = jQuery('object').filter('.diagrams-svg');

    // collect image IDs with file extension
    const imageIds = $images.map(function (key, image) {
        return extractIdFromMediaUrl(image.data);
    }).toArray();

    let ajaxData = {};
    ajaxData['call'] = 'plugin_diagrams';
    ajaxData['images'] = imageIds;

    // callback to attach buttons to editable diagrams
    const attachButtons = function (result) {
        const diagrams = result;
        if (!Array.isArray(diagrams)) return;
        $images.each(function () {
            const id = extractIdFromMediaUrl(this.data);
            const $current = jQuery(this);
            if (diagrams.includes(id)) {
                let $editButton = editDiagramButton(id);
                if ($current.parent()[0].nodeName === 'A') {
                    $current.parent().after("<br>", $editButton);
                } else {
                    $current.after("<br>", $editButton);
                }
            }
        });
    };

    // query backend about permissions and SVG properties before attaching edit buttons
    if (imageIds.length) jQuery.getJSON(DOKU_BASE + 'lib/exe/ajax.php', ajaxData, attachButtons);

    /**
     * Media manager
     * FIXME this should be moved to a separate file
     */

    /* are we in media manager context? */
    const $mm_page = jQuery('#mediamanager__page');
    const $mm_popup = jQuery('#media__manager');
    const isMMPage = $mm_page.length > 0;
    const isMMPopup = $mm_popup.length > 0;
    if (!isMMPage && !isMMPopup) return;

    const $fileList = isMMPage ? $mm_page.find('div.filelist').first() : jQuery('#media__content');

    function openCreateDialog(ns) {
        newDiagramForm(ns).dialog({
            title: LANG.plugins.diagrams.createLink,
            width: Math.min(600, window.innerWidth - 32),
            appendTo: '.dokuwiki, #media__manager',
            modal: true,
            close: function () {
                jQuery(this).dialog('destroy').remove();
            }
        });
    }

    function addCreateAction() {
        // Bootstrap3 owns the Actions tab; other templates retain a standalone link.
        if (isMMPage && typeof dw_template !== 'undefined' && dw_template.mediaActions) return;
        if ($fileList.find('.diagrams-create-action').length) return;
        const $link = jQuery('<a href="#"/>')
            .text(LANG.plugins.diagrams.createLink)
            .on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openCreateDialog(currentMediaNamespace());
            });
        const $tabs = $fileList.children('ul.tabs').first();
        if ($tabs.length) {
            jQuery('<li class="diagrams-create-action"/>').append($link).appendTo($tabs);
        } else {
            // The editor popup has no tab strip; keep the action with its files.
            jQuery('<div class="diagrams-create-action"/>').append($link).prependTo($fileList);
        }
    }

    jQuery(document).on('bootstrap3:media-actions', function (event, actions) {
        actions.push({label: LANG.plugins.diagrams.createLink, run: openCreateDialog});
    });
    addCreateAction();
    // Core replaces this panel when changing namespace or tabs.
    new MutationObserver(addCreateAction).observe($fileList[0], {childList: true});

    if (!isMMPage) return;
    const $details = $mm_page.find('div.file').first();
    let pendingId = null;
    function addDetailEditButton() {
        const $svgLink = $details.find('a.mediafile.mf_svg').first();
        const id = $svgLink.length ? extractIdFromMediaUrl($svgLink[0].href) : null;
        if (!id || $details.find('button.diagrams-btn').length || pendingId === id) return;
        pendingId = id;
        jQuery.getJSON(DOKU_BASE + 'lib/exe/ajax.php', {
            call: 'plugin_diagrams', images: [id]
        }, function (result) {
            const editable = result;
            if (!Array.isArray(editable)) return;
            const $current = $details.find('a.mediafile.mf_svg').first();
            if ($current.length && extractIdFromMediaUrl($current[0].href) === id &&
                editable.includes(id) && !$details.find('button.diagrams-btn').length) {
                jQuery('<li/>').append(editDiagramButton(id)).appendTo($details.find('ul.actions').first());
            }
        }).always(function () { if (pendingId === id) pendingId = null; });
    }
    // One observer per panel, rather than a new observer on every file click.
    new MutationObserver(addDetailEditButton).observe($details[0], {childList: true, subtree: true});
    addDetailEditButton();

});
