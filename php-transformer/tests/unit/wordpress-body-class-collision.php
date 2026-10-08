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
echo "WordPress body-class collision tests: {$count} passed\n";
