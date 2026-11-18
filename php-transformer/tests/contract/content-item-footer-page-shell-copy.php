<?php
declare(strict_types=1);

// An entry's own <footer> (and <header>) inside an <article> is no landmark:
// HTML maps it to a generic role. Saved as <footer> in page content it reads
// as a copy of the page shell, so it saves as a plain group, unless author
// CSS still selects it by that tag.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$page = static function (string $css, string $title): string {
    return '<!doctype html><html><head><title>' . $title . '</title><style>' . $css . '</style></head><body>'
        . '<header role="banner"><nav><a href="index.html">Home</a> <a href="about.html">About</a></nav></header>'
        . '<main><article class="entry"><header class="entry-header"><h1 class="entry-title"><a href="post.html">' . $title . ' post</a></h1></header>'
        . '<p>Body text of the entry.</p><footer class="entry-footer"><a href="post.html">Share</a></footer></article></main>'
        . '<footer><p>Site footer</p></footer></body></html>';
};

$import = static function (string $css) use ($page): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $page($css, 'Home'),
        'about.html' => $page($css, 'About'),
    )))->toArray();
    return (new WordPressSitePlan())->fromResult($result);
};

// normalize.css only restates a div's own display for the sectioning tags.
$plan = $import('article,aside,footer,header,nav,section{display:block} .entry-footer{margin-top:1em}');
$footerParts = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'footer' === ($part['area'] ?? null)));
$assert(1 === count($footerParts), 'The site footer must still extract as the shared footer part');
foreach ($plan['pages'] as $entry) {
    $content = (string) ($entry['canonical_block_markup'] ?? $entry['block_markup'] ?? '');
    $assert(str_contains($content, 'entry-footer'), 'The entry footer must stay in page content');
    $assert(1 !== preg_match('/<footer[ >]/i', $content), 'An article footer must not save as a <footer> copy in page content: ' . $content);
    $assert(1 !== preg_match('/<header[ >]/i', $content), 'An article header must not save as a <header> copy in page content: ' . $content);
    $assert(!str_contains($content, '"name":"Footer"') && !str_contains($content, '"name":"Header"'), 'A content item header/footer must not be named as a landmark');
}

// Author CSS that selects the entry header by its tag keeps the tag.
$plan = $import('article header h1 a{color:#123456}');
foreach ($plan['pages'] as $entry) {
    $content = (string) ($entry['canonical_block_markup'] ?? $entry['block_markup'] ?? '');
    $assert(1 === preg_match('/<header class="wp-block-group entry-header/', $content), 'An article header author CSS selects by tag must keep its tag');
    $assert(1 !== preg_match('/<footer[ >]/i', $content), 'An article footer no author rule selects by tag must save as a plain group');
}

echo "content item footer page shell copy: ok\n";
