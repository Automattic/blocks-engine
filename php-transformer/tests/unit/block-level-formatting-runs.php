<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Every inline tag RichText may carry lowers the same way when a page places
// it directly in a block container: `<u>`, `<s>`, `<del>` and `<ins>` become a
// paragraph that keeps the tag and its text, exactly like `<strong>`.

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$compile = static function (string $body): array {
    $html = '<!doctype html><html><head><title>Legal</title></head><body><main><div class="legal"><h2>Publisher</h2>'
        . $body . '<p>Closing text.</p></div></main></body></html>';
    $plan = ( new ArtifactCompiler() )->compile(array( 'files' => array( 'index.html' => $html ) ))->toArray()['source_reports']['wordpress_site_plan'];
    $markup = '';
    foreach ( $plan['pages'] ?? array() as $page ) {
        $markup .= (string) ( $page['canonical_block_markup'] ?? $page['block_markup'] ?? '' );
    }

    return array( $markup, count($plan['quality']['fallbacks'] ?? array()) );
};

foreach ( array( 'u', 's', 'del', 'ins' ) as $tag ) {
    // A label run followed by its value, as legal pages lay out field lists.
    [$markup, $fallbacks] = $compile("<{$tag}><strong>Address</strong></{$tag}> : 1 Main Street<br><{$tag}>Email</{$tag}> : hello@example.test");
    $assert(0 === $fallbacks, "<{$tag}> run in a block container lowers without a fallback");
    $assert(str_contains($markup, "<{$tag}><strong>Address</strong></{$tag}>"), "<{$tag}> run keeps its tag, nested formatting and text");
    $assert(str_contains($markup, "<{$tag}>Email</{$tag}>"), "a second <{$tag}> run keeps its text");
    $assert(str_contains($markup, '1 Main Street') && str_contains($markup, 'hello@example.test'), "text beside the <{$tag}> runs is kept");
    $assert(1 === preg_match('/<!-- wp:paragraph[^>]*-->\s*<p[^>]*>[^<]*<' . $tag . '><strong>Address/', $markup), "<{$tag}> run lowers into a paragraph");
}

// <ins> and <del> may also wrap flow content; the wrapped paragraphs survive.
foreach ( array( 'ins', 'del' ) as $tag ) {
    [$markup, $fallbacks] = $compile("<{$tag}><p>First edit.</p><p>Second edit.</p></{$tag}>");
    $assert(0 === $fallbacks, "<{$tag}> wrapping paragraphs lowers without a fallback");
    $assert(str_contains($markup, 'First edit.') && str_contains($markup, 'Second edit.'), "<{$tag}> wrapping paragraphs keeps both paragraphs");
}

echo "block-level formatting runs: ok\n";
