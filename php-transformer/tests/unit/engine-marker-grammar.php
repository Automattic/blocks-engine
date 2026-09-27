<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

$passes = 0;
$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$passes, &$failures): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}" . ('' !== $detail ? " - {$detail}" : '') . "\n");
};

// Every kind the allocator hands out is document-scoped: two routes compiling
// the same chrome get different seeds. Shell identity must ignore all of them,
// or identical chrome compiled in separate documents (staged compilation)
// never clusters into one shared template part.
$kinds = array_merge(EngineMarker::DOCUMENT_KINDS, array('source-div', 'source-li'));
foreach ( $kinds as $kind ) {
    $chrome = static fn (string $seed): string => '<!-- wp:group {"tagName":"header","className":"site-header"} --><header class="wp-block-group site-header">'
        . '<!-- wp:paragraph {"className":"brand blocks-engine-' . $kind . '-' . $seed . '-7"} --><p class="brand blocks-engine-' . $kind . '-' . $seed . '-7">Acme</p><!-- /wp:paragraph -->'
        . '</header><!-- /wp:group -->';
    $first = $chrome('0123456789ab');
    $second = $chrome('ba9876543210');
    $assert(EngineMarker::matchesAny($first), "grammar recognizes a document-scoped {$kind} marker");
    $assert(
        ShellExtraction::identityMarkup($first) === ShellExtraction::identityMarkup($second),
        "shell identity ignores the document seed of a {$kind} marker",
        ShellExtraction::identityMarkup($first)
    );
}

// Authored and engine utility classes are not document-scoped markers.
foreach ( array('site-header', 'blocks-engine-list-navigation', 'blocks-engine-css-owned-layout', 'blocks-engine-specificity-class-site-0') as $class ) {
    $assert(! EngineMarker::matchesAny($class), "grammar leaves {$class} alone");
}

// The allocator only hands out kinds the grammar declares, so a new marker kind
// cannot silently fall outside every consumer's normalization.
$analysis = new ReflectionClass(AuthorStyleAnalysis::class);
$instance = $analysis->newInstanceWithoutConstructor();
foreach ( array('markerSeed' => '0123456789ab', 'markerCounter' => 0, 'markerCollisionTexts' => array('', '')) as $property => $value ) {
    $reflected = $analysis->getProperty($property);
    $reflected->setValue($instance, $value);
}
$assert(EngineMarker::matchesAny($instance->allocateMarker('semantic')), 'allocated markers match the grammar');
$threw = false;
try {
    $instance->allocateMarker('undeclared-kind');
} catch ( InvalidArgumentException ) {
    $threw = true;
}
$assert($threw, 'the allocator rejects a kind the grammar does not declare');

if ( 0 < $failures ) {
    fwrite(STDERR, "engine marker grammar FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "engine marker grammar passed: {$passes} assertions\n";
