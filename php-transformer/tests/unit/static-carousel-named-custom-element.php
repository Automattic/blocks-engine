<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Neutral reproduction from #6283: names do not prove motion or runtime ownership.
$html = '<!DOCTYPE html><html><head></head><body><main><h1>Item</h1><item-carousel class="box"><ul class="item-list" role="list"><li><img src="/media/one.jpg" alt="Item photo" width="800" height="1000"></li></ul></item-carousel></main></body></html>';
foreach ( array('carousel', 'slider', 'gallery') as $name ) {
    $result = (new HtmlTransformer())->transform(str_replace('item-carousel', 'item-' . $name, $html))->toArray();
    $names = array();
    $walk = static function (array $blocks) use (&$walk, &$names): void {
        foreach ( $blocks as $block ) {
            $names[] = $block['blockName'] ?? '';
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($result['blocks']);
    $markup = (string) json_encode($result['blocks'], JSON_UNESCAPED_SLASHES);
    if ( array() !== $result['fallbacks']
        || ! in_array('core/image', $names, true)
        || in_array('core/html', $names, true)
        || ! str_contains($markup, '/media/one.jpg')
        || ! str_contains($markup, 'Item photo')
    ) {
        fwrite(STDERR, "FAIL: static {$name} must retain editable image content without fallbacks\n");
        exit(1);
    }
}

foreach ( array(
    'inline motion' => str_replace('<li>', '<li class="slider" style="transform:translateX(-100%)">', $html),
    'stylesheet motion' => str_replace('<head></head>', '<head><style>.slider { animation: advance 5s infinite; } @keyframes advance { to { transform:translateX(-100%); } }</style></head>', str_replace('<li>', '<li class="slider">', $html)),
    'event handler' => str_replace('class="box"', 'class="box" onclick="advance()"', $html),
    'runtime definition' => str_replace('</body>', '<script>customElements.define("item-carousel", class extends HTMLElement { connectedCallback() { this.dataset.ready = "true"; } });</script></body>', $html),
    'interactivity directive' => str_replace('class="box"', 'class="box" data-wp-interactive="items"', $html),
) as $name => $source ) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    if ( array() === $result['fallbacks'] ) {
        fwrite(STDERR, "FAIL: {$name} must still fall back\n");
        exit(1);
    }
}

echo "Static carousel-named custom element tests passed\n";
