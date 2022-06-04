<?php

/**
 * Action component of diagrams plugin
 */
class action_plugin_diagrams extends DokuWiki_Action_Plugin
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
        $controller->register_hook('PLUGIN_MOVE_HANDLERS_REGISTER', 'BEFORE', $this, 'handle_move_register');
    }

    /**
     * Add data to JSINFO: full service URL and security token used for uploading
     *
     * @param Doku_Event $event
     */
    public function addJsinfo(Doku_Event $event)
    {
        global $JSINFO;
        $JSINFO['sectok'] = getSecurityToken();
        $JSINFO['plugins']['diagrams']['service_url'] = $this->getConf('service_url');
        $JSINFO['plugins']['diagrams']['xsvg_style'] = $this->getConf('xsvg_style');
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
        if ($event->data !== 'plugin_diagrams') return;
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;
        global $conf;
        
        $action = $INPUT->str('action');

        if ($action == 'savepng') {
            if (!$this->getConf('exportPng')) {
                return http_response_code(200);
            }
            // Write content to file
            $fid = $INPUT->str('id');
            $media_id = cleanID($fid);
            $media_path = mediaFN($media_id);

            // Check for appropriate permissions
            if (auth_quickaclcheck($media_id) < AUTH_UPLOAD) {
                return http_response_code(403);
            }

            // Save old revision
            if ($this->getConf('mediaRevisionsOnExtra')) {
                $old = @filemtime($media_path);
                if(!file_exists(mediaFN($media_id, $old)) && file_exists($media_path)) {
                    // add old revision to the attic if missing
                    media_saveOldRevision($media_id);
                }
                $filesize_old = file_exists($media_path) ? filesize($media_path) : 0;
            }

            // Write the content to file. We know this namespace must already exist. This occuers after svg save
            $content = $INPUT->str('content');
            $base64data = explode(",", $content)[1];
            $whandle = fopen($media_path, 'w');
            fwrite($whandle,base64_decode($base64data));
            fclose($whandle);

            // clear cache time
            @clearstatcache(true, $media_path);
            $new = @filemtime($media_path);
            chmod($media_path, $conf['fmode']);

            // Add to log history
            if ($this->getConf('mediaRevisionsOnExtra')) {
                $filesize_new = filesize($media_path);
                $sizechange = $filesize_new - $filesize_old;
                if ($filesize_old != 0) {
                    addMediaLogEntry($new, $media_id, DOKU_CHANGE_TYPE_EDIT, '', '', null, $sizechange);
                } else {
                    addMediaLogEntry($new, $media_id, DOKU_CHANGE_TYPE_CREATE, $lang['created'], '', null, $sizechange);
                }
            }

            return http_response_code(200);

        } else {
            // Checking permissions of images
           $images = $INPUT->arr('images');
           echo json_encode($this->editableDiagrams($images)); 
        }
    }

    /**
     * Return an array of diagrams editable by the current user
     *
     * @param array $images
     * @return array
     */
    protected function editableDiagrams($images)
    {
        $editable = [];

        foreach ($images as $image) {
            $img_id = ($image[0] == ':') ? substr($image, 1) : $image;
            if (auth_quickaclcheck($img_id) >= AUTH_UPLOAD && $this->isDiagram($image)) {
                $editable[] = $image;
            }
        }
        return $editable;
    }

    /**
     * Return true if the image is recognized as our diagram
     * based on content ('embed.diagrams.net' or 'draw.io')
     *
     * @param string $image image id
     * @return bool
     */
    protected function isDiagram($image)
    {
        global $conf;
        // strip nocache parameters from image
        $image = explode('&', $image);
        $image = $image[0];

        $file = DOKU_INC .
            $conf['savedir'] .
            DIRECTORY_SEPARATOR .
            'media' .
            DIRECTORY_SEPARATOR .
            preg_replace(['/:/'], [DIRECTORY_SEPARATOR], $image);

        $begin = file_get_contents($file, false, null, 0, 500);
        $confServiceUrl = $this->getConf('service_url'); // like "https://diagrams.xyz.org/?embed=1&..."
        $serviceHost = parse_url($confServiceUrl, PHP_URL_HOST); // Host-Portion of the Url, e.g. "diagrams.xyz.org"
        return strpos($begin, 'embed.diagrams.net') || strpos($begin, 'draw.io') || strpos($begin, $serviceHost);
    }

    public function handle_move_register(Doku_Event $event, $params) {
        $event->data['handlers']['diagrams'] = array($this, 'rewrite_diagram');
    }

    public function rewrite_diagram($match, $state, $pos, $pluginname, helper_plugin_move_handler $handler) {
        $handler->media($match, $state, $pos);
    }
}
