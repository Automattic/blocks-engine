<?php
declare(strict_types=1);

/**
 * A missing or non-standard include directive, or a document over the include
 * count, is a page-local limit: that page's includes stay unexpanded and the
 * site still compiles, with a page-attributed finding. The entrypoint is the
 * page the site hangs off, so the same breach there still fails. Whole,
 * staged and reference-backed staged compilation must agree.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$include = static fn (string $name): string => '<!--#include virtual="/parts/' . $name . '.html" -->';
$findings = static fn (array $result): array => array_values(array_filter(
    $result['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => str_starts_with((string) ($diagnostic['code'] ?? ''), 'html_include_')
));
$compiler = new ArtifactCompiler();
$stagedResult = static function (array $artifact) use ($compiler): array {
    $shared = $compiler->prepareShared($artifact);
    return $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
};
$referencedResult = static function (array $artifact) use ($compiler): array {
    $referenced = $artifact;
    $referenced['files'] = array();
    $payloads = array();
    foreach ( $artifact['files'] as $path => $content ) {
        $payloads[$path] = $content;
        $referenced['files'][] = array('path' => $path, 'payload_reference' => array('schema' => 'blocks-engine/payload-reference/v1', 'id' => $path, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)));
    }
    $reader = new class($payloads) implements PayloadReader {
        public function __construct(private array $payloads) {}
        public function read(array $reference): string { return $this->payloads[$reference['id']]; }
    };
    $shared = $compiler->prepareShared($referenced, $reader);
    return $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($referenced, $shared, $reader), $reader), $reader)->toArray();
};

$base = array('index.html' => '<main><h1>Home</h1>' . $include('brand') . '</main>', 'parts/brand.html' => '<p>Brand</p>', 'parts/unit.html' => '<i>u</i>');
// Fragment reuse also counts: 42 uses of a fragment that itself includes 99 others.
$wide = array('parts/wide.html' => str_repeat($include('unit'), 99));
foreach (array(
    'directive_ignored: non-standard directive' => array('html_include_directive_ignored', array('about.html' => '<main><h2>About</h2><!--#include virtual="/../secret.html" --></main>')),
    'directive_ignored: single-quoted directive' => array('html_include_directive_ignored', array('about.html' => "<main><h2>About</h2><!--#include virtual='/parts/brand.html' --></main>")),
    'directive_ignored: missing fragment' => array('html_include_directive_ignored', array('about.html' => '<main><h2>About</h2>' . $include('missing') . '</main>')),
    'budget_exceeded: directive count' => array('html_include_budget_exceeded', array('about.html' => '<main><h2>About</h2>' . str_repeat($include('unit'), 4097) . '</main>')),
    'budget_exceeded: fragment reuse' => array('html_include_budget_exceeded', array('about.html' => '<main><h2>About</h2>' . str_repeat($include('wide'), 42) . '</main>') + $wide),
) as $label => list($code, $extra)) {
    $artifact = array('entrypoint' => 'index.html', 'files' => $extra + $base);
    $whole = $compiler->compile($artifact)->toArray();
    $plan = $whole['source_reports']['wordpress_site_plan'] ?? null;
    $assert(is_array($plan) && 'success_with_warnings' === $whole['status'], "{$label}: the site compiles with warnings.");
    WordPressSitePlan::assertValid($plan);
    $pages = array_column($plan['pages'], 'canonical_block_markup', 'source_path');
    $assert(2 === count($pages) && str_contains($pages['index.html'], 'Brand'), "{$label}: the other page still expands its includes.");
    $assert(str_contains($pages['about.html'], 'About') && !str_contains($pages['about.html'], 'Brand'), "{$label}: the offending page keeps its content with includes unexpanded.");
    $found = $findings($whole);
    $assert(1 === count($found) && $code === $found[0]['code'] && 'warning' === $found[0]['severity'] && 'about.html' === $found[0]['context']['source_path'], "{$label}: one page-attributed {$code} warning.");
    $staged = $stagedResult($artifact);
    $assert($staged['status'] === $whole['status'] && $findings($staged) === $found && $staged['source_reports']['wordpress_site_plan'] === $plan, "{$label}: staged compilation matches whole compilation.");
    $referenced = $referencedResult($artifact);
    $assert($referenced['status'] === $whole['status'] && $findings($referenced) === $found && $referenced['source_reports']['wordpress_site_plan'] === $plan, "{$label}: reference-backed staged compilation matches whole compilation.");
}

// The entrypoint is not a page the site can lose.
foreach (array(
    'html_include_count_exceeded' => '<main>' . str_repeat($include('unit'), 4097) . '</main>',
    'html_include_missing_path' => '<main>' . $include('missing') . '</main>',
    'html_include_invalid_directive' => '<main><!--#include file="parts/brand.html" --></main>',
) as $reason => $entry) {
    $artifact = array('entrypoint' => 'index.html', 'files' => array('index.html' => $entry, 'about.html' => '<main>About</main>') + $base);
    foreach (array('whole' => static fn () => $compiler->compile($artifact), 'staged' => static fn () => $stagedResult($artifact)) as $path => $run) {
        try {
            $run();
            $assert(false, "The entrypoint {$reason} unexpectedly compiled ({$path}).");
        } catch ( InvalidArgumentException $error ) {
            $assert(str_contains($error->getMessage(), $reason), "The entrypoint still fails with {$reason} ({$path}).");
        }
    }
}

echo "html-include-page-local-limits contract passed\n";
