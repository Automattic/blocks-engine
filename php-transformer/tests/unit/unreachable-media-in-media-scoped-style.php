<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\UnreachableMediaPruner;

// A device-split capture publishes a source sheet once per viewport range,
// each copy behind its own media. Nested @media rules that range excludes can
// never apply and must not be shipped; reachable and unreadable ones stay.
$desktop = '.hero{color:red}'
    . '@media screen and (max-width: 1023px){.hero{color:DeadMobileRule}}'
    . '@media (min-width: 1200px){.hero{color:WideRule}}'
    . '@media print{.hero{color:PrintRule}}'
    . '@media not screen and (max-width: 1023px){.hero{color:NegatedRule}}'
    . '@supports (display:grid){@media (max-width: 600px){.hero{color:NestedDeadRule}}}';
$mobile = '.hero{color:blue}@media screen and (max-width: 1023px){.hero{color:LiveMobileRule}}'
    . '@media (min-width: 1024px){.hero{color:DeadDesktopRule}}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<style media="(min-width:1024px)">' . $desktop . '</style>'
        . '<style media="(max-width:1023px)">' . $mobile . '</style>'
        . '<main><h1 class="hero">Hero</h1></main>',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
if (array() === $plan) throw new RuntimeException('Expected a materializable site plan.');
$css = implode("\n", array_map(static fn (array $write): string => (string) ($write['payload']['data'] ?? ''), array_filter($plan['writes'] ?? array(), static fn (array $write): bool => str_ends_with($write['target_path'] ?? '', '.css'))));
$expectations = array(
    'DeadMobileRule' => false,
    'NestedDeadRule' => false,
    'DeadDesktopRule' => false,
    'WideRule' => true,
    'PrintRule' => true,
    'NegatedRule' => true,
    'LiveMobileRule' => true,
);
foreach ($expectations as $needle => $expected) {
    if ($expected !== str_contains($css, $needle)) throw new RuntimeException('Unexpected media-scoped style output for ' . $needle . ': ' . $css);
}

foreach (array(
    array('(min-width:1024px)', 'screen and (max-width: 1023px)', true),
    array('(min-width:1024px)', '(max-width: 1024px)', false),
    array('screen', 'print', true),
    array('(min-width:1024px)', '(max-width: 40em)', false),
    array('(min-width:1024px)', '(max-width: 600px), (min-width: 1300px)', false),
    array('', '(max-width: 1px)', false),
) as [$outer, $inner, $disjoint]) {
    if ($disjoint !== UnreachableMediaPruner::disjoint($outer, $inner)) throw new RuntimeException('Unexpected disjointness for ' . $outer . ' / ' . $inner);
}
echo "Unreachable media in media-scoped style tests passed\n";
