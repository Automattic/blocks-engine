<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ValidationException;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$page = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Comment import</title>
<style>/* This block can be replaced with an @import / enqueue later. */
:root{--accent:#c00}h1{color:var(--accent)}</style>
</head><body><main><h1>Hello</h1><p>Body text.</p></main></body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'A CSS comment mentioning @import does not prevent site plan production.');
WordPressSitePlan::assertValid($plan);

$missing = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Missing import</h1><style>@import url("/missing.css");</style></main>',
)))->toArray();
$rejected = false;
try {
    (new WordPressSitePlan(true))->fromResult($missing);
} catch (ValidationException $exception) {
    $rejected = 'missing_stylesheet_import' === ($exception->diagnostic()['reason'] ?? null);
}
$assert($rejected, 'A real unresolved @import remains rejected.');

echo "css-comment-import-reference contract passed\n";
