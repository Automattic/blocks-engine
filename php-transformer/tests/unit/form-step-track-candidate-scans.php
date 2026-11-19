<?php
declare(strict_types=1);

/** Step-track work follows candidate rules, not stylesheet size (#6885). */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormStepTrackDetector;

$assert = static function (bool $ok, string $message): void {
    if ( ! $ok ) {
        throw new RuntimeException($message);
    }
};
$page = file_get_contents(dirname(__DIR__) . '/fixtures/form-step-track-candidate-scans.html');
$noise = '';
for ( $i = 0; $i < 5000; ++$i ) {
    $noise .= '.absent-' . $i . ' { overflow: hidden; --clip: visible; }' . PHP_EOL;
}
$counts = array();
$outputs = array();
foreach ( array( '', $noise ) as $css ) {
    $html = str_replace('/* generated rules */', $css, $page);
    $document = new DOMDocument();
    $document->loadHTML($html);
    $form = $document->getElementsByTagName('form')->item(0);
    $styles = $document->getElementsByTagName('style')->item(0)->textContent;
    $graph = (new FormLayoutGraphBuilder())->build($form, array(), $styles);
    $cache = new CssSelectorMatchCache();
    $steps = (new FormStepTrackDetector($cache))->detect($form, $graph, array(), $styles);
    $assert(2 === count($steps['steps'] ?? array()), 'The reduced page retains its two steps and inherited custom property.');
    $counts[] = $cache->matchExecutions;
    $before = FormStepTrackDetector::cascadeBuilds();
    $result = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ))->toArray();
    $assert(1 === FormStepTrackDetector::cascadeBuilds() - $before, 'Inventory and fallback must share one cascade, including across detector instances.');
    foreach ( $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration ) {
        if ( 'forms' === ($declaration['type'] ?? null) ) {
            // Source hashes and generated carrier ids intentionally track the
            // whole stylesheet; compare the form contract without those ids.
            $output = json_encode($declaration['payload']['entities']);
            $output = preg_replace('/"source_sha256":"[a-f0-9]{64}"/', '"source_sha256":"HASH"', $output);
            $outputs[] = preg_replace('/blocks-engine-control-[a-f0-9]+-/', 'blocks-engine-control-HASH-', $output);
        }
    }
}
$assert($counts[0] > 0 && $counts[0] === $counts[1] && $counts[1] < 100, 'Absent subjects must add no selector evaluations: ' . json_encode($counts));
$assert(2 === count($outputs) && $outputs[0] === $outputs[1], 'Generated absent rules must not change the emitted form entity.');
echo 'form-step-track-candidate-scans: identical form output; selector evaluations ' . json_encode($counts) . PHP_EOL;
