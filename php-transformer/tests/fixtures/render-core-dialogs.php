<?php
/** Inspect the first dialog Group while preserving serialized editable children. */
function core_dialog_match(string $markup): array
{
    $runtime = new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime();
    $find = static function (array $blocks) use (&$find, $runtime): array {
        foreach ($blocks as $block) {
            $shell = $block['attrs']['metadata']['blocksEngineDialog'] ?? null;
            if (is_array($shell)) {
                $attrs = $shell['attributes'];
                $binding = array('dialogId' => $attrs['id'] ?? '', 'triggerIds' => explode(' ', $attrs['data-blocks-engine-triggers'] ?? ''), 'closerIds' => explode(' ', $attrs['data-blocks-engine-closers'] ?? ''));
                $html = render_core_dialogs($runtime->serializeBlocks(array($block)));
                preg_match('/^(<[^>]*>)/', $html, $tag);
                return array($html, 'core/group', json_encode($binding), $tag[1] ?? '', $runtime->serializeBlocks($block['innerBlocks'] ?? array()));
            }
            if (array() !== ($found = $find($block['innerBlocks'] ?? array()))) return $found;
        }
        return array();
    };
    return $find($runtime->parseBlocks($markup));
}

/** Render Group shells for render-free browser fixtures. */
function render_core_dialogs(string $markup): string
{
    $runtime = new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime();
    $render = static function (array $blocks) use (&$render): string {
        $html = '';
        foreach ($blocks as $block) {
            $content = ''; $index = 0;
            foreach ($block['innerContent'] ?? array($block['innerHTML'] ?? '') as $chunk) {
                $content .= null === $chunk ? $render(array($block['innerBlocks'][$index++])) : $chunk;
            }
            $shell = $block['attrs']['metadata']['blocksEngineDialog'] ?? null;
            if (is_array($shell)) {
                $opening = '<' . $shell['tagName'];
                foreach ($shell['attributes'] as $name => $value) $opening .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                $opening .= '>';
                if (!empty($shell['addCloseButton'])) $opening .= '<button type="button" data-blocks-engine-dialog-close="true" aria-label="Close">Close</button>';
                $content = preg_replace('/^<div\b[^>]*>(.*)<\/div>$/s', $opening . '$1</' . $shell['tagName'] . '>', $content);
            }
            $html .= $content;
        }
        return $html;
    };
    return $render($runtime->parseBlocks($markup));
}
