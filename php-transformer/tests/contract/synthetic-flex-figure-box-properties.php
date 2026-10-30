<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Reduced from the supplied header: align-items:center, gap:8px, a flag
// followed by a 10x7 SVG with margin:4px 7px. Dropdown, navigation, flag
// shadow and inherited text paint do not contribute to the item spacing.
// Keep only those layout rules, then exercise other contents-box properties.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
foreach (array('svg', 'img') as $tag) {
    foreach (array(false, true) as $inline) {
        $box = 'margin:4px 7px;margin-left:7px;padding:2px;border:1px solid red;box-sizing:border-box;align-self:center;width:10px;height:7px';
        $style = $inline ? ' style="' . $box . '"' : '';
        $icon = 'svg' === $tag
            ? '<svg class="indicator" width="10" height="7" viewBox="0 0 10 7"' . $style . '><path d="M0 0L5 7L10 0Z"/></svg>'
            : '<img class="indicator" src="indicator.png" width="10" height="7" alt=""' . $style . '>';
        $out = (new HtmlTransformer())->transform('<style>.row{display:flex;align-items:center;gap:8px}'
            . ($inline ? '' : '.indicator{' . $box . '}@media(max-width:600px){.indicator{margin-left:3px}}')
            . '</style><main><div class="row"><img src="flag.png" width="20" height="14" alt="Flag">'
            . $icon . '</div></main>')->toArray();
        $markup = (string) $out['serialized_blocks'];
        $sheet = implode("\n", array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) $asset['content'] : '', $out['assets']));
        $assert(2 === substr_count($markup, '<!-- wp:image '), 'Both items remain native core/image blocks.');
        $assert(!str_contains($markup, '<!-- wp:html'), 'No raw HTML escape hatch.');
        $assert(1 === preg_match('/<figure class="([^"]*indicator[^\"]*)"/', $markup, $figure), 'Indicator figure exists.');
        $assert(str_contains($figure[1], 'blocks-engine-synthetic-flex-image-figure'), 'Indicator has a transparent flex figure.');
        $found = array();
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $sheet, $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            // A declaration on the figure alone is insufficient: require an
            // image-leaf selector attached to this very figure's identity.
            foreach (explode(',', $rule[1]) as $selector) {
                if (!preg_match('/>\s*img\s*$/', trim($selector))) continue;
                foreach (explode(' ', $figure[1]) as $class) {
                    if ('indicator' !== $class && !str_starts_with($class, 'be-inline-geometry-')) continue;
                    if (!str_contains($selector, '.' . $class)) continue;
                    foreach (array('margin:4px 7px', 'margin-left:7px', 'padding:2px', 'border:1px solid red', 'box-sizing:border-box', 'align-self:center', 'width:10px', 'height:7px') as $declaration) {
                        if (str_contains($rule[2], $declaration)) $found[$declaration] = true;
                    }
                }
            }
        }
        $assert(8 === count($found), $tag . ': source box properties must reach the box-generating img, not just display:contents.');
        if (!$inline) {
            $assert(1 === preg_match('/@media[^{}]*600px[^{}]*\{.*?>\s*img[^{}]*\{[^{}]*margin-left:3px/s', $sheet), 'Responsive spacing keeps its media condition.');
            $assert(!preg_match('/margin-left:7px\s*!important/', $sheet), 'Stylesheet spacing is not frozen into an important desktop carrier.');
        }
    }
}
echo "synthetic flex figure box properties ok\n";
