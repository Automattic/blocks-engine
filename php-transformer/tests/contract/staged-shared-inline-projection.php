<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Each page envelope re-extracts the same inline sheet. Terminal reduction
// must merge its occurrence records instead of projecting the path N times.
foreach (array(3, 6) as $count) {
    $files = array();
    for ($i = 0; $i < $count; ++$i) {
        $files[] = array('path' => "page-$i.html", 'metadata' => array('compilation' => array('scope' => 'page', 'id' => "page-$i.html")), 'content' => '<html><head><style>@namespace svg url(http://www.w3.org/2000/svg);.note{color:red}#note-0{color:blue}#note-1{color:green}#note-2{color:purple}#note-3{color:orange}#note-4{color:black}#note-5{color:pink}</style></head><body><main><p id="note-' . $i . '" class="note">Hello</p></main></body></html>');
    }
    $artifact = array('entrypoint' => 'page-0.html', 'files' => $files);
    $compiler = new ArtifactCompiler();
    $shared = $compiler->prepareShared($artifact);
    $pages = $compiler->preparePages($artifact, $shared);
    $receipts = $compiler->compilePreparedPages($shared, $pages);
    $result = $compiler->compose($shared, array_values($receipts))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $css = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')));
    echo $count . ' routes: ' . count($plan['assets']) . ' assets, ' . strlen(json_encode($plan, JSON_THROW_ON_ERROR)) . " plan bytes\n";
    $work = new ReflectionProperty(ArtifactCompiler::class, 'authorStylesheetProjectionCount');
    if (1 !== $work->getValue($compiler)) throw new RuntimeException('Shared stylesheet was projected more than once.');
    $inline = array_filter($css, static fn(array $asset): bool => str_starts_with($asset['source_path'], 'inline/'));
    if (1 !== count($inline)) throw new RuntimeException('Expected exactly one shared inline asset.');
    $reversed = $compiler->compose($shared, array_reverse(array_values($receipts)))->toArray();
    if ($plan !== $reversed['source_reports']['wordpress_site_plan']) throw new RuntimeException('Partition order changed output.');
    $paths = array_column($css, 'source_path');
    if (count($paths) !== count(array_unique($paths))) throw new RuntimeException('Duplicate stylesheet output paths.');
}
