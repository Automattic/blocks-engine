<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

/**
 * Shared footer content is emitted from a real occurrence, not its identity.
 *
 * Footer copy repeated across footer variants is clustered by a normalized
 * identity that drops RichText markers and engine source classes, so that
 * occurrences compiled on different pages compare equal. That string is for
 * comparison only. Emitted as the part, a `<mark>` loses the marker the
 * reset `mark:where([style*="--blocks-engine-richtext-marker:"])` keys on,
 * and the browser paints its default yellow highlight.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$address = static fn (int $marker): string => '<!-- wp:paragraph {"className":"blocks-engine-source-p-0a1b2c3d4e5f-' . $marker . '"} -->'
    . '<p class="blocks-engine-source-p-0a1b2c3d4e5f-' . $marker . '"><mark class="wixui-rich-text__text" style="--blocks-engine-richtext-marker:blocks-engine-richtext-4968553964a3-' . $marker . '">Farmington Hills, MI 48331</mark></p>'
    . '<!-- /wp:paragraph -->';
$footer = static fn (string $slug, string $copy, string $extra): array => array(
    'source_path' => 'wordpress-site-plan/shared/' . $slug . '#footer',
    'slug' => $slug,
    'area' => 'footer',
    'canonical_block_markup' => '<!-- wp:group --><div class="wp-block-group">' . $copy . $extra . '</div><!-- /wp:group -->',
    'placement' => array('kind' => 'shared_shell', 'template_slugs' => array('index', 'page', 'front-page')),
    'provenance' => array('sources' => array('index.html' => 'x', 'about.html' => 'x')),
);
$paragraph = static fn (string $text): string => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$pages = array_map(static fn (string $path): array => array('source_path' => $path, 'canonical_block_markup' => $paragraph('Body for ' . $path), 'entrypoint' => 'index.html' === $path, 'post_type' => 'page', 'slug' => basename($path, '.html')), array('index.html', 'about.html'));

// The two footer variants were compiled separately, so their marker counters differ.
$first = $address(17);
$second = $address(42);
$result = (new ShellExtraction(new WordPressSitePlan()))->factorSharedFooterContent($pages, array(
    $footer('footer', $first, $paragraph('Desktop footer links')),
    $footer('footer-mobile', $second, $paragraph('Phone footer links')),
), array());
$contentPart = array_values(array_filter($result['parts'], static fn (array $row): bool => 'footer-content' === ($row['slug'] ?? null)))[0] ?? null;
$assert(is_array($contentPart), 'The repeated address is shared as footer content.');
$markup = (string) $contentPart['canonical_block_markup'];
$assert($first === $markup, 'The part is the first occurrence as compiled, marker and source class included. Got: ' . $markup);
$assert(str_contains($markup, '--blocks-engine-richtext-marker:'), 'The <mark> keeps the marker its reset rule matches.');
$assert(hash('sha256', $markup) === ($contentPart['provenance']['shell_identity'] ?? ''), 'The provenance identity hashes the emitted markup.');

echo "Footer content real markup contract passed.\n";
