<?php
declare(strict_types=1);

/**
 * A heading inside the page-level footer (or a form) is site chrome, not the
 * page's name. When main carries no heading, the page falls back to its
 * declared document title instead of the footer form's heading. A post's own
 * footer inside its article still counts as content.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (!$condition) throw new RuntimeException($message . ('' !== $detail ? ' - ' . $detail : ''));
};

$page = static fn (string $title, string $main, string $footer): string => '<!doctype html><html><head><title>' . $title . '</title></head><body>'
    . '<header><p><a href="/">Site</a></p></header><main>' . $main . '</main>' . $footer . '</body></html>';
$compile = static function (array $files): array {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    return array_column($plan['pages'], 'title', 'source_path');
};

$footerForm = '<footer><form action="/quote"><h1>Request a quote</h1><p><input type="email" name="email"></p><button type="submit">Send</button></form></footer>';
$contentinfo = '<div role="contentinfo"><h1>Request a quote</h1></div>';
$titles = $compile(array(
    array('path' => 'index.html', 'content' => $page('Welcome home', '<h1>Welcome</h1><p>Intro.</p>', $footerForm)),
    array('path' => 'blog/index.html', 'content' => $page('Blog | Site', '<p>Latest writing.</p>', $footerForm)),
    array('path' => 'news/index.html', 'content' => $page('Newsroom', '<p>Latest news.</p>', $contentinfo)),
    array('path' => 'story/index.html', 'content' => $page('A story', '<article><p>Body.</p><footer><h1>Story footer</h1></footer></article>', '')),
));

$assert('Request a quote' !== ($titles['blog/index.html'] ?? null), 'A page-level footer form heading never names the page', (string) json_encode($titles));
$assert('Blog | Site' === ($titles['blog/index.html'] ?? null), 'The page falls back to its declared document title', (string) json_encode($titles));
$assert('Newsroom' === ($titles['news/index.html'] ?? null), 'A contentinfo heading never names the page', (string) json_encode($titles));
$assert('Story footer' === ($titles['story/index.html'] ?? null), 'An article-scoped footer heading still names the page', (string) json_encode($titles));

echo "page footer form heading title: passed\n";
