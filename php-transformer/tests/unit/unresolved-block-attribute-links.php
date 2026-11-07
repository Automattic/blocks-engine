<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A link to a page missing from the artifact is neutralized wherever the link
// lives: markup hrefs and block attributes (navigation items, card wrappers).
$shapes = array(
    'paragraph link' => '<p><a href="./o-mnie/index.html">O mnie</a></p>',
    'navigation item' => '<header><nav><ul><li><a href="./o-mnie/index.html">O mnie</a></li><li><a href="./index.html">Start</a></li></ul></nav></header>',
    'card link' => '<a href="./o-mnie/index.html" class="card"><h3>O mnie</h3><p>Text</p></a>',
);
$failures = 0;
foreach ( $shapes as $name => $fragment ) {
    try {
        $plan = ( new WordPressSitePlan() )->fromCompilerResult(( new ArtifactCompiler() )->compile(array(
            'entrypoint' => 'index.html',
            'files'      => array( 'index.html' => '<html><body><main><h1>Start</h1>' . $fragment . '</main></body></html>' ),
        ))->toArray());
    } catch ( Throwable $error ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$name} rejects the plan: {$error->getMessage()}\n");
        continue;
    }
    $markup = '';
    array_walk_recursive($plan, static function (mixed $value, int|string $key) use (&$markup): void {
        if ( 'canonical_block_markup' === $key && is_string($value) ) $markup .= $value;
    });
    if ( str_contains($markup, 'o-mnie/index.html') || !str_contains(json_encode($plan), 'wordpress_site_plan_unresolved_navigation_link') ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$name} keeps the missing page link or reports no warning\n");
    }
}
if ( $failures > 0 ) exit(1);
echo "unresolved block attribute links: passed\n";
