<?php
/** Equivalent variant searches and candidate evidence are memoized per compilation. */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressor;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$html = file_get_contents(dirname(__DIR__) . '/fixtures/navigation-variant-memo.html');
$expected = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/navigation-variant-memo.expected.json'), true, 512, JSON_THROW_ON_ERROR);
$compilation = new HtmlCompilation();
foreach (array(1, 2) as $compile) {
    $result = $compilation->transform($html)->toArray();
    // Recorded on trunk: compare every block attribute, child and serialized byte.
    $assert($expected === array('blocks' => $result['blocks'], 'serialized_blocks' => $result['serialized_blocks']), 'Reduced page output stays identical to trunk.');
    $suppressor = (new ReflectionProperty(HtmlCompilation::class, 'navigationToggleSuppressor'))->getValue($compilation);
    $assert($suppressor instanceof NavigationToggleSuppressor, 'Compilation owns the suppressor.');
    $assert(($suppressor->navigationVariantEvaluations ?? 0) > 0, 'Compilation evaluates navigation variants.');

    // Keep source nodes alive and exercise both callers on each navigation,
    // including null results (the two device documents are separate scopes).
    $source = new DOMDocument();
    $source->loadHTML($html, LIBXML_NOERROR);
    $suppressor->forgetCoreNavigationRecognitions();
    $before = $suppressor->navigationVariantEvaluations;
    foreach ($source->getElementsByTagName('nav') as $navigation) {
        for ($repeat = 0; $repeat < 3; ++$repeat) {
            $suppressor->expandedMobileNavigation($navigation);
            $suppressor->hasEquivalentNavigationVariant($navigation);
        }
    }
    $assert(2 === $suppressor->navigationVariantEvaluations - $before, 'Both callers evaluate each navigation only once, including misses.');

    foreach (array('hasMobileNavigationSignal' => 'mobileNavigationSignalEvaluations', 'hasMenuVisibilityEvidence' => 'menuVisibilityEvidenceEvaluations') as $method => $counter) {
        $probe = new ReflectionMethod(NavigationToggleSuppressor::class, $method);
        $suppressor->forgetCoreNavigationRecognitions();
        $before = $suppressor->$counter;
        foreach ($source->getElementsByTagName('*') as $candidate) {
            $first = $probe->invoke($suppressor, $candidate);
            $assert($first === $probe->invoke($suppressor, $candidate), 'Candidate evidence remains identical on repeated reads.');
        }
        $assert($source->getElementsByTagName('*')->length === $suppressor->$counter - $before, 'Candidate evidence is evaluated once per element, including false results.');
    }

    // Change document scopes and verify that source-mutation invalidation
    // causes a fresh search rather than reusing the old result.
    foreach ($source->getElementsByTagName('div') as $wrapper) {
        if ($wrapper->hasAttribute('data-blocks-engine-capture-document-scope')) {
            $wrapper->removeAttribute('class');
            $wrapper->removeAttribute('data-blocks-engine-capture-document-scope');
        }
    }
    $suppressor->forgetCoreNavigationRecognitions();
    $before = $suppressor->navigationVariantEvaluations;
    $navigation = $source->getElementsByTagName('nav')->item(0);
    $variant = $suppressor->hasEquivalentNavigationVariant($navigation);
    $assert($variant === $suppressor->hasEquivalentNavigationVariant($navigation), 'Variant results remain stable after source mutation.');
    $assert(1 === $suppressor->navigationVariantEvaluations - $before, 'Source mutation invalidation clears variant results.');
}

echo "navigation-variant-memo: {$assertions} assertions passed\n";
