<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Reduced page from #4122: source sheets commonly carry a normalize rule on
// the root element and set the real typeface on the body.
$source = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Root font</title>'
    . '<style>html{font-family:sans-serif;line-height:1.15}body{margin:0;font-family:Georgia,serif;font-size:18px;color:#333}</style></head><body>'
    . '<main><h1>Hello</h1><p>Body text in Georgia.</p></main></body></html>';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) throw new RuntimeException($message);
};
$compact = static fn(string $value): string => preg_replace('/\s+/', '', $value) ?? $value;

$plan = (new WordPressSitePlan())->fromCompilerResult(
    (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray()
);
$writeMap = static function (array $writes): array {
    $map = array();
    foreach ($writes as $write) $map[$write['target_path']] = $write;
    return $map;
};
$theme = json_decode((string) ($writeMap($plan['writes'] ?? array())['theme.json']['payload']['data'] ?? ''), true);
$editorCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter(
        $plan['assets'],
        static fn (array $asset): bool => 'css' === $asset['kind'] && 'frontend' !== ($asset['stylesheet_target'] ?? 'both')
    )
));

// The body level owns canvas typography through the projected theme presets.
$assert('var:preset|font-family|body' === ($theme['styles']['typography']['fontFamily'] ?? null), 'Body-level typography owns the canvas font through the projected theme preset.');
$bodyFamily = '';
foreach ($theme['settings']['typography']['fontFamilies'] ?? array() as $family) {
    if ('body' === ($family['slug'] ?? '')) $bodyFamily = (string) ($family['fontFamily'] ?? '');
}
$assert('Georgia,serif' === $bodyFamily, 'The projected body preset keeps the source typeface.');

// Root-element declarations keep root semantics: the authored root rule rides
// the shared stylesheet into the editor document, and its presentation is
// re-stated onto the canvas through a restatement wholly wrapped in :where(),
// so the canvas inherits it as the document default without the restatement
// ever weighing more than the body-level styles that must outrank it.
$assert(str_contains($editorCss, 'html{font-family:sans-serif;line-height:1.15}'), 'The source root rule keeps its authored form as the editor document root default.');
$restatedSelector = ':where(html .editor-styles-wrapper)';
$assert(str_contains($editorCss, $restatedSelector . '{font-family:sans-serif;line-height:1.15}'), 'Root-element presentation is re-stated onto the canvas so inherited defaults still arrive.');
$parsedRestatement = CssSelectorMatcher::parse($restatedSelector);
$assert($parsedRestatement['supported'] && 0 === CssSelectorMatcher::specificity($parsedRestatement), 'The restated root selector weighs (0,0,0), so no body-level style can lose to it.');
$parsedBodyCarrier = CssSelectorMatcher::parse(':root body.editor-styles-wrapper');
$assert(CssSelectorMatcher::specificity($parsedBodyCarrier) > CssSelectorMatcher::specificity($parsedRestatement), 'Body-level presentation keeps its canvas-scoped carrier and outranks the inherited default.');
$assert(str_contains($editorCss, ':root body.editor-styles-wrapper{'), 'Body-level presentation keeps its canvas-scoped carrier.');

// The canvas resolves the body typeface, exactly as the frontend does. Core
// applies the theme.json body typography to the editor canvas body; a plain
// body rule carries that presentation into the render-free cascade.
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><head><style>' . $editorCss . '</style></head><body class="editor-styles-wrapper"><main><h1>Hello</h1><p>Body text in Georgia.</p></main></body></html>');
$cascade = new StaticCssCascade($document, 'body{font-family:' . $bodyFamily . '}');
$canvasBody = $document->getElementsByTagName('body')->item(0);
$paragraph = $document->getElementsByTagName('p')->item(0);
$assert(null !== $canvasBody && null !== $paragraph, 'The editor canvas fixture resolves both the canvas body and its paragraph.');
$bodyResolved = $cascade->resolve($canvasBody, array('font-family', 'line-height'), array('font-family', 'line-height'));
$assert('Georgia,serif' === $compact((string) ($bodyResolved['font-family'] ?? '')), 'The canvas body keeps the body-level typeface over root-element declarations.');
$paragraphResolved = $cascade->resolve($paragraph, array('font-family', 'line-height'), array('font-family', 'line-height'));
$assert('Georgia,serif' === $compact((string) ($paragraphResolved['font-family'] ?? '')), 'Canvas typography matches the frontend: the paragraph computes the body typeface.');
$assert('1.15' === $compact((string) ($paragraphResolved['line-height'] ?? '')), 'Root declarations still set the inherited default: the paragraph inherits the root line height.');

fwrite(STDOUT, "Root element editor typography parity: {$assertions} assertions passed\n");
