<?php
declare(strict_types=1);

// An image that fills its grid cell (`width/height:100%`, `min-height:0`) is
// lowered to core/image, whose figure is display:contents as a grid item. When
// the image is linked, core's anchor between figure and image becomes the grid
// item instead. Its automatic minimum height is the image's natural height, so
// the 1fr rows grew to the square images (#5286). The author's item minimum
// must reach the anchor; with no anchor the image keeps it as before.

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/expand-compacted-selectors.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fail = static function (string $message, string $context = ''): never {
    fwrite(STDERR, "FAIL: {$message}\n{$context}\n");
    exit(1);
};
// Bodies of every rule whose selector list holds exactly $selector.
$bodies = static function (string $sheet, string $selector): array {
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', expandCompactedSelectors($sheet), $rules, PREG_SET_ORDER);
    $found = array();
    foreach ($rules as $rule) {
        if (in_array($selector, array_map('trim', explode(',', $rule[1])), true)) $found[] = $rule[2];
    }
    return $found;
};

$figure = '.mosaic :where(figure):not(be-t-0).wp-block-image:where(.blocks-engine-synthetic-flex-image-figure)';
$images = '<img src="a.png" alt=""><img src="b.png" alt=""><img src="c.png" alt=""><img src="d.png" alt="">';
$mosaic = '<span class="mosaic" role="img" aria-label="Four palettes">' . $images . '</span><span>04</span>';
$base = '.tile{display:block;position:relative;overflow:hidden;aspect-ratio:1.45;width:320px}'
    . '.mosaic{display:grid;grid-template-columns:repeat(2,1fr);grid-template-rows:repeat(2,1fr);width:100%;height:100%;overflow:hidden}';

$cases = array(
    'linked, author min-height:0' => array(
        'css' => $base . '.mosaic img{width:100%;height:100%;min-height:0;object-fit:cover;display:block}',
        'html' => '<main><a class="tile" href="/four-seasons/">' . $mosaic . '</a></main>',
        'linked' => true,
        'leaf' => 'width:100%;height:100%;min-height:0',
        'link_min' => 'min-height:0',
    ),
    'unlinked, author min-height:0' => array(
        'css' => $base . '.mosaic img{width:100%;height:100%;min-height:0;object-fit:cover;display:block}',
        'html' => '<main><div class="tile">' . $mosaic . '</div></main>',
        'linked' => false,
        'leaf' => 'width:100%;height:100%;min-height:0',
        'link_min' => null,
    ),
    // Without an authored minimum the source image's automatic minimum is its
    // natural height too, so the anchor keeps its own automatic minimum.
    'linked, no authored minimum' => array(
        'css' => $base . '.mosaic img{width:100%;height:100%;object-fit:cover;display:block}',
        'html' => '<main><a class="tile" href="/four-seasons/">' . $mosaic . '</a></main>',
        'linked' => true,
        'leaf' => 'width:100%;height:100%',
        'link_min' => '',
    ),
);

foreach ($cases as $name => $case) {
    $out = (new HtmlTransformer())->transform('<style>' . $case['css'] . '</style>' . $case['html'], array('static_css' => $case['css']))->toArray();
    $markup = (string) $out['serialized_blocks'];
    $sheet = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter((array) $out['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''))));

    $figures = preg_match_all('/<figure class="[^"]*blocks-engine-synthetic-flex-image-figure[^"]*blocks-engine-synthetic-image-figure-fill[^"]*">(<a href="\/four-seasons\/">)?<img/', $markup, $matches);
    if (4 !== $figures) $fail("{$name}: four grid-item fill image figures", $markup);
    if ($case['linked'] !== ('' !== $matches[1][0])) $fail("{$name}: link wrapper presence", $markup);

    // The image fills the box that is the grid item, with the author's minimum.
    $leaf = $figure . ($case['linked'] ? ' > a > img' : ' > img');
    if (!in_array($case['leaf'], $bodies($sheet, $leaf), true)) {
        $fail("{$name}: image leaf fills its item box", $sheet);
    }

    $linkBodies = $bodies($sheet, $figure . ' > a');
    if (null === $case['link_min']) continue;
    if ('' === $case['link_min']) {
        foreach ($linkBodies as $body) {
            if (str_contains($body, 'min-')) $fail("{$name}: anchor keeps its automatic minimum", $sheet);
        }
        continue;
    }
    if (!in_array($case['link_min'], $linkBodies, true)) $fail("{$name}: the anchor grid item carries the author's minimum height", $sheet);
    // The engine's linked-fill rule stretches the anchor to the cell.
    if (!str_contains($sheet, ':root :where(figure.blocks-engine-synthetic-image-figure-fill.blocks-engine-linked-image-figure)>a{display:block;width:100%;height:100%}')) {
        $fail("{$name}: the anchor fills the cell", $sheet);
    }
}

echo "Linked grid fill image minimum size passed.\n";
