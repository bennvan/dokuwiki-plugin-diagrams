<?php

/**
 * Class syntax_plugin_diagrams
 */
class syntax_plugin_diagrams extends DokuWiki_Syntax_Plugin {

    /**
     * @inheritdoc
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * @inheritdoc
     */
    public function getSort()
    {
        return 319;
    }

    /**
     * @inheritdoc
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('\{\{[^\}]+(?:\.svg)[^\}]*?\}\}',$mode,'plugin_diagrams');
    }

    /**
     * Parse SVG syntax into media data
     *
     * @param string $match
     * @param int $state
     * @param int $pos
     * @param Doku_Handler $handler
     * @return array|bool
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $p = \dokuwiki\Parsing\ParserMode\Media::parseMedia($match);
        // Inspect only the parameter block, never change filenames or captions.
        $source = trim(explode('|', substr($match, 2, -2), 2)[0]);
        $query = strrpos($source, '?');
        $responsive = $query !== false && in_array('responsive', explode('&', substr($source, $query + 1)), true);
        
        $handler->addCall(
                    $p['type'],
                    array($p['src'], $p['title'], $p['align'], $p['width'],
                    $p['height'], $p['cache'], $p['linking'], true),
                    $pos
                );

        // Do we let the svg be responsive or scroll overflow?
        $p['wrapper_class'] = $responsive ? 'diagrams-responsive' : 'diagrams-overflow';

        return $p;
    }

    /**
     * Render the diagram SVG as <object> instead of <img> to allow links,
     * except when rendering to a PDF
     *
     * @param string $format
     * @param Doku_Renderer $renderer
     * @param array $data
     * @return bool
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml') return false;

        global $ID;

        if ($data['linking'] === 'linkonly') {
            $method = $data['type'] === 'externalmedia' ? 'externalmedia' : 'internalmedia';
            $renderer->$method($data['src'], $data['title'], $data['align'], $data['width'], $data['height'],
                $data['cache'], $data['linking']);
            return true;
        }

        if ($data['type'] == 'internalmedia') {
            $data['src'] = (new \dokuwiki\File\MediaResolver($ID))->resolveId($data['src'], $renderer->date_at, true);
        }

        if(is_a($renderer, 'renderer_plugin_dw2pdf')) {
            $imageAttributes = array(
                'class'   => 'media',
                'src'     => ml($data['src'], '', true, '&'),
                'width'   => $data['width'],
                'height'  => $data['height'],
                'align'   => $data['align'],
                'title'   => $data['title']
            );
            $renderer->doc .= '<img '. buildAttributes(array_filter($imageAttributes, static fn($value) => $value !== null)) . '/>';
        } else {
            $attributes = [
                // Use a fresh URL after the diagram CSP change: browsers may retain
                // the old policy alongside a cached SVG, even when its contents match.
                'data' => ml($data['src'], ['cache' => 'nocache', 'diagram-rev' => 1], true, '&'),
                'type' => 'image/svg+xml',
                'class' => 'diagrams-svg media' . $data['align'],
                'width' => $data['width'],
                'height' => $data['height'],
                'title' => $data['title'],
            ];
            $renderer->doc .= '<div class="' . hsc($data['wrapper_class']) . ' geDiagramContainer"><object ' .
                buildAttributes(array_filter($attributes, static fn($value) => $value !== null)) . '></object></div>';
        }

        return true;
    }
}
