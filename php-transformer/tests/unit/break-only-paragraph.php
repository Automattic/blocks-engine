<?php
declare(strict_types=1);

/** Break-only rich-text blocks keep their line boxes, unlike empty wrappers. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message, string $detail): void {
    if ( ! $ok ) {
        fwrite(STDERR, "FAIL: {$message}\n{$detail}\n");
        exit(1);
    }
};

foreach ( array( '1.5', '27px' ) as $lineHeight ) {
    foreach ( array( '<div class="para"><span><br role="presentation"></span></div>', '<p class="para"><br></p>', '<div class="para"><br><br></div>' ) as $blank ) {
        $result = ( new HtmlTransformer() )->transform(
            '<style>.para{margin:0;font-size:18px;line-height:' . $lineHeight . '}</style>'
            . '<div><p class="para">First paragraph.</p></div><div>' . $blank . '</div>'
            . '<div><div><span></span></div></div>'
            . '<div><p class="para">Second paragraph.</p></div>'
        )->toArray();
        $markup = (string) ($result['serialized_blocks'] ?? '');
        preg_match_all('/<!-- wp:paragraph\b[^>]*-->\s*(<p\b[^>]*>.*?<\/p>)/s', $markup, $paragraphs);
        $assert(3 === count($paragraphs[1]), 'exactly three paragraphs survive; the empty wrapper does not', $markup);
        $middle = $paragraphs[1][1];
        $assert(str_contains($paragraphs[1][0], 'First paragraph.') && str_contains($paragraphs[1][2], 'Second paragraph.'), 'the blank line stays between the text paragraphs', $markup);
        $assert(str_contains($middle, 'para') && ! str_contains($middle, 'blocks-engine-empty-source-paragraph'), 'the blank line retains source styling and is not collapsed in the editor', $markup);
        $assert(str_contains(html_entity_decode(strip_tags($middle), ENT_QUOTES | ENT_HTML5, 'UTF-8'), "\u{00a0}"), 'blank line uses nonempty editor-safe RichText content', $markup);
        $assert((str_contains($blank, '<br><br>') ? 1 : 0) === substr_count($middle, '<br'), 'multiple breaks retain their line count without a trailing padding break', $markup);
        $css = '';
        foreach ( $result['assets'] ?? array() as $asset ) {
            if ( 'css' === ($asset['kind'] ?? null) ) {
                $css .= (string) ($asset['content'] ?? '');
            }
        }
        $assert(str_contains($markup . $css, 'line-height:' . $lineHeight) || str_contains($markup, '"lineHeight":"' . $lineHeight . '"'), 'authored line height survives', $markup . $css);
    }
}
echo "break-only paragraphs passed\n";
