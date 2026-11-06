<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A theme stylesheet shared across sites styles widgets this site never
// renders. Arms requiring a class or id no site file names are dropped;
// script-built, state and engine names stay, as do rules with no such need.
$css = '/*! Theme v1 */ .card .absent-widget,.card{color:red}'
    . '#absent-id .card{color:blue}'
    . '.card:not(.absent-modifier){color:green}'
    . '.slot-3{color:teal}'
    . '.card.is-open{color:orange}'
    . '.card [class*=absent-]{color:purple}'
    . '@media (min-width:600px){.absent-grid{color:navy}}';
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<link rel="stylesheet" href="site.css"><main><div class="card"><p>Card</p></div></main>'
        . '<script>document.querySelector("main").classList.add("slot-" + 3)</script>',
    'site.css' => $css,
)))->toArray();
$out = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($compiled['assets'] ?? array(), static fn (array $asset): bool => 'site.css' === ($asset['path'] ?? null))));
$expected = array('red' => true, 'blue' => false, 'green' => true, 'teal' => true, 'orange' => true, 'purple' => true, 'navy' => false, 'absent-widget' => false, 'Theme v1' => true, '@media' => false);
foreach ($expected as $needle => $kept) {
    if ($kept !== str_contains($out, $needle)) {
        throw new RuntimeException(sprintf('%s should %sremain:%s%s', $needle, $kept ? '' : 'not ', PHP_EOL, $out));
    }
}
echo "Unreachable selector pruning passed\n";
