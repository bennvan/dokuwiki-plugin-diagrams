/**
 * Handle messages from diagramming service
 *
 * @param event
 */
const handleServiceMessages = function( event ) {
    const diagrams = getDiagramsEditor();
    // early exit
    if (!diagrams) {
        return;
    }

    // some browsers stubbornly cache request data and mess up subsequent edits
    disableRequestCaching();

    // get diagram info passed to the function
    const fullId = event.data.fullId;
    const {ns, id} = splitFullId(fullId);

    const msg = JSON.parse( event.originalEvent.data );
    if( msg.event === 'init' ) {
        // try loading existing diagram file
        jQuery.get(DOKU_BASE + 'lib/exe/fetch.php?media=' + fullId, function (data) {
            diagrams.postMessage(JSON.stringify({action: 'load', xml: data}), '*');
        }, 'text')
            .fail(function () { // catch 404, file does not yet exist locally
                diagrams.postMessage(JSON.stringify({action: 'load', xml: ''}), '*');
            });
    } else if ( msg.event === 'save' ) {
        diagrams.postMessage(
            JSON.stringify( {action: 'export', format: 'xmlsvg', spin: LANG.plugins.diagrams.saving } ),
            '*'
        );
        diagrams.postMessage(
            JSON.stringify( {action: 'export', format: 'png', spin: LANG.plugins.diagrams.saving } ),
            '*'
        );
    } else if ( msg.event === 'export' ) {
        const supported_formats = ['svg', 'png'];

        if (!supported_formats.includes(msg.format)) {
            alert( LANG.plugins.diagrams.errorUnsupportedFormat + ' .' + msg.format );
        }
        if ( msg.format == 'png' ) {
            var pngid = fullId.substr(0, fullId.lastIndexOf(".")) + ".png";
            if (pngid === fullId) {
                alert( LANG.plugins.diagrams.filenameError );
                return;
            }
            jQuery.post( DOKU_BASE + 'lib/exe/ajax.php', 
                {
                    call: 'plugin_diagrams',
                    action: 'savepng', 
                    id: pngid, 
                    content: msg.data
                } 
                    )
                .done( function(data) {                   
                    if (confirm( LANG.plugins.diagrams.saveSuccess )) {
                        removeDiagramsEditor(handleServiceMessages);
                        const url = new URL(location.href);
                        // media manager window should show current namespace
                        url.searchParams.set('ns', ns);
                        setTimeout( function() {
                        location.assign(url);
                        }, 200 );
                    }
                })
                .fail( function() {
                    if (confirm( LANG.plugins.diagrams.errorSaving )) {
                        removeDiagramsEditor(handleServiceMessages);
                    }
                })
        }
        else if (msg.format == 'svg') {
            const datastr = doctypeXML + '\n' +
                decodeURIComponent( atob( msg.data.split( ',' )[1] ).split( '' ).map( function( c ) {
                    return '%' + ( '00' + c.charCodeAt( 0 ).toString( 16 ) ).slice( -2 );
                } ).join( '' ) );
            jQuery.post( getLocalDiagramUrl(ns, id), datastr )
                .done( function() {
                })
                .fail( function() {
                    if (confirm( LANG.plugins.diagrams.errorSaving )) {
                        removeDiagramsEditor(handleServiceMessages);
                    }
                })
        }
    } else if( msg.event === 'exit' ) {
        removeDiagramsEditor(handleServiceMessages);
        const url = new URL(location.href);
        // media manager window should show current namespace
        url.searchParams.set('ns', ns);
        setTimeout( function() {
        location.assign(url);
        }, 200 );
    }
};