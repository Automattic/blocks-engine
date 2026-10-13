<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDependencyParityReport;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressSitePlanComposer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

/**
 * A runtime dependency miss costs one block its script behavior, not the site.
 * The emitted block keeps its static markup, the finding is a block-level
 * warning, and the site plan is kept with `success_with_warnings`.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$misses = static fn (array $diagnostics): array => array_values(array_filter($diagnostics, static fn (mixed $row): bool => is_array($row) && 'runtime_dependency_target_missing' === ($row['code'] ?? null)));

$cases = array(
    'script selector' => array(
        'selector' => '[data-counter]',
        'files' => array(
            'index.html' => '<main><style data-counter="clock">.clock{color:red}</style><p>Editable</p><script src="js/clock.js"></script></main>',
            'js/clock.js' => 'document.querySelector("[data-counter]").textContent = "2";',
        ),
    ),
);

foreach ($cases as $name => $case) {
    $artifact = array('entrypoint' => 'index.html', 'files' => $case['files']);
    $result = (new ArtifactCompiler())->compile($artifact)->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? null;
    $found = $misses($result['diagnostics']);
    $assert(1 === count($found) && $case['selector'] === ($found[0]['selector'] ?? null), "{$name}: the miss is reported once for its selector: " . json_encode($found));
    $assert('warning' === $found[0]['severity'] && 'block' === $found[0]['unit'] && 'index.html' === ($found[0]['source_path'] ?? null), "{$name}: the miss is a block-level warning: " . json_encode($found[0]));
    $assert(array() === array_filter($result['diagnostics'], static fn (array $row): bool => 'error' === ($row['severity'] ?? null)), "{$name}: a dependency miss adds no error: " . json_encode(array_column(array_filter($result['diagnostics'], static fn (array $row): bool => 'error' === ($row['severity'] ?? null)), 'code')));
    $assert('success_with_warnings' === $result['status'] && is_array($plan), "{$name}: the site plan is kept.");
    $assert('success_with_warnings' === $plan['quality']['status'] && true === $plan['quality']['pass'], "{$name}: the plan reports its own warning status.");
    $assert(1 === count($misses($plan['diagnostics'])) && in_array('runtime_dependency_target_missing', $plan['reporting']['diagnostic_codes'], true), "{$name}: the plan carries the finding.");
    $assert($plan['plan_identity'] === WordPressSitePlan::planIdentity($plan), "{$name}: the plan identity covers the finding.");
    WordPressSitePlan::assertValid($plan);
    $assert(str_contains($plan['pages'][0]['canonical_block_markup'], 'Editable'), "{$name}: the page keeps its static blocks.");

    $compiler = new ArtifactCompiler();
    $shared = $compiler->prepareShared($artifact);
    $staged = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
    $assert($plan === ($staged['source_reports']['wordpress_site_plan'] ?? null), "{$name}: whole and staged compilation keep the same plan.");
}

// An emitted aria-controls whose panel is gone from its source document scope
// is the same block-level miss, through the same composer path.
$envelope = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><p>Editable</p></main>')));
$idref = static fn (?array $plan): array => (new RuntimeDependencyParityReport())->fromArtifact(array(), '<div id="control" role="button" aria-controls="target"></div><div id="target"><p>Panel</p></div>', '<div id="control" role="button" aria-controls="target"></div>', 'index.html', wordpressSitePlan: $plan);
$composed = (new WordPressSitePlanComposer())->compose($envelope, array(), null, $idref)->toArray();
$found = $misses($composed['diagnostics']);
$plan = $composed['source_reports']['wordpress_site_plan'] ?? null;
$assert(1 === count($found) && '#target' === $found[0]['selector'] && 'block' === $found[0]['unit'] && 'warning' === $found[0]['severity'], 'aria-controls: the missing panel is a block-level warning: ' . json_encode($found));
$assert('success_with_warnings' === $composed['status'] && is_array($plan) && 'success_with_warnings' === $plan['quality']['status'] && $plan['plan_identity'] === WordPressSitePlan::planIdentity($plan), 'aria-controls: the site plan is kept with its warning.');
WordPressSitePlan::assertValid($plan);

echo "runtime dependency target miss contract passed\n";
