<?php

class action_plugin_drawio extends DokuWiki_Action_Plugin
{

    /**
     * Registers a callback function for a given event
     *
     * @param \Doku_Event_Handler $controller
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'addJsinfo');
        $controller->register_hook('MEDIAMANAGER_STARTED', 'AFTER', $this, 'addJsinfo');
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'checkConf');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
    }

    /**
     * Add security token to JSINFO, used for uploading
     *
     * @param Doku_Event $event
     */
    public function addJsinfo(Doku_Event $event)
    {
        global $JSINFO;
        $JSINFO['sectok'] = getSecurityToken();
    }

    /**
     * Check if DokuWiki is properly configured to handle SVG diagrams
     *
     * @param Doku_Event $event
     */
    public function checkConf(Doku_Event $event)
    {
        $mime = getMimeTypes();
        if (!array_key_exists('svg', $mime) || $mime['svg'] !== 'image/svg+xml') {
            msg($this->getLang('missingConfig'), -1);
        }
    }

    /**
     * Check all supplied images and return only editable diagrams
     *
     * @param Doku_Event $event
     */
    public function handleAjax(Doku_Event $event)
    {
        if ($event->data !== 'plugin_drawio') return;
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;
        $images = $INPUT->arr('images');

        echo json_encode($this->editableDiagrams($images));
    }

    /**
     * Return an array of diagrams that are editable,
     * based on ACLs and image content ('embed.diagrams.net' or 'draw.io')
     *
     * @param array $images
     * @return array
     */
    protected function editableDiagrams($images)
    {
        $editable = [];

        foreach ($images as $image) {
            // skip non SVG files
            if (strpos($image, '.svg', - strlen('.svg')) === false) {
                continue;
            }
            // check ACLs
            if (auth_quickaclcheck($image) < AUTH_UPLOAD) {
                continue;
            }
            // is it our diagram?
            global $conf;
            $file = DOKU_INC .
                $conf['savedir'] .
                DIRECTORY_SEPARATOR .
                'media' .
                DIRECTORY_SEPARATOR .
                preg_replace(['/:/'], [DIRECTORY_SEPARATOR], $image);

            $begin = file_get_contents($file, false, null, 0, 500);
            // TODO find a better way to detect diagrams
            if (strpos($begin, 'embed.diagrams.net') || strpos($begin, 'draw.io')) {
                $editable[] = $image;
            }
        }

        return $editable;
    }
}
