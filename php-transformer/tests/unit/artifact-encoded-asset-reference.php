<?php
declare(strict_types=1);
require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Both document-relative and site-root references must find the on-disk path.
foreach (array('dir@1' => 'dir%401', 'dir space' => 'dir%20space', 'dir+1' => 'dir%2B1') as $directory => $encoded) {
    foreach (array('', '/') as $root) {
        $results = array();
        foreach (array($directory, $encoded) as $reference) {
            $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
                'index.html' => '<html><head><link rel="stylesheet" href="' . $root . $reference . '/grid.css?ver=1#sheet"></head><body><main><div class="loop"><article><p>First</p></article><article><p>Second</p></article></div></main></body></html>',
                $directory . '/grid.css' => 'body{--n:2}.loop{display:grid;grid-template-columns:repeat(var(--n),minmax(0,1fr))}',
            )))->toArray();
            $markup = $result['serialized_blocks'];
            if (!str_contains($markup, 'loop blocks-engine-css-owned-layout blocks-engine-css-owned-grid') || str_contains($markup, '"layout":{"type":"grid"')) {
                throw new RuntimeException('Encoded stylesheet references must retain author grid ownership: ' . $root . $reference);
            }
            $results[] = $markup;
            $plan = $result['source_reports']['wordpress_site_plan'];
            $styles = array_values(array_filter(ThemeBootstrap::documentStylesheets($plan, 'index.html'), static fn(array $row): bool => 'grid.css' === basename($row['target_path'])));
            if (1 !== count($styles)) throw new RuntimeException('The linked stylesheet must be enqueued exactly once.');
            $bootstrap = (string) array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
            if (1 !== substr_count($bootstrap, "wp_enqueue_style( '" . $styles[0]['handle'] . "'")) throw new RuntimeException('The emitted theme must enqueue the stylesheet once.');
        }
        if ($results[0] !== $results[1]) throw new RuntimeException('Encoding a stylesheet href must not change converted blocks.');
    }
}
echo "Artifact encoded asset references passed\n";
