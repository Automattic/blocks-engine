<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'heading' => array('<div class="visual">Artwork</div><div><h3>Title</h3><p>Text</p></div>', 'h3', false),
    'editorial visual' => array('<div class="editorial-visual aspect-[16/10]"></div><div><h3>Title</h3><p>Summary</p></div>', 'h3', false),
    'plain card' => array('<h3>Title</h3><p>Summary</p>', 'h3', false),
    'read-more icon' => array('<div class="visual"></div><div><h3>Title</h3><p>Summary</p><span>Read guide <svg viewBox="0 0 24 24"><path d="M7 7h10v10"></path></svg></span></div>', 'h3', false),
    'trailing cue' => array('<div class="icon flex"></div><div><h3>Title</h3><p>Summary</p></div><span class="ml-auto">→</span>', 'h3', false),
    'text' => array('<div class="visual"></div><p>Text</p>', 'p', false),
    'button' => array('<div><h3>Title</h3><button>Choose</button></div>', '', true),
    'no carrier' => array('<pre>Code</pre>', '', true),
    'single label' => array('<div><p>Label</p></div>', '', true),
    'media card' => array('<img src="cover.png" alt=""><h3>Title</h3><p>Summary</p>', '', true),
    'icon row' => array('<svg viewBox="0 0 24 24"></svg><h3>Title</h3><p>Summary</p>', '', true),
    'headingless cue' => array('<div class="visual"></div><p>Text</p><span>→</span>', '', true),
    'role control' => array('<h3>Title</h3><p>Summary</p><span role="button">Choose</span>', '', true),
);
foreach ($cases as $name => [$content, $carrier, $shell]) {
    $card = '<a class="group block" id="card" href="/x/" target="_blank" rel="noopener">' . $content . '</a>';
    $out = (new HtmlTransformer())->transform('<main>' . $card . str_replace('id="card"', 'id="second"', $card) . '</main>')->toArray();
    $markup = $out['serialized_blocks'];
    if ($shell) {
        if (!str_contains($markup, '/layout-shell')) {
            throw new RuntimeException($name . ': unsafe cards must retain the layout shell');
        }
        continue;
    }
    if (str_contains($markup, '/layout-shell')) {
        throw new RuntimeException($name . ': safe cards must be native');
    }
    $saved = preg_replace('/<!--\s*\/?wp:[^>]*?-->/s', '', $markup);
    $again = (new HtmlTransformer())->transform($saved)->toArray();
    if ($markup !== $again['serialized_blocks']) {
        throw new RuntimeException($name . ': saved stretched cards must be a fixed point' . "\n" . $markup . "\n" . $again['serialized_blocks']);
    }
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $xpath = new DOMXPath($dom);
    $groups = $xpath->query('//div[@id="card" and contains(concat(" ", @class, " "), " group ") and contains(concat(" ", @class, " "), " block ")]');
    $links = $xpath->query('//' . $carrier . '/a[@href="/x/" and @target="_blank" and @rel="noopener"]');
    if (1 !== $groups->length || 2 !== $links->length || 2 !== $xpath->query('//a')->length) {
        throw new RuntimeException($name . ': card identity and single preferred text destination must survive');
    }
    $css = implode("\n", array_column($out['assets'], 'content'));
    if (1 !== substr_count($css, ':where(body:not(.editor-styles-wrapper):not(.wp-admin)) .blocks-engine-stretched-link-carrier>a::after{content:"";position:absolute;inset:0}')
        || !str_contains($css, ':where(.blocks-engine-stretched-link){position:relative}')) {
        throw new RuntimeException($name . ': multi-card pages must share one stretch rule');
    }
}
echo "Stretched link card contract passed.\n";
