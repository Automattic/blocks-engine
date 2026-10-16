<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$shims = static function (WordPressCompatCss $compat): array {
    $property = new ReflectionProperty(WordPressCompatCss::class, 'specificityShims');
    return $property->getValue($compat);
};
$expected = static function (string $css, array $files): array {
    $avoid = array( $css );
    foreach ( $files as $file ) {
        if ( 'html' === ( $file['kind'] ?? '' ) ) {
            $avoid[] = (string) $file['content'];
        }
    }
    return array(
        AuthorStyleAnalysis::allocateSiteMarker('specificity', $avoid),
        AuthorStyleAnalysis::allocateSiteMarker('specificity-class', $avoid),
        AuthorStyleAnalysis::allocateSiteMarker('specificity-id', $avoid),
    );
};

$files = array(
    array( 'kind' => 'html', 'path' => 'a.html', 'content' => '<p class="blocks-engine-specificity-site-0">a</p>' ),
    array( 'kind' => 'html', 'path' => 'b.html', 'content' => '<p class="blocks-engine-specificity-class-site-0 blocks-engine-specificity-class-site-1">b</p>' ),
    array( 'kind' => 'css', 'path' => 'x.css', 'content' => '.x{}' ),
);
$stylesheets = array(
    '.plain{color:red}',
    '.blocks-engine-specificity-site-1{color:red}',
    '.blocks-engine-specificity-class-site-2,.blocks-engine-specificity-class-site-3{}',
    '.blocks-engine-specificity-id-site-0{}',
);

// One instance projects every stylesheet against the same documents, as the
// compiler does; each must get exactly the marker the full search allocates.
$compat = new WordPressCompatCss();
foreach ( $stylesheets as $css ) {
    $compat->projectNavigationStylesheet($css, $files);
    $assert($expected($css, $files) === $shims($compat), 'site markers for ' . $css);
}
$assert(array( 'blocks-engine-specificity-site-1', 'blocks-engine-specificity-class-site-2', 'blocks-engine-specificity-id-site-1' ) === $shims($compat), 'a stylesheet collision moves to the next free marker');

// A different document set is scanned again.
$changed = $files;
$changed[0]['content'] = '<p class="blocks-engine-specificity-site-0 blocks-engine-specificity-site-1">a</p>';
$compat->projectNavigationStylesheet('.plain{}', $changed);
$assert($expected('.plain{}', $changed) === $shims($compat), 'site markers follow changed documents');
$assert('blocks-engine-specificity-site-2' === $shims($compat)[0], 'changed documents move the marker');

echo "Site marker document scan tests passed ({$count} assertions)\n";
