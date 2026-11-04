<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetStructureCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

function normalized(array $output): string
{
    $strip = static function (array $value) use (&$strip): array {
        unset($value['metrics'], $value['transform_duration_ms']);
        foreach ($value as &$child) if (is_array($child)) $child = $strip($child);
        return $value;
    };
    return preg_replace('/blocks-engine-(richtext|control)-[a-f0-9]+/', 'blocks-engine-$1-MARKER', json_encode($strip($output), JSON_UNESCAPED_SLASHES));
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$compiler = new ArtifactCompiler();
$css = 'form{display:flex;gap:12px}label{font-size:14px}input{color:#123456}@media(min-width:600px){form{padding:8px}}';
for ($page = 0; $page < 4; ++$page) {
    $html = str_replace('{{page}}', (string) $page, file_get_contents(__DIR__ . '/../fixtures/contract/shared-stylesheet-structure/page.html'));
    $artifact = array('entrypoint' => 'index.html', 'files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
        array('path' => 'base.css', 'kind' => 'css', 'content' => $css),
        array('path' => 'page.css', 'kind' => 'css', 'content' => 'h1{color:rgb(' . $page . ',0,0)}'),
    ));
    check(normalized($compiler->compile($artifact)->toArray()) === normalized((new ArtifactCompiler())->compile($artifact)->toArray()), 'Reused compiler differs from fresh compilation');
    check(1 === $compiler->stylesheetStructureCache()->parses('h1{color:rgb(' . $page . ',0,0)}'), 'Each distinct page sheet must be parsed once');
}
check(1 === $compiler->stylesheetStructureCache()->parses($css), 'Shared sheet must be parsed once');
check(!method_exists(CssStylesheetStructureCache::class, 'activate'), 'Structure must not use ambient global activation');

// Reduced alternating-sheet replay: 22 shared entries total approximately 1 MB,
// with one changing page entry. These are long declarations, not 1 MB of tiny
// selectors: scanning source bytes dominated the regression in the native replay.
$cache = new CssStylesheetStructureCache();
$sheets = require __DIR__ . '/../fixtures/contract/shared-stylesheet-structure/shared-css.php';
$rewrite = static fn(string $prelude, string $body): string => $prelude . '{' . $body . '}';
$run = static function (bool $cached) use ($cache, $sheets, $rewrite): float {
    $start = microtime(true);
    for ($page = 0; $page < 8; ++$page) {
        foreach (array_merge($sheets, array('h1{color:rgb(' . $page . ',0,0)}')) as $sheet) {
            $transformer = new CssStylesheetTransformer($cached ? $cache : null);
            $actual = $transformer->transformStyleRules($sheet, $rewrite);
            check($sheet === $actual, 'Parsed units must preserve exact source bytes');
        }
    }
    return microtime(true) - $start;
};
$uncached = $run(false);
$cached = $run(true);
$inputs = $cache->inputs();
$run(true);
check($inputs === $cache->inputs(), 'Repeating pages must not grow source cache memory');
check(74 === $inputs, 'Expected 22 shared sheets, their 44 nested bodies and 8 page sheets, not pages times sheets');
// Timings are evidence, not a flaky wall-clock assertion in CI.
printf("PASS: shared stylesheet structure; %d bytes; uncached %.4fs; cached %.4fs; %d distinct inputs; peak %.1f MB\n", array_sum(array_map('strlen', $sheets)), $uncached, $cached, $inputs, memory_get_peak_usage(true) / 1048576);
