<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Captures can retain font descriptors after removing unavailable sources.
// Those faces are inert; local() alternatives and real sources must survive.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<link rel="stylesheet" href="site.css"><main><h1>Fonts</h1></main>',
    'site.css' => '@font-face{font-family:EmptyFace;font-weight:400}'
        . '@media screen{@font-face{font-family:NestedEmpty;font-style:italic}}'
        . '@font-face{font-family:LocalFace;src:local("Arial")}'
        . '@font-face{font-family:RemoteFace;src:url(https://example.org/font.woff2)}'
        . 'h1{color:red}',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
if (array() === $plan) throw new RuntimeException('Expected a materializable site plan.');
$css = implode("\n", array_map(static fn (array $write): string => (string) ($write['payload']['data'] ?? ''), array_filter($plan['writes'] ?? array(), static fn (array $write): bool => str_ends_with($write['target_path'] ?? '', 'site.css'))));
foreach (array('EmptyFace' => false, 'NestedEmpty' => false, 'LocalFace' => true, 'RemoteFace' => true, 'h1{color:' => true) as $needle => $expected) {
    if ($expected !== str_contains($css, $needle)) throw new RuntimeException('Unexpected font recovery output for ' . $needle . ': ' . $css);
}
echo "Sourceless font face tests passed\n";
