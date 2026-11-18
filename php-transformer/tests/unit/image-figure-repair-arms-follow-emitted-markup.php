<?php
declare(strict_types=1);

// Each projected author image rule used to carry a layout-table inline-image
// arm, an inline-figure alignment arm and a flex-item box arm whatever the
// converted page held. Those arms only reach figures carrying their engine
// classes, so a page with none of them shipped kilobytes of dead selectors
// (Automattic/blocks-engine#6145, theme_css_weight). They now follow the
// emitted markup, as the line-box figure rule and the support CSS already do.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message, string $context = ''): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n{$context}\n"); exit(1); }
};
$convert = static function (string $html): array {
    $out = (new HtmlTransformer())->transform($html)->toArray();
    $css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter((array) ($out['assets'] ?? array()), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''))));
    return array((string) $out['serialized_blocks'], $css);
};
$styles = '<style>.card{display:flex;gap:16px}.thumb{width:140px;height:100px;vertical-align:middle}</style>';

// 1. Block-flow and flex-item images whose figures carry none of those classes.
foreach (array(
    'block flow' => '<main><div><img class="thumb" src="a.png" alt="A"></div></main>',
    'flex item' => '<main><div class="card"><img class="thumb" src="a.png" alt="A"><img class="thumb" src="b.png" alt="B"></div></main>',
) as $label => $body) {
    [$markup, $css] = $convert($styles . $body);
    $assert(str_contains($css, '.thumb.wp-block-image > img'), "{$label}: the author image rule still projects onto the image", $css);
    foreach (array('blocks-engine-layout-table-cell', 'blocks-engine-synthetic-image-figure-inline', 'blocks-engine-synthetic-flex-image-figure') as $class) {
        if (str_contains($markup, $class)) continue;
        $assert(!str_contains($css, $class), "{$label}: no projected arm names {$class}, which no emitted figure carries", $css);
    }
}

// 2. An inline image in a layout table keeps its table-cell line-box arm.
[$markup, $css] = $convert('<style>.thumb{width:140px;vertical-align:middle}</style><main><table width="600" border="0" cellpadding="0" cellspacing="0"><tr><td width="200"><img class="thumb" src="a.png" alt="A"> Logo</td><td width="400"><p>Some content here</p></td></tr></table></main>');
$assert(str_contains($markup, 'blocks-engine-layout-table-cell') && str_contains($markup, 'blocks-engine-synthetic-image-figure-inline'), 'the table image is an inline figure in a layout cell', $markup);
$assert(str_contains($css, ':where(.blocks-engine-layout-table-cell) .thumb.wp-block-image:where(.blocks-engine-synthetic-image-figure-inline) > img'), 'the layout-cell inline image keeps its line-box arm', $css);
$assert(str_contains($css, '{display:inline;max-width:none;vertical-align:middle}'), 'the line-box arm keeps the authored alignment', $css);

fwrite(STDOUT, "image figure repair arms follow emitted markup tests: passed\n");
