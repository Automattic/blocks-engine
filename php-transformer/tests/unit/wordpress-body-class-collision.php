<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext;

$count = 0;
$assert = static function (bool $value, string $message) use (&$count): void {
    ++$count;
    if (!$value) throw new RuntimeException($message);
};
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><head><style>.page{padding:7px;color:red}body.page{color:blue}.wp-block-group .leaf{margin-left:11px}</style></head><body class="page wp-block-group"><main class="page"><p class="leaf">Source</p></main></body></html>')))->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
$page = SourceClassIdentity::marker('page');
$group = SourceClassIdentity::marker('wp-block-group');
$assert(str_contains($css, '.' . $page), 'Source page selectors bind to provenance rather than Core page state.');
$assert(str_contains($css, '.' . $group), 'Generated block-name ancestry also keeps source ownership.');
$root = SourceClassIdentity::projectRoot(array('class' => 'page wp-block-group scope', 'data-mode' => 'wide'));
$assert(str_contains($root['class'], $page) && str_contains($root['class'], $group), 'The true root carries the same source markers as actual element subjects.');
$assert(str_contains($root['class'], 'page wp-block-group scope'), 'Genuine source classes remain available to authored runtime code.');
$assert('wide' === $root['data-mode'], 'Class provenance does not alter route attributes.');
$bootstrap = DocumentRootContext::bootstrap(array(array('reconciliation_identity' => 'neutral', 'entrypoint' => true, 'document_metadata' => array('body_attributes' => array('class' => 'page')))));
$assert(!str_contains($bootstrap, 'array_diff( $classes'), 'Root context keeps Core classes intact.');
$assert('' === SourceClassIdentity::marker('neutral-scope'), 'Ordinary source names require no alias.');
// Body classes can be authoritatively cleared when rendering a route. Keep
// negative class gates even when they do not match the captured body.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><head><style>@media(min-width:1100px){body:not(.responsive) #content-frame{min-width:1600px}}</style></head><body class="responsive"><main id="content-frame"><p>Source</p></main></body></html>')))->toArray();
$plan = (new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan())->fromCompilerResult($result);
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind'] && 'editor' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));
$assert(str_contains($css, ':not(.responsive)') && str_contains($css, 'min-width:1600px'), 'Negative body-class gates survive compilation for authoritative route contexts.');
$assert(str_contains($css, 'min-width:1100px'), 'The body-class gate retains its viewport condition.');
// A projected selector binds only when its element carries the marker, for
// marked classes of any length and in any position of a long class list.
$long = 'items-template--' . str_repeat('9', 14) . '__section_collection_eJUUBE' . str_repeat('x', 30);
$late = 'card-template-a';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><head><style>.' . $long . '{display:grid;grid-template-columns:1fr 1fr 1fr 1fr}.' . $late . '{padding:9px}</style></head><body><main><div class="' . $long . '"><p>One</p><p>Two</p></div><p class="a b c d e f g h ' . $late . '">Late</p></main></body></html>')))->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
foreach (array($long, $late) as $class) {
    $marker = SourceClassIdentity::marker($class);
    $assert(str_contains($css, '.' . $marker), "The selector for {$class} is projected to its marker.");
    $assert(1 === preg_match('/class="[^"]*\b' . preg_quote($marker, '/') . '\b/', $result['serialized_blocks']), "The element carrying {$class} also carries its marker.");
}
echo "WordPress body-class collision tests: {$count} passed\n";
