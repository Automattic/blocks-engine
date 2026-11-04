<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// A reset on the anchor must not erase artwork formerly inside that anchor.
$source = '<style>nav ul li a{background:transparent}svg{width:24px;height:24px}</style>'
    . '<nav aria-label="Profiles"><a href="https://example.com" aria-label="Profile">'
    . '<svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>'
    . '</a></nav>';
$result = (new HtmlTransformer())->transform($source)->toArray();
preg_match('/<!--\s*wp:navigation-link\s+(\{.*?\})\s*\/?-->/s', $result['serialized_blocks'], $match);
$attrs = json_decode($match[1] ?? '{}', true);
if (!str_contains($attrs['className'] ?? '', 'blocks-engine-navigation-link-icon-')) {
    throw new RuntimeException('Expected native navigation artwork projection.');
}
// Core's navigation-link render surface, with the source anchor reset projected
// by a site plan (the added root/item specificity is intentional).
$candidate = '<nav class="wp-block-navigation"><ul class="wp-block-navigation__container">'
    . '<li class="wp-block-navigation-item ' . htmlspecialchars($attrs['className'], ENT_QUOTES) . '">'
    . '<a class="wp-block-navigation-item__content" href="https://example.com">'
    . '<span class="wp-block-navigation-item__label">Profile</span></a></li></ul></nav>';
$css = implode("\n", array_column($result['assets'], 'content'));
echo json_encode(array('candidate' => $candidate, 'css' => $css));
