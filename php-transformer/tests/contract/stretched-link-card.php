<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'heading' => array('<div class="visual">Artwork</div><div><h3>Title</h3><p>Text</p></div>', 'h3', false),
    'text' => array('<div class="visual"></div><p>Text</p>', 'p', false),
    'button' => array('<div><h3>Title</h3><button>Choose</button></div>', '', true),
    'no carrier' => array('<pre>Code</pre>', '', true),
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
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $xpath = new DOMXPath($dom);
    $groups = $xpath->query('//div[@id="card" and contains(concat(" ", @class, " "), " group ") and contains(concat(" ", @class, " "), " block ")]');
    $links = $xpath->query('//' . $carrier . '/a[@href="/x/" and @target="_blank" and @rel="noopener"]');
    if (1 !== $groups->length || 2 !== $links->length || 2 !== $xpath->query('//a')->length) {
        throw new RuntimeException($name . ': card identity and single preferred text destination must survive');
    }
    $css = implode("\n", array_column($out['assets'], 'content'));
    if (1 !== substr_count($css, '.blocks-engine-stretched-link-carrier>a::after{content:"";position:absolute;inset:0}')
        || !str_contains($css, '.blocks-engine-stretched-link{position:relative}')) {
        throw new RuntimeException($name . ': multi-card pages must share one stretch rule');
    }
}
echo "Stretched link card contract passed.\n";
