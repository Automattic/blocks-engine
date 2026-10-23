<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$runtime = new Runtime();
$assertNoFreeform = static function (array $blocks, string $source) use (&$assertNoFreeform, $assert): void {
    foreach ($blocks as $block) {
        $assert(null !== $block['blockName'] || '' === trim($block['innerHTML']), $source . ' has non-whitespace freeform residue');
        $assertNoFreeform($block['innerBlocks'] ?? array(), $source);
    }
};

// Real block-theme shape: the template-part header wraps another header and
// social links. The inner header is content of the outer one, not a variant.
$innerHeader = '<!-- wp:group {"tagName":"header","className":"inner-header"} --><header class="wp-block-group inner-header"><!-- wp:paragraph --><p>Site brand</p><!-- /wp:paragraph --></header><!-- /wp:group -->';
$socialLinks = '<!-- wp:social-links {"className":"site-social-links"} --><ul class="wp-block-social-links site-social-links"><!-- wp:social-link {"url":"https://example.com/","service":"wordpress"} /--></ul><!-- /wp:social-links -->';
$header = '<!-- wp:group {"tagName":"header","className":"wp-block-template-part"} --><header class="wp-block-group wp-block-template-part">' . $innerHeader . $socialLinks . '</header><!-- /wp:group -->';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about.html' => '<main><h1>About</h1></main>',
)))->toArray();
foreach ($result['source_reports']['compiled_site']['pages'] as &$page) {
    $title = 'index.html' === $page['source_path'] ? 'Home' : 'About';
    $page['block_markup'] = '<!-- wp:group {"className":"site-root"} --><div class="wp-block-group site-root">' . $header
        . '<!-- wp:paragraph --><p>' . $title . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
}
unset($page);

$plan = (new WordPressSitePlan())->fromResult($result);
$parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($parts), 'Nested header landmarks must extract as one header part');
$part = $parts[0];
$assert(str_contains($part['canonical_block_markup'], $innerHeader), 'The part must preserve the complete inner header');
$assert(str_contains($part['canonical_block_markup'], $socialLinks), 'The part must preserve the following social-links block');
$assertNoFreeform($runtime->parseBlocks($part['canonical_block_markup']), 'header part');
foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    $assertNoFreeform($runtime->parseBlocks($markup), $page['source_path']);
    $assert(1 === substr_count($markup, '"slug":"' . $part['slug'] . '"'), 'Each page must reference the single header exactly once');
    $assert(!str_contains($markup, 'Site brand') && !str_contains($markup, 'site-social-links'), 'All header content must move into the part');
    $title = 'index.html' === $page['source_path'] ? 'Home' : 'About';
    $assert(str_contains($markup, '<p>' . $title . '</p>'), 'Page content after the header must remain intact');
}
fwrite(STDOUT, "Nested header landmark shell tests passed\n");
