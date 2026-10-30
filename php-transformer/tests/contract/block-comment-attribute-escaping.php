<?php
declare(strict_types=1);

/**
 * Every block delimiter in a site plan escapes its attribute JSON the way
 * WordPress core's serialize_block_attributes() does, so source text such as a
 * navigation label containing `--` or markup cannot end the comment early.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$text = 'a--b <i>x</i> & "q"';
$nav = static fn(string $current): string => '<header class="site-header"><a href="/">Brand</a><nav class="menu">'
    . '<a href="/"' . ('/' === $current ? ' class="active" aria-current="page"' : '') . '>Home ' . $text . '</a>'
    . '<a href="/about/"' . ('/about/' === $current ? ' class="active" aria-current="page"' : '') . '>About ' . $text . '</a>'
    . '<a href="/blog/">Blog</a></nav></header>';
$page = static fn(string $current, string $title, string $body): string => '<!doctype html><html><head><title>' . $title . '</title>'
    . '<style>.menu{display:flex;gap:12px}.active{color:red}.site-header{display:flex}</style></head><body>'
    . $nav($current) . '<main>' . $body . '</main><footer><p>Foot</p></footer></body></html>';
$files = array(
    'index.html' => $page('/', 'Home', '<h1>Home</h1>'),
    'about/index.html' => $page('/about/', 'About', '<h1>About</h1>'),
    'blog/index.html' => $page('/blog/', 'Blog', '<h1>Blog</h1>'),
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array_map(static fn(string $path, string $content): array => array('path' => $path, 'content' => $content), array_keys($files), $files)))->toArray()['source_reports']['wordpress_site_plan'];

$openers = 0;
$unescaped = array();
array_walk_recursive($plan, static function ($value) use (&$openers, &$unescaped): void {
    if (!is_string($value) || !preg_match_all('/<!--\s+wp:([a-z0-9\/-]+)\s+(\{.*?\})\s+\/?-->/s', $value, $matches, PREG_SET_ORDER)) return;
    foreach ($matches as [, $name, $json]) {
        ++$openers;
        if (preg_match('/--|[<>&]/', $json)) $unescaped[$name] = $json;
    }
});
if (0 === $openers || !str_contains(json_encode($plan) ?: '', 'navigation-link')) throw new RuntimeException('Fixture must emit navigation link delimiters.');
if (array() !== $unescaped) throw new RuntimeException('Block attribute JSON must be comment-safe: ' . json_encode($unescaped, JSON_UNESCAPED_SLASHES));
echo "Block comment attribute escaping contract passed.\n";
