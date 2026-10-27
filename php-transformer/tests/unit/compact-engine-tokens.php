<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . "\n"); exit(1); }
};
foreach (array('specificity' => array('', array(0, 0, 1)), 'specificity-class' => array('.', array(0, 1, 0)), 'specificity-id' => array('#', array(1, 0, 0))) as $kind => [$sigil, $weight]) {
    $token = AuthorStyleAnalysis::allocateSiteMarker($kind, array());
    $assert(strlen($token) <= 6 && EngineMarker::isSpecificityShim($token), 'Shims have compact, centrally recognized names.');
    $parsed = CssSelectorMatcher::parse(':not(' . $sigil . $token . ')');
    $assert(array_values(CssSelectorMatcher::specificityCounts($parsed)) === $weight, 'Each shim contributes exactly its original type, class or id weight.');
    $assert($token !== AuthorStyleAnalysis::allocateSiteMarker($kind, array($token)), 'Shims still avoid authored collisions.');
}
$scope = ':where([' . EngineMarker::DEVICE_SCOPE_ATTRIBUTE . '="mobile"])';
$assert(array_values(CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($scope))) === array(0, 0, 0), 'Device gates are weightless.');
$publishedGate = ':where([data-blocks-engine-capture-device-document="mobile"])';
$sample = '@media(min-width:1px){' . $publishedGate . ' p{content:"data-blocks-engine-capture-device-document";color:red}' . $publishedGate . ' p{color:blue}}';
$expected = '@media(min-width:1px){' . $scope . ' p{content:"data-blocks-engine-capture-device-document";color:red}' . $scope . ' p{color:blue}}';
$assert($expected === EngineMarker::compactDeviceScope($sample), 'Scope compaction changes only selectors, preserving declarations and competing rule order.');
$artifact = (static fn (): array => require dirname(__DIR__) . '/fixtures/compact-engine-tokens.php')();
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(str_contains($css, $scope), 'Published capture inputs emit compact device gates.');
foreach (EngineMarker::SPECIFICITY_PREFIXES as $prefix) $assert(str_contains($css, $prefix), 'The neutral catalogue projects every shim kind.');
$assert(!str_contains($css, 'blocks-engine-specificity-') && !str_contains($css, ':where([data-blocks-engine-capture-device-document'), 'No verbose engine gates or shims remain in projected CSS.');
$assert(str_contains($result['serialized_blocks'], EngineMarker::DEVICE_SCOPE_ATTRIBUTE . '="mobile"'), 'Emitted document roots carry the compact gate attribute.');
$assert(str_contains($result['serialized_blocks'], 'data-blocks-engine-capture-device-document="mobile"'), 'Published capture attributes remain available to authored runtime code.');
fwrite(STDOUT, "Compact engine token regression passed.\n");
