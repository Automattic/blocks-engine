<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RouteLinks;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

/**
 * Shared footer content is placed once per footer a document authors.
 *
 * A Squarespace/Ylopo archive renders both the platform footer and an
 * alternate footer, and both carry the same office paragraph. Extraction
 * references the shared part in each footer, so the binding proof must
 * accept one reference per authored footer, not only one per responsive copy.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$paragraph = static fn (string $text): string => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$footer = static fn (string $class, string $inner): string => '<!-- wp:group {"tagName":"footer","className":"' . $class . '"} --><footer class="wp-block-group ' . $class . '">' . $inner . '</footer><!-- /wp:group -->';
$office = $paragraph('Tusch Real Estate Group, 1 High Street, Watford');
$site = static fn (string $inner): string => '<!-- wp:group {"className":"site-wrapper"} --><div class="wp-block-group site-wrapper">' . $inner . '</div><!-- /wp:group -->';
$pages = array(
    array('source_path' => 'blog/category/Seller/index.html', 'post_type' => 'page', 'slug' => 'seller', 'canonical_block_markup' => $site($paragraph('Seller posts') . $footer('footer', $office . $paragraph('Office hours')) . $footer('alternate-footer', $office . $paragraph('Quick links')))),
    array('source_path' => 'team/index.html', 'post_type' => 'page', 'slug' => 'team', 'canonical_block_markup' => $site($paragraph('Team') . $footer('alternate-footer', $office . $paragraph('Quick links')))),
);

$result = (new ShellExtraction(new RouteLinks('', '', array(), array(), null, array(), array())))->factorSharedFooterContent($pages, array(), array());
$part = array_values(array_filter($result['parts'], static fn (array $row): bool => 'footer-content' === ($row['slug'] ?? null)))[0] ?? null;
$assert(is_array($part), 'The office paragraph is shared as footer content.');
$seller = (string) $result['pages'][0]['canonical_block_markup'];
$assert(2 === substr_count($seller, '"slug":"footer-content"'), 'Each authored footer on the archive references the shared content.');

$templates = array_map(static fn (array $page): array => array('slug' => 'page-' . $page['slug'], 'source_path' => $page['source_path'], 'shell_frame' => array('footer-content'), 'canonical_block_markup' => $page['canonical_block_markup']), $result['pages']);
ThemeTemplates::assertPartBound($part, array('templates' => $templates, 'pages' => array(), 'taxonomy_entities' => array(), 'author_entities' => array()), array());

$templates[0]['canonical_block_markup'] .= '<!-- wp:template-part {"slug":"footer-content","area":"footer"} /-->';
try {
    ThemeTemplates::assertPartBound($part, array('templates' => $templates, 'pages' => array(), 'taxonomy_entities' => array(), 'author_entities' => array()), array());
    $assert(false, 'A reference beyond the authored footers is still rejected.');
} catch (InvalidArgumentException $exception) {
    $assert(str_contains($exception->getMessage(), 'binding is invalid'), 'The extra reference fails the binding proof.');
}

echo "Footer content multiple footers contract passed.\n";
