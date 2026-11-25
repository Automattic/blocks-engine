<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A style-text guard on the font reset must not prevent adding a class:
// no inline declaration is added, removed or rewritten by that handoff.
$source = '<style>body{font-family:Arial,sans-serif}button,a{font:inherit}'
    . '.purchase{display:inline-flex;font-family:Arial,sans-serif;padding:12px;background:#123456;color:white}'
    . '</style>'
    . '<p>Ordinary body copy.</p><button class="purchase">Buy now</button>';
$compile = static function (string $source): array {
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))
        ->toArray()['source_reports']['wordpress_site_plan'];
};
$plan = $compile($source);
$markup = $plan['pages'][0]['canonical_block_markup'];
if (!preg_match('/<!-- wp:button \{[^>]*"fontFamily":"([a-z0-9-]+)"/', $markup, $match)
    || !str_contains($markup, 'has-' . $match[1] . '-font-family')) {
    throw new RuntimeException('The class-owned button stack must be a block setting: ' . $markup);
}
// A changing family must remain in the authored cascade rather than be
// frozen by the important font-family preset class.
$plan = $compile(str_replace('</style>', '.purchase:hover{font-family:Georgia,serif}</style>', $source));
$markup = $plan['pages'][0]['canonical_block_markup'];
if (preg_match('/<!-- wp:button \{[^>]*"fontFamily":/', $markup)) {
    throw new RuntimeException('An interaction-dependent button font must not be pinned to a preset');
}
echo "Button class font preset ownership passed\n";
