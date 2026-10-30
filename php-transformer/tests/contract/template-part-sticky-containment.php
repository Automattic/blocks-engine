<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\TemplatePartStickyContainment;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

// A sticky element moves only within its parent. A shared header part puts
// the authored sticky header inside the part's transport wrapper, which is
// exactly as tall as the header, so it would scroll away. On the front end the
// wrapper gives up its box when it directly holds an authored sticky subject.
$build = static fn (string $css): string => (new TemplatePartStickyContainment())->supportCss($css);
$rule = static fn (string $subject): string => ':where(body:not(.editor-styles-wrapper):not(.wp-admin)) :where(.wp-block-template-part):has(> ' . $subject . '){display:contents}';

$assert($rule('.masthead') === $build('/* Navigation */ .masthead{position:sticky;top:0;z-index:5}'), 'a sticky compound subject releases its part wrapper; comments are not selector bytes');
$assert($rule('.masthead') === $build(':root .masthead{position:-webkit-sticky;top:0}'), 'a :root scope and the prefixed keyword select the same subject');
$assert('@media (min-width: 800px){' . $rule('header.bar[data-x="a b"]') . '}' === $build('@media (min-width: 800px){header.bar[data-x="a b"]{position:sticky}}'), 'conditional rules keep their at-rule and attribute values');
$assert('' === $build('.page .toc{position:sticky;top:0}.rail > .toc{position:sticky}.x::before{position:sticky}body{position:sticky}'), 'descendant, child, pseudo-element and document subjects cannot be a part wrapper\'s direct child');
$assert('' === $build('.card{position:relative}.over{position:fixed;top:0}.later{position:sticky;position:static}'), 'non-sticky and overridden positions are left alone');
$assert($rule('.later') === $build('.later{position:sticky!important;position:static}'), 'an important sticky declaration wins over a later normal one');
$assert(1 === substr_count($build('.x{position:sticky}.x{position:sticky}'), '{display:contents}'), 'a repeated subject emits one rule');

// End to end: a sticky header, styled by a linked or an inline stylesheet,
// becomes a shared header part, and the compiled assets release that part's
// wrapper around it.
$css = '.masthead{position:sticky;top:0;z-index:10;background:#fff}.masthead nav a{margin-right:1rem}';
foreach (array('linked' => '<link rel="stylesheet" href="styles.css">', 'inline' => '<style>' . $css . '</style>') as $kind => $head) {
    $routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
    $files = 'linked' === $kind ? array('styles.css' => $css) : array();
    foreach ($routes as $slug => $title) {
        $links = '';
        foreach ($routes as $target => $label) $links .= '<a href="' . $target . '.html">' . $label . '</a>';
        $files[$slug . '.html'] = '<!doctype html><html><head><title>' . $title . '</title>' . $head . '</head><body>'
            . '<header class="masthead"><a href="index.html">Harbor Parish</a><nav>' . $links . '</nav></header>'
            . '<main><h1>' . $title . '</h1><p>' . $title . ' body copy.</p></main>'
            . '<footer class="colophon"><p>1 Shore Road</p></footer></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), $kind . ': the sticky header is one shared header part');
    $assert(str_contains((string) ($headers[0]['canonical_block_markup'] ?? ''), 'masthead'), $kind . ': the shared part holds the sticky header');
    $released = array_filter($plan['assets'], static fn (array $asset): bool => str_contains((string) ($asset['content'] ?? ''), $rule('.masthead')));
    $assert(array() !== $released, $kind . ': a compiled stylesheet releases the header part wrapper around the sticky header');
}

if ($failures > 0) {
    fwrite(STDERR, "Template part sticky containment tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Template part sticky containment tests: {$passes} passed\n");
