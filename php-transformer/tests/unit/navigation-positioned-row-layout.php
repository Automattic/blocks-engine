<?php
declare(strict_types=1);

/** Positioning a landmark must not turn its source row into a stack. */
require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$cases = array(
    'absolute inline-flex list' => array('nav', '#m{position:absolute}', 'display:inline-flex', false),
    'absolute flex list' => array('nav', '#m{position:absolute}', 'display:flex', false),
    'absolute inline-block items' => array('nav', '#m{position:absolute}#m li{display:inline-block}', '', false),
    'absolute inline items' => array('nav', '#m{position:absolute}#m li{display:inline}', '', false),
    'plain flex list' => array('nav', '', 'display:flex', false),
    'full-width flex list' => array('nav', '#m{width:100%}', 'display:flex', false),
    'relative flex list' => array('nav', '#m{position:relative;right:2px}', 'display:flex', false),
    'absolute div wrapper' => array('div', '#m{position:absolute}', 'display:flex', false),
    'absolute stacked rail' => array('nav', '#m{position:absolute}#m li{display:block}', '', true),
);
$failures = 0;
foreach ($cases as $name => [$tag, $css, $listStyle, $vertical]) {
    $source = '<style>' . $css . '</style><' . $tag . ' id="m"><ul class="nav" style="' . $listStyle . '">'
        . '<li><a href="/alpha">Alpha</a></li><li><a href="/beta">Beta</a></li><li><a href="/gamma">Gamma</a></li>'
        . '</ul></' . $tag . '>';
    $compiled = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $source),
    ))->toArray();
    $markup = $compiled['source_reports']['compiled_site']['pages'][0]['block_markup'];
    $navigations = array();
    $walk = static function (array $blocks) use (&$walk, &$navigations): void {
        foreach ($blocks as $block) {
            if ('core/navigation' === ($block['blockName'] ?? null)) {
                $navigations[] = $block;
            }
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk((new Runtime())->parseBlocks($markup));
    if (1 !== count($navigations)
        || $vertical !== ('vertical' === ($navigations[0]['attrs']['layout']['orientation'] ?? null))) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $name . ' layout: ' . json_encode($navigations) . PHP_EOL);
    }
}
if ($failures) {
    fwrite(STDERR, "positioned navigation layout: {$failures} FAILED\n");
    exit(1);
}
echo 'positioned navigation layout: all cases passed' . PHP_EOL;
