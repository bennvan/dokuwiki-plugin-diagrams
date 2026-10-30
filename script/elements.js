/**
 * Returns a diagram creation form as jQuery object
 *
 * @returns {jQuery|HTMLElement}
 */
function newDiagramForm(ns) {
    const $createForm = jQuery(
        '<div class="diagrams-create-dialog"><form class="diagrams-create-form">' +
        '<p>' + LANG.plugins.diagrams.createIntro + ' <strong><span id="diagrams__current-ns">' +
        '</span></strong></p>' +
        '<label for="diagrams__create-filename">' + LANG.plugins.diagrams.filenameLabel + '</label>' +
        '<input type="text" class="edit" name="diagrams-create-filename" id="diagrams__create-filename" required />' +
        '<button type="submit" id="diagrams__create" class="button">' + LANG.plugins.diagrams.createButton + '</button>' +
        '</form></div>'
    );

    $createForm.find('form').data('namespace', ns);
    $createForm.find('#diagrams__current-ns').text(ns ? ':' + ns : ':');
    jQuery( $createForm ).on( 'submit', createDiagram );

    return $createForm;
}

/**
 * Launch the editor and create a new diagram
 *
 * @param event
 */
function createDiagram(event) {
    event.preventDefault();

    const $form = jQuery(event.target);
    const ns = $form.data('namespace');
    const id = $form.find('#diagrams__create-filename').val().trim();

    if (!validId(id)) {
        alert(LANG.plugins.diagrams.errorInvalidId);
        return;
    }

    const name = id.replace(/^:/, '').replace(/\.svg$/i, '') + '.svg';
    launchEditor({data:{fullId: [ns, name].filter(Boolean).join(':')}});
    $form.closest('.ui-dialog-content').dialog('close');
}

/**
 * Returns an edit button with attached click handler that launches the editor
 *
 * @param fullId
 * @returns {jQuery|HTMLElement}
 */
function editDiagramButton(fullId) {
    const $editButton = jQuery(
        '<button type="button" class="diagrams-btn"/>'
    ).attr('data-id', fullId).text(LANG.plugins.diagrams.editButton);
    jQuery( $editButton ).on( 'click', {fullId: fullId}, launchEditor );

    return $editButton;
}

/**
 * Launch diagram editor's iframe
 */
const launchEditor = function(event) {
    const fullId = event.data.fullId;
    const url = diagramServiceUrl();
    if (!url) { alert(LANG.plugins.diagrams.errorServiceUrl); return; }
    if (!jQuery('#diagrams-frame')[0]) {
        jQuery('<iframe id="diagrams-frame" style="border: 0;position: fixed; top: 0; left: 0; right:0; bottom: 0; width:100%; height:100%; z-index: 9999;"/>')
            .attr('title', LANG.plugins.diagrams.editorTitle).appendTo('body');
        jQuery(window).on('message', {fullId: fullId}, handleServiceMessages);
        jQuery('#diagrams-frame').attr('src', url.href);
    }
};
