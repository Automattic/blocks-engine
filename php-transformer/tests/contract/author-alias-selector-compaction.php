<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$css = '.links-component{display:flex;gap:4px;list-style:none;padding:0}.links-component>li>a{color:#fff;text-decoration:underline}';
$html = '';
for ($n = 1; $n <= 4; $n++) {
    $css .= '.links-component.instance-' . $n . '{gap:' . $n . 'px}.links-component.instance-' . $n . '>li>a{font-size:1' . $n . 'px}';
    $html .= '<ul class="links-component instance-' . $n . '"><li><a href="/a">A</a></li></ul>';
}
$out = (new HtmlTransformer())->transform('<!doctype html><html><head><style>' . $css . '</style></head><body><main>' . $html . '</main></body></html>')->toArray();
$projected = implode('', array_column($out['source_reports']['author_stylesheet_projections'], 'content'));
if (strlen($projected) > 1.6 * strlen($css)) throw new RuntimeException('Author CSS grew from ' . strlen($css) . ' to ' . strlen($projected) . ' bytes: ' . $projected);
$rules = 0;
(new CssStylesheetTransformer())->visitStyleRules($projected, static function () use (&$rules): void { $rules++; });
if (10 !== $rules) throw new RuntimeException('Expected one rule per source rule, got ' . $rules);
if (preg_match('/(:not\([^()]+\))\1/', $projected)) throw new RuntimeException('Repeated guard: ' . $projected);
echo 'Alias selector compaction: ' . strlen($css) . ' source bytes, ' . strlen($projected) . " projected bytes.\n";
