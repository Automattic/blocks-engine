<?php
declare(strict_types=1);

/**
 * A non-entrypoint page that fails with a representability (non-integrity)
 * error is dropped and named in the plan; the rest of the site compiles. The
 * entrypoint, integrity errors and identity failures keep failing closed, and
 * whole and staged compilation agree.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentIdentityException;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$throws = static function (callable $run, string $class): bool {
    try {
        $run();
    } catch ( Throwable $error ) {
        return $error instanceof $class;
    }
    return false;
};
$failing = static fn (string $path, Throwable $error): Closure => static function (string $sourcePath, string $scope) use ($path, $error): void {
    if ( $sourcePath === $path && 'artifact-shared-shell' !== $scope ) {
        throw $error;
    }
};
$omittedOf = static fn (array $plan): array => array_values(array_filter($plan['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'page_compile_omitted' === ($diagnostic['code'] ?? null)));
$stagedCompile = static function (ArtifactCompiler $compiler, array $artifact): array {
    $shared = $compiler->prepareShared($artifact);
    $receipts = $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared));
    return array($compiler->compose($shared, $receipts)->toArray(), $receipts, $shared);
};

$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><title>Home</title></head><body><main><h1>Home</h1><p><a href="about.html">About</a> <a href="pricing.html">Pricing</a></p></main></body></html>',
        'about.html' => '<!doctype html><html><head><title>About</title></head><body><main><h1>About</h1><p><a href="index.html">Home</a> <a href="pricing.html">Pricing</a></p></main></body></html>',
        'pricing.html' => '<!doctype html><html><head><title>Pricing</title></head><body><main><h1>Pricing</h1><p><a href="index.html">Home</a></p></main></body></html>',
    ),
);
$withSource = $artifact + array('provenance' => array('source_url' => 'https://example.test/site/'));
$representability = new InvalidArgumentException('Form layout graph exceeds its node limit.');

// The control: no failure, no omission.
$whole = (new ArtifactCompiler())->compile($artifact)->toArray();
$assert(array() === $omittedOf($whole['source_reports']['wordpress_site_plan']) && 3 === count($whole['source_reports']['wordpress_site_plan']['pages']), 'Without a failure all three pages compile and none is omitted.');

// 1. One non-entry page fails: success_with_warnings with exactly that page omitted.
$result = (new ArtifactCompiler(beforeDocumentTransform: $failing('pricing.html', $representability)))->compile($withSource)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan) && 'success_with_warnings' === $result['status'] && 'success_with_warnings' === $plan['quality']['status'], 'A page-scoped representability failure compiles to success_with_warnings: ' . json_encode($result['status']));
$planned = array_column($plan['pages'], 'source_path');
sort($planned);
$assert(array('about.html', 'index.html') === $planned, 'Only the two healthy pages stay in the plan: ' . json_encode($planned));
$omitted = $omittedOf($plan);
$assert(1 === count($omitted), 'The plan names exactly one omitted page.');
$row = $omitted[0];
$assert('warning' === $row['severity'] && 'page' === $row['unit'] && 'pricing.html' === $row['source_path'] && 'page_compile_representability_limit' === $row['reason_code'] && 'Form layout graph exceeds its node limit.' === $row['message'], 'The omitted page record carries source_path, reason_code and message: ' . json_encode($row));
$assert(array() === array_filter($result['diagnostics'], static fn (array $diagnostic): bool => 'error' === ($diagnostic['severity'] ?? '')), 'The omission adds no error diagnostic.');
$assert(!isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'The omission is not a plan failure.');

// 2. Links to the omitted page are handled like any other unresolved local link.
$markup = array_column($plan['pages'], 'canonical_block_markup', 'source_path');
$assert(!str_contains($markup['index.html'], 'href="/pricing"') && !str_contains($markup['about.html'], 'href="/pricing"'), 'No page keeps a local route to the omitted page.');
$assert(str_contains($markup['index.html'], 'href="https://example.test/site/pricing.html"'), 'A link to the omitted page resolves against the recorded source site.');
$links = array_values(array_filter($plan['diagnostics'], static fn (array $diagnostic): bool => 'wordpress_site_plan_unresolved_navigation_link' === ($diagnostic['code'] ?? null) && 'pricing.html' === ($diagnostic['value'] ?? null)));
$assert(2 === count($links) && 'warning' === $links[0]['severity'], 'Each page linking to the omitted page records an unresolved-link finding: ' . json_encode($links));
$assert(array() === array_filter($plan['routes'] ?? array(), static fn (array $route): bool => 'pricing.html' === ($route['source_path'] ?? null)), 'The omitted page owns no route.');
WordPressSitePlan::assertValid($plan);

// 3. Staged compilation records the same omission, in the receipt and in the plan.
// (Staged transport does not carry the source URL, so compare like with like.)
$plan = (new ArtifactCompiler(beforeDocumentTransform: $failing('pricing.html', $representability)))->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'];
[$staged, $receipts, $shared] = $stagedCompile(new ArtifactCompiler(beforeDocumentTransform: $failing('pricing.html', $representability)), $artifact);
$assert('success_with_warnings' === $staged['status'], 'Staged compilation of the same site is success_with_warnings: ' . json_encode($staged['status']));
$assert($plan === $staged['source_reports']['wordpress_site_plan'], 'Whole and staged compilation produce the same plan with the same omitted set.');
$receiptOmitted = array();
foreach ( $receipts as $receipt ) {
    foreach ( $receipt['terminal_reduction']['omitted_pages'] ?? array() as $page ) {
        $receiptOmitted[] = $page;
        $assert(array() === $receipt['compiled_documents'] && array() === $receipt['owned_document_paths'], 'A receipt that omitted its page owns no compiled document.');
    }
}
$assert(array(array('source_path' => 'pricing.html', 'reason_code' => 'page_compile_representability_limit', 'message' => 'Form layout graph exceeds its node limit.')) === $receiptOmitted, 'Exactly one receipt names the omitted page: ' . json_encode($receiptOmitted));
$assert(3 === count($receipts), 'Every page keeps one receipt, so a consumer still holds one per frozen page.');
// Without a recorded source URL the links to the omitted page are neutralized, still as findings.
$stagedPlan = $staged['source_reports']['wordpress_site_plan'];
$stagedMarkup = array_column($stagedPlan['pages'], 'canonical_block_markup', 'source_path');
$neutralized = array_values(array_filter($stagedPlan['diagnostics'], static fn (array $diagnostic): bool => 'wordpress_site_plan_unresolved_navigation_link' === ($diagnostic['code'] ?? null) && 'pricing.html' === ($diagnostic['value'] ?? null) && 'neutralized' === ($diagnostic['resolution'] ?? null)));
$assert(2 === count($neutralized) && !str_contains($stagedMarkup['index.html'], 'pricing') && !str_contains($stagedMarkup['about.html'], 'pricing'), 'Without a source URL every link to the omitted page is neutralized and reported: ' . json_encode($neutralized));

// 3b. The importer's shape: one fresh worker per page, receipts serialized
// between processes, composition by a compiler that never saw a failure.
$hook = $failing('pricing.html', $representability);
$workerShared = (new ArtifactCompiler())->prepareShared($artifact);
$workerReceipts = array();
foreach ( (new ArtifactCompiler())->preparePages($artifact, $workerShared) as $pageId => $pagePlan ) {
    $batch = (new ArtifactCompiler(beforeDocumentTransform: $hook))->compilePreparedPages(json_decode(json_encode($workerShared), true), array(json_decode(json_encode($pagePlan), true)));
    $assert(array($pageId) === array_keys($batch), "A worker returns exactly the receipt it was asked for ({$pageId}).");
    $workerReceipts[$pageId] = json_decode(json_encode($batch[$pageId]), true);
}
$workerResult = (new ArtifactCompiler())->compose($workerShared, array_values($workerReceipts));
$assert('success_with_warnings' === $workerResult->status && $plan === $workerResult->toArray()['source_reports']['wordpress_site_plan'], 'Per-page workers with serialized receipts compose the same plan as whole compilation.');
$compact = $workerResult->toCompactWordPressSitePlanView();
$assert(array(array('pricing.html', 'page_compile_representability_limit')) === array_map(static fn (array $row): array => array($row['source_path'], $row['reason_code']), $omittedOf($compact['wordpress_site_plan'])), 'The compact site plan view an importer persists carries the omitted set.');

// 4. A receipt cannot omit the entrypoint, a page another receipt compiled,
// or a page another receipt already omitted.
$compiler = new ArtifactCompiler();
// Re-seal each forged receipt so only the omission itself is under test.
$digestInput = new ReflectionMethod(ArtifactCompiler::class, 'pagePlanDigestInput');
$digest = new ReflectionMethod(ArtifactCompiler::class, 'planDigest');
$forge = static function (array $receipts, string $pageId, string $omittedPath) use ($compiler, $digestInput, $digest): array {
    $receipts[$pageId]['terminal_reduction']['omitted_pages'] = array(array('source_path' => $omittedPath, 'reason_code' => 'x', 'message' => 'x'));
    $receipts[$pageId]['digest'] = $digest->invoke($compiler, $digestInput->invoke($compiler, $receipts[$pageId]));
    return $receipts;
};
foreach ( array(
    'the entrypoint' => array($forge($receipts, 'index.html', 'index.html'), 'cannot omit the entrypoint'),
    'a page another receipt compiled' => array($forge($receipts, 'index.html', 'about.html'), 'undeclared or still compiled'),
    'a page that is not in the artifact' => array($forge($receipts, 'index.html', 'missing.html'), 'undeclared or still compiled'),
    'a page already omitted' => array($forge($receipts, 'index.html', 'pricing.html'), 'more than one omission'),
) as $case => [$forged, $reason] ) {
    try {
        $compiler->compose($shared, $forged);
        $assert(false, "A forged receipt that omits {$case} is rejected.");
    } catch ( InvalidArgumentException $error ) {
        $assert(str_contains($error->getMessage(), $reason), "A forged receipt that omits {$case} is rejected for that reason: " . $error->getMessage());
    }
}

// 5. The same failure on the entrypoint still fails, whole and staged.
$entryFailure = $failing('index.html', $representability);
$assert($throws(static fn () => (new ArtifactCompiler(beforeDocumentTransform: $entryFailure))->compile($artifact), InvalidArgumentException::class), 'A representability failure on the entrypoint fails the whole compile.');
$assert($throws(static fn () => $stagedCompile(new ArtifactCompiler(beforeDocumentTransform: $entryFailure), $artifact), InvalidArgumentException::class), 'A representability failure on the entrypoint fails the staged compile.');

// 6. Integrity errors on a non-entry page still fail.
$integrity = array(
    'logic' => new LogicException('HTML compilation must provide BlockCompilationOutput.'),
    'identity' => new DocumentIdentityException(array()),
    'runtime' => new RuntimeException('Bundled core block metadata snapshot is unavailable.'),
);
foreach ( $integrity as $name => $error ) {
    $hook = $failing('pricing.html', $error);
    $assert($throws(static fn () => (new ArtifactCompiler(beforeDocumentTransform: $hook))->compile($artifact), get_class($error)), "A {$name} error on a non-entry page still fails the whole compile.");
    $assert($throws(static fn () => $stagedCompile(new ArtifactCompiler(beforeDocumentTransform: $hook), $artifact), get_class($error)), "A {$name} error on a non-entry page still fails the staged compile.");
}

echo "omitted-page-receipt contract passed\n";
