<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A footer list of text links to social hosts, with no class naming a social
// region, is a text list. Core social-link would paint a service glyph and
// brand pill the source never had.
$cases = array(
    'text list' => array('<li><a href="https://www.instagram.com/kuru/">Instagram</a></li><li><a href="https://www.facebook.com/kuru">Facebook</a></li><li><a href="https://www.tiktok.com/@kuru">TikTok</a></li>', false),
    'icon-font list' => array(str_replace('GLYPH', "\u{E001}", '<li><a href="https://www.instagram.com/kuru/" aria-label="Instagram"><i class="icon-instagram">GLYPH</i></a></li><li><a href="https://www.facebook.com/kuru" aria-label="Facebook"><i class="icon-facebook">GLYPH</i></a></li>'), true),
);
foreach ( $cases as $name => [$items, $social] ) {
    $result = (new HtmlTransformer())->transform('<footer><div><h4>Redes sociales</h4><ul>' . $items . '</ul></div></footer>', array())->toArray();
    $markup = (string) $result['serialized_blocks'];
    if ( $social !== str_contains($markup, '<!-- wp:social-links') ) {
        throw new RuntimeException($name . ': ' . ($social ? 'icon links keep core/social-links' : 'text links stay a list'));
    }
    if ( ! $social && (! str_contains($markup, '<!-- wp:list') || ! str_contains($markup, '>Instagram</a>')) ) {
        throw new RuntimeException($name . ': the text links stay editable list items with their labels.');
    }
    if ( 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? null) ) {
        throw new RuntimeException($name . ': editor-valid.');
    }
}
echo "Social-host text lists stay lists.\n";
