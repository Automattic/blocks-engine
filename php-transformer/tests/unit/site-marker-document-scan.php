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
    array( 'kind' => 'html', 'path' => 'a.html', 'content' => '<p class="be-t-0">a</p>' ),
    array( 'kind' => 'html', 'path' => 'b.html', 'content' => '<p class="be-c-0 be-c-1">b</p>' ),
    array( 'kind' => 'css', 'path' => 'x.css', 'content' => '.x{}' ),
);
$stylesheets = array(
    '.plain{color:red}',
    '.be-t-1{color:red}',
    '.be-c-2,.be-c-3{}',
    '.be-i-0{}',
);

// One instance projects every stylesheet against the same documents, as the
// compiler does; each must get exactly the marker the full search allocates.
$compat = new WordPressCompatCss();
foreach ( $stylesheets as $css ) {
    $compat->projectNavigationStylesheet($css, $files);
    $assert($expected($css, $files) === $shims($compat), 'site markers for ' . $css);
}
$assert(array( 'be-t-1', 'be-c-2', 'be-i-1' ) === $shims($compat), 'a stylesheet collision moves to the next free marker');

// A different document set is scanned again.
$changed = $files;
$changed[0]['content'] = '<p class="be-t-0 be-t-1">a</p>';
$compat->projectNavigationStylesheet('.plain{}', $changed);
$assert($expected('.plain{}', $changed) === $shims($compat), 'site markers follow changed documents');
$assert('be-t-2' === $shims($compat)[0], 'changed documents move the marker');

echo "Site marker document scan tests passed ({$count} assertions)\n";
