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
        $controller->register_hook('MEDIA_SENDFILE', 'BEFORE', $this, 'allowEmbeddedDiagramImages');
        $controller->register_hook('PLUGIN_MOVE_HANDLERS_REGISTER', 'BEFORE', $this, 'handle_move_register');
    }

    /** Allow images embedded in local diagram SVGs; keep scripts and remote loads blocked. */
    public function allowEmbeddedDiagramImages(Doku_Event $event)
    {
        $data = &$event->data;
        if ($data['mime'] !== 'image/svg+xml' || media_isexternal($data['media']) ||
            !$this->isDiagramFile($data['file'])) return;

        $sources = $data['csp']['img-src'] ?? [];
        if (is_string($sources)) $sources = preg_split('/\s+/', trim($sources));
        $sources = array_diff($sources, ["'none'", '']);
        $sources[] = 'data:';
        $data['csp']['img-src'] = array_values(array_unique($sources));
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
        $JSINFO['plugins']['diagrams']['exportPng'] = (bool) $this->getConf('exportPng');
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
        global $lang;
        
        $action = $INPUT->str('action');

        if ($action == 'savepng') {
            if ($INPUT->server->str('REQUEST_METHOD') !== 'POST' || !checkSecurityToken($INPUT->post->str('sectok'))) {
                return http_response_code(403);
            }
            if (!$this->getConf('exportPng')) {
                return http_response_code(200);
            }
            // Write content to file
            $fid = $INPUT->post->str('id');
            $media_id = cleanID($fid);
            $media_path = mediaFN($media_id);

            // Check for appropriate permissions
            $auth = auth_quickaclcheck(mediaAclPath($media_id));
            if ($auth < AUTH_UPLOAD || (file_exists($media_path) && !$conf['mediarevisions'] && $auth < AUTH_DELETE)) {
                return http_response_code(403);
            }

            $content = $INPUT->post->str('content');
            if ((getMimeTypes()['png'] ?? null) !== 'image/png' ||
                !str_ends_with($media_id, '.png') || !str_starts_with($content, 'data:image/png;base64,')) {
                return http_response_code(400);
            }
            $png = base64_decode(substr($content, strlen('data:image/png;base64,')), true);
            $info = $png === false || $png === '' ? false : @getimagesizefromstring($png);
            if (!$info || $info[2] !== IMAGETYPE_PNG) return http_response_code(400);
            // Use the core content checks before writing or saving a revision.
            $temp = tempnam($conf['tmpdir'], 'diagrams-');
            if ($temp === false) return http_response_code(500);
            try {
                if (file_put_contents($temp, $png) !== strlen($png)) return http_response_code(500);
                if (media_contentcheck($temp, 'image/png') !== 0) return http_response_code(400);
            } finally {
                unlink($temp);
            }
            // A successful SVG upload creates this directory before PNG export starts.
            if (!is_dir(dirname($media_path))) return http_response_code(400);

            // Save old revision
            if ($this->getConf('mediaRevisionsOnExtra')) {
                $old = @filemtime($media_path);
                if(!file_exists(mediaFN($media_id, $old)) && file_exists($media_path)) {
                    // add old revision to the attic if missing
                    media_saveOldRevision($media_id);
                }
                $filesize_old = file_exists($media_path) ? filesize($media_path) : 0;
            }

            if (!io_saveFile($media_path, $png)) return http_response_code(500);

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
           header('Content-Type: application/json; charset=utf-8');
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
            if (!is_string($image) || $image === '') continue;
            $img_id = cleanID(explode('&', $image, 2)[0]);
            if (!str_ends_with($img_id, '.svg')) continue;
            if (auth_quickaclcheck(mediaAclPath($img_id)) >= AUTH_UPLOAD && $this->isDiagram($img_id)) {
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
        $file = mediaFN(cleanID(explode('&', $image, 2)[0]));
        return $this->isDiagramFile($file);
    }

    /** Inspect the actual file being served, including historical revisions. */
    protected function isDiagramFile($file)
    {
        if (!is_file($file) || !is_readable($file)) return false;
        $begin = file_get_contents($file, false, null, 0, 500);
        if ($begin === false) return false;
        $confServiceUrl = $this->getConf('service_url'); // like "https://diagrams.xyz.org/?embed=1&..."
        $serviceHost = parse_url($confServiceUrl, PHP_URL_HOST); // Host-Portion of the Url, e.g. "diagrams.xyz.org"
        return str_contains($begin, 'embed.diagrams.net') || str_contains($begin, 'draw.io') ||
            (is_string($serviceHost) && $serviceHost !== '' && str_contains($begin, $serviceHost));
    }

    public function handle_move_register(Doku_Event $event, $params) {
        $event->data['handlers']['diagrams'] = array($this, 'rewrite_diagram');
    }

    public function rewrite_diagram($match, $state, $pos, $pluginname, helper_plugin_move_handler $handler) {
        $handler->media($match, $state, $pos);
    }
}
