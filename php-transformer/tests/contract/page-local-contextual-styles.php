<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = '<style>.row{display:flex;flex-wrap:wrap;--g:1.5rem;margin-left:calc(-.5*var(--g));margin-right:calc(-.5*var(--g))}.row>*{padding-left:calc(var(--g)*.5);padding-right:calc(var(--g)*.5)}.box{max-width:1000px;margin:0 auto}</style>';
$page = static fn(string $body): string => '<!doctype html><html><head>' . $css . '</head><body><main>' . $body . '</main></body></html>';
$a = $page('<div class="row"><div class="box"><p>In a row</p></div></div><a href="/b/">b</a>');
$b = $page('<section><div class="box"><p>Not in a row</p></div></section>');
$boxes = static function (array $files): array {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $result = array();
    foreach ($plan['pages'] as $page) {
        if (preg_match('/<!-- wp:group (\{"className":"box(?: [^"]*)?"(?:(?!-->).)*\}) -->/s', $page['canonical_block_markup'], $match)) {
            $result[$page['source_path']] = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        }
    }
    return $result;
};
$alone = $boxes(array('index.html' => $b));
$together = $boxes(array('index.html' => $a, 'b/index.html' => $b));
if (!isset($alone['index.html'], $together['b/index.html'], $together['index.html'])) throw new RuntimeException('Both source boxes must remain groups.');
if ($alone['index.html'] !== $together['b/index.html']) throw new RuntimeException('A contextual rule on page a must not change page b box attributes.');
if (!isset($together['index.html']['style']['spacing']['padding'])) throw new RuntimeException('The box inside a row must keep its matching padding.');
echo "Page-local contextual styles passed\n";
