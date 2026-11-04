<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) throw new RuntimeException($message);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/shared-form-cascade.html');
$css = ':root{--space:12px;--ink:#123456}.contact{display:flex;flex-direction:column;gap:var(--space);color:var(--ink);font:16px sans-serif}.fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}.contact label{display:flex;flex-direction:column}.contact input{padding:8px;border:1px solid #ccc}.contact button{padding:8px 16px;background:#123456;color:white}@media(max-width:600px){.fields{grid-template-columns:1fr}}';
for ( $i = 0; $i < 150; ++$i ) $css .= '.unused-' . $i . '{display:block;color:red;padding:2px}';
$files = array('index.html' => $html, 'other.html' => str_replace('Contact', 'Other', $html), 'shared.css' => $css);
$before = CssRuleAnalyzer::indexBuilds();
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$builds = CssRuleAnalyzer::indexBuilds() - $before;
$assert(4 === $builds, 'Two pages with two forms each prepare four distinct stylesheet/property analyses; actual: ' . $builds);
// Repeating the same pages must not build more immutable CSS analyses.
$beforeRepeat = CssRuleAnalyzer::indexBuilds();
(new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files));
$assert($beforeRepeat === CssRuleAnalyzer::indexBuilds(), 'Replaying the same stylesheet inputs reuses the owning CSS index.');
// Wall-clock telemetry is the only nondeterministic part of the result.
$withoutTiming = static function (array $value) use (&$withoutTiming): array {
    foreach ( $value as $key => $child ) {
        if ( in_array($key, array('transform_duration_ms', 'step_timings'), true) ) unset($value[$key]);
        elseif ( is_array($child) ) $value[$key] = $withoutTiming($child);
    }
    return $value;
};
$hash = hash('sha256', json_encode($withoutTiming($result), JSON_UNESCAPED_SLASHES));
$assert('70bfc6fc12c7b2fc63071c397692bd4a64f3ed5d6c968b3f77267d6ebec48d3b' === $hash, 'Complete compiled output, including fallback/layout/presentation graphs and receipts, matches trunk: ' . $hash);
