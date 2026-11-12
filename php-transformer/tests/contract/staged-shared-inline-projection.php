<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/BootstrapStyles.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Read only generated literal data, without running the WordPress bootstrap.
$registry = static function (string $bootstrap, string $name): array {
    return \Automattic\BlocksEngine\PhpTransformer\Tests\Support\BootstrapStyles::registry($bootstrap, $name);
};

// Each page envelope re-extracts the same inline sheet. Terminal reduction
// must merge its occurrence records instead of projecting the path N times.
// Image bridge rules remain authored CSS; @namespace forces page deltas.
$bootstrapCounts = array();
foreach (array(3, 6) as $count) {
    $files = array();
    for ($i = 0; $i < $count; ++$i) {
        $files[] = array('path' => "page-$i.html", 'metadata' => array('compilation' => array('scope' => 'page', 'id' => "page-$i.html")), 'content' => '<html><head><style>@namespace svg url(http://www.w3.org/2000/svg);.note{color:red}#note-0{width:41px}#note-1{width:42px}#note-2{width:43px}#note-3{width:44px}#note-4{width:45px}#note-5{width:46px}</style></head><body><main><img src="photo.png" alt="Photo" id="note-' . $i . '" class="note"></main></body></html>');
    }
    $artifact = array('entrypoint' => 'page-0.html', 'files' => $files);
    $compiler = new ArtifactCompiler();
    $shared = $compiler->prepareShared($artifact);
    $pages = $compiler->preparePages($artifact, $shared);
    $receipts = $compiler->compilePreparedPages($shared, $pages);
    $result = $compiler->compose($shared, array_values($receipts))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $css = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')));
    $bootstrap = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
    $presentation = $registry($bootstrap, 'blocks_engine_presentation_styles');
    $replay = $registry($bootstrap, 'blocks_engine_presentation_replay');
    $scopes = $registry($bootstrap, 'blocks_engine_presentation_scopes');
    echo $count . ' routes: ' . count($plan['assets']) . ' assets, ' . strlen(json_encode($plan, JSON_THROW_ON_ERROR)) . ' plan bytes, ' . strlen($bootstrap) . ' bootstrap bytes, ' . substr_count($bootstrap, 'wp_enqueue_style(') . ' enqueue lines, ' . substr_count($bootstrap, "'target_path' =>") . ' presentation files, ' . count($replay) . " replay entries\n";
    $work = new ReflectionProperty(ArtifactCompiler::class, 'authorStylesheetProjectionCount');
    if (1 !== $work->getValue($compiler)) throw new RuntimeException('Shared stylesheet was projected more than once.');
    $inline = array_filter($css, static fn(array $asset): bool => str_starts_with($asset['source_path'], 'inline/'));
    if ($count !== count($inline)) throw new RuntimeException('Expected one shared inline asset and one delta per non-primary owner.');
    $deltas = array_filter($inline, static fn(array $asset): bool => isset($asset['stylesheet_parent']));
    if ($count - 1 !== count($deltas)) throw new RuntimeException('Fixture must force per-owner page deltas.');
    foreach ($deltas as $delta) {
        $owner = $delta['inline_references'][0]['source_path'] ?? '';
        if ('' === $owner || 1 !== count($delta['inline_references']) || 1 !== count($delta['scopes']) || 1 !== count($delta['stylesheet_instances'])) throw new RuntimeException('A page delta must retain exactly its owner occurrence, scope and instance.');
        if ($owner !== $delta['scopes'][0]['source_path'] || $owner !== $delta['stylesheet_instances'][0]['source_path']) throw new RuntimeException('Page delta scope and instance must match its owner.');
        if (1 !== count(array_filter($presentation, static fn(array $style): bool => $style['target_path'] === $delta['target_path']))) throw new RuntimeException('A page delta must have one presentation entry.');
    }
    $parents = array_values(array_filter($inline, static fn(array $asset): bool => !isset($asset['stylesheet_parent'])));
    if (1 !== count($parents) || $count !== count($parents[0]['inline_references']) || $count !== count($parents[0]['stylesheet_instances'])) throw new RuntimeException('The shared parent must retain all route occurrences.');
    if (count($presentation) !== count(array_unique(array_column($presentation, 'target_path')))) throw new RuntimeException('Presentation files must be stored once.');
    $actual = array();
    foreach ($replay as $entry) {
        $file = $presentation[$entry[0]];
        $actual[] = array($file['target_path'], $scopes[$entry[1]], $entry[2], $entry[3], $entry[4], $entry[5]);
    }
    // Compare occurrence order, handles, media, fallback and route ownership
    // against the enqueue plan, not just registry sizes.
    $expected = array();
    $enqueues = (new ReflectionMethod(ThemeBootstrap::class, 'stylesheetEnqueues'))->invoke(null, $plan['assets'], $plan['pages']);
    foreach ($enqueues as $enqueue) {
        $asset = $enqueue['asset'];
        foreach ($enqueue['scopes'] ?? array($enqueue['scope']) as $scope) $expected[] = array($asset['target_path'], $scope, $enqueue['handle'], $asset['source_media'] ?? $enqueue['media'], !empty($enqueue['fallback']), array());
    }
    if ($expected !== $actual) throw new RuntimeException('Presentation replay changed per-route enqueue behavior.');
    foreach ($deltas as $delta) {
        $occurrences = array_values(array_filter($actual, static fn(array $entry): bool => $entry[0] === $delta['target_path']));
        if (1 !== count($occurrences) || $delta['scopes'][0]['source_path'] !== ($occurrences[0][1]['source_path'] ?? null)) throw new RuntimeException('A page delta must replay only on its owner route, never once per route.');
    }
    $bootstrapCounts[$count] = array('enqueues' => substr_count($bootstrap, 'wp_enqueue_style('), 'files' => count($presentation), 'replay' => count($replay), 'scopes' => count($scopes), 'bytes' => strlen($bootstrap));
    $reversed = $compiler->compose($shared, array_reverse(array_values($receipts)))->toArray();
    if ($plan !== $reversed['source_reports']['wordpress_site_plan']) throw new RuntimeException('Partition order changed output.');
    $paths = array_column($css, 'source_path');
    if (count($paths) !== count(array_unique($paths))) throw new RuntimeException('Duplicate stylesheet output paths.');
}
// Fixed overhead plus two frontend enqueue lines/files and three replay
// occurrences per route. The shared parent has N occurrences, but each of
// the N-1 deltas has only its owner's occurrence, never N occurrences.
foreach (array(3, 6) as $count) {
    $expected = array('enqueues' => 4, 'files' => 2 + 2 * $count, 'replay' => 1 + 3 * $count, 'scopes' => 1 + $count);
    if ($expected !== array_diff_key($bootstrapCounts[$count], array('bytes' => true))) throw new RuntimeException('Bootstrap stylesheet delivery must scale linearly from three to six routes.');
}
if ($bootstrapCounts[6]['bytes'] > 2 * $bootstrapCounts[3]['bytes']) throw new RuntimeException('Bootstrap bytes must grow at most linearly with routes.');
