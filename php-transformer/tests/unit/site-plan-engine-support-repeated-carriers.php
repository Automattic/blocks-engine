<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #6145: every inline icon gets its own geometry carrier class, and the
// engine support stylesheet restated the same declaration block once per
// carrier. The final theme stylesheet ships that block once, for all of them,
// while each icon keeps its own carrier class.
$icon = static fn (string $d): string => '<svg class="svg-inline--fa" aria-hidden="true" viewBox="0 0 512 512"><path fill="currentColor" d="' . $d . '"></path></svg>';
$html = '<!doctype html><html><head><title>Icons</title><style>.svg-inline--fa{display:var(--fa-display,inline-block);height:1em}</style></head><body><main><p>'
    . '<a href="#a">' . $icon('M0 0h512v512z') . '</a> <a href="#b">' . $icon('M0 0h256v512z') . '</a> <a href="#c">' . $icon('M0 0h512v256z') . '</a>'
    . '</p></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && 'engine-support' === ($asset['source'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
preg_match_all('/\.(be-inline-geometry-[a-f0-9]{16})\b/', $css, $carriers);
$carriers = array_unique($carriers[1]);
if (3 !== count($carriers)) throw new RuntimeException("Expected one geometry carrier per icon:\n" . $css);
$body = 'display:var(--fa-display,inline-block);height:1em';
if (1 !== substr_count($css, $body)) throw new RuntimeException("The repeated carrier declaration block must ship once:\n" . $css);
if (!preg_match('/\.' . implode(',\.', array_map('preg_quote', $carriers)) . '\{' . preg_quote($body, '/') . '/', $css)) throw new RuntimeException("Every carrier must share the folded rule:\n" . $css);
echo "Site plan engine support ships a repeated carrier declaration block once\n";
