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
        // Remove responsive flag
        $count = false;
        $match = preg_replace('/&?responsive&?/', '', $match, 1, $count);

        $p = Doku_Handler_Parse_Media($match);
        
        $handler->addCall(
                    $p['type'],
                    array($p['src'], $p['title'], $p['align'], $p['width'],
                    $p['height'], $p['cache'], $p['linking'], true),
                    null
                );

        // Do we let the svg be responsive or scroll overflow?
        $p['wrapper_class'] = ($count) ? 'diagrams-responsive' : 'diagrams-overflow';

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
            $renderer->internalmedia($data['src'], $data['title'], $data['align'], $data['width'], $data['height'],
                $data['cache'], $data['linking']);
            return true;
        }

        if ($data['type'] == 'internalmedia') {
            resolve_mediaid(getNS($ID), $data['src'], $exists, $renderer->date_at, true);
        }

        if(is_a($renderer, 'renderer_plugin_dw2pdf')) {
            $imageAttributes = array(
                'class'   => 'media',
                'src'     => ml($data['src']),
                'width'   => $data['width'],
                'height'  => $data['height'],
                'align'   => $data['align'],
                'title'   => $data['title']
            );
            $renderer->doc .= '<img '. buildAttributes($imageAttributes) . '/>';
        } else {
            $width = $data['width'] ? 'width="' . $data['width'] . '"' : '';
            $height = $data['height'] ? 'height="' . $data['height'] . '"' : '';
            $tag = '<div class="'.$data['wrapper_class'].' geDiagramContainer"><object data="%s&cache=nocache" type="image/svg+xml" class="diagrams-svg media%s" %s %s></object></div>';
            $renderer->doc .= sprintf($tag, ml($data['src']), $data['align'], $width, $height);
        }

        return true;
    }
}
