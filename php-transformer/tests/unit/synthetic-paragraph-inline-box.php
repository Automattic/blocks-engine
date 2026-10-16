<?php
declare(strict_types=1);

/**
 * A synthetic paragraph stands for inline source content: a text-only inline
 * element it was retagged from (`<span class="track-dur">`) or loose text. It
 * gets an inline box, not display:contents, so it keeps the source element's
 * own geometry (a flex parent blockifies it into the same item) and the
 * editor can focus it: an element with no box cannot take focus, so its
 * RichText never selects its block. A paragraph that only carries a source
 * anchor stays transparent, and a text-only source div is one lowered
 * paragraph that keeps its own padded block box.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};
$css = static function (array $result): string {
    return implode("\n", array_map(static fn (array $asset): string => (string) ( $asset['content'] ?? '' ), (array) ( $result['assets'] ?? array() )));
};

$synthetic = SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS;
$sourceBox = SourceBlockAttributeProjector::SOURCE_BOX_PARAGRAPH_CLASS;
$inlineRule = ':where(p.' . $synthetic . ':not(.' . $sourceBox . ')){display:inline}';
$anchorRule = ':where(p.' . $synthetic . ':not(.' . $sourceBox . '):has(>a)){display:contents}';

$tracklist = ( new HtmlTransformer() )->transform(
    '<style>.tracklist li{display:flex;align-items:baseline;gap:1.25rem}.track-num{min-width:1.5rem}.track-title{flex:1}</style>'
    . '<ul class="tracklist"><li><span class="track-num">01</span><span class="track-title">Kindling</span><span class="track-dur">3:42</span></li></ul>'
)->toArray();
$tracklistMarkup = (string) ( $tracklist['serialized_blocks'] ?? '' );
$tracklistCss = $css($tracklist);
$assert(str_contains($tracklistMarkup, '<p class="track-title ' . $synthetic . '">Kindling</p>'), 'a text-only flex-item span is retagged as a synthetic paragraph carrying its class', $tracklistMarkup);
$assert(str_contains($tracklistCss, $inlineRule), 'a synthetic paragraph gets an inline box that a flex parent blockifies like the source span');
$assert(! str_contains($tracklistCss, ':where(p.' . $synthetic . ':not(.' . $sourceBox . ')){display:contents}'), 'a synthetic paragraph is not boxless, so min-width/flex and editor focus apply to it');
$assert(str_contains($tracklistCss, $anchorRule), 'a paragraph that only carries a source anchor stays transparent');
$assert(strpos($tracklistCss, $anchorRule) > strpos($tracklistCss, $inlineRule), 'the anchor-carrier rule follows the inline rule at equal specificity');

$badge = ( new HtmlTransformer() )->transform(
    '<style>.card{position:relative}.featured-badge{position:absolute;top:-11px;padding:4px 14px;background:#e8a020}</style>'
    . '<div class="card"><div class="featured-badge">Most popular</div><h3>Pro</h3></div>'
)->toArray();
$badgeMarkup = (string) ( $badge['serialized_blocks'] ?? '' );
$assert(str_contains($badgeMarkup, 'featured-badge') && str_contains($badgeMarkup, HtmlCompilation::LOWERED_PARAGRAPH_CLASS) && ! preg_match('/<p class="[^"]*featured-badge[^"]*' . $synthetic . '/', $badgeMarkup), 'a text-only source div is one lowered paragraph that keeps its padded, positioned box', $badgeMarkup);
$assert('pass' === ( $badge['source_reports']['wp_block_validity']['status'] ?? '' ), 'the lowered badge paragraph remains editor-valid');

if ( $failures > 0 ) {
    fwrite(STDERR, "synthetic paragraph inline box: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "synthetic paragraph inline box passed: {$passes} assertions\n";
