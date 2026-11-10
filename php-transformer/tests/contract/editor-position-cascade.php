<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// A broad source rule positions every section absolutely while a more
// specific source rule restores the flow of the rows. The editor mirror must
// reproduce that cascade for every editor identity: the copies share one
// `:root .editor-styles-wrapper` prefix, and id selectors also reach their
// editor-anchor form, so the specific `relative` copy keeps winning.
// An element matched only by the broad rule keeps its absolute editor copy.
$html = '<!doctype html><html><head><meta charset="utf-8"><title>Editor important</title><style>'
    . '[data-kind]{position:absolute}'
    . '.page{position:relative}'
    . '.page .row{position:relative;display:flex;flex-direction:column;gap:10px;padding:20px}'
    . '</style></head><body><div class="page">'
    . '<div class="row" data-kind="stack"><h1>First heading</h1><p>First paragraph text.</p></div>'
    . '<div class="row" data-kind="stack"><h2>Second heading</h2><p>Second paragraph text.</p></div>'
    . '<div data-kind="x" class="badge">Badge</div>'
    . '</div></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$editorPositionRules = array();
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body) use (&$editorPositionRules): void {
        if (! str_contains($body, 'position:') || ! str_contains($selector, 'editor-styles-wrapper')) return;
        foreach (explode(',', $selector) as $part) {
            $editorPositionRules[trim($part)] = trim($body);
        }
    });
}

$broad = array_filter($editorPositionRules, static fn (string $selector): bool => 1 === preg_match('/\.blocks-engine-attribute-[a-f0-9]+-\d+/', $selector), ARRAY_FILTER_USE_KEY);
$assert(1 === count($broad), 'The broad attribute rule keeps exactly one editor copy.');
foreach ($editorPositionRules as $selector => $body) {
    $assert(! str_contains($body, '!important'), 'Editor position copies must stay unforced so the source cascade decides: ' . $selector . '{' . $body . '}');
}
$absoluteSelectors = array_filter($editorPositionRules, static fn (string $body): bool => 'position:absolute' === $body);
$assert(array() !== $absoluteSelectors, 'An element matched only by the broad rule keeps its editor position:absolute copy.');
$assert(array_keys($absoluteSelectors) === array_keys($broad), 'The broad rule serves the absolute-only copy.');
$relativeSelectors = array_filter($editorPositionRules, static fn (string $body): bool => 'position:relative' === $body);
$assert(2 === count($relativeSelectors), 'Both narrowing relative rules keep editor copies: ' . json_encode($editorPositionRules));
