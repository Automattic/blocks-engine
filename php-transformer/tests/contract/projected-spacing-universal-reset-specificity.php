<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

// Both recognition-boundary tokens and ordinary class names must preserve the
// browser winner, regardless of where the universal reset appears.
foreach (array('header-layout', 'block-header-layout', 'header-layout-desktop', 'header-x', 'x-layout', 'layout-desktop') as $class) {
    foreach (array(false, true) as $customProperty) {
        foreach (array(false, true) as $resetFirst) {
            $padding = $customProperty ? 'var(--padding,24px 0)' : '14px';
            $rule = '.' . $class . '{padding:' . $padding . ';display:grid}';
            $css = $resetFirst ? '*{padding:0}' . $rule : $rule . '*{padding:0}';
            $result = (new ArtifactCompiler())->compile(array(
                'entrypoint' => 'index.html',
                'files' => array(
                    's.css' => $css,
                    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><header'
                        . ($customProperty ? ' style="--padding:14px"' : '')
                        . '><div class="' . $class . '"></div></header></body></html>',
                ),
            ))->toArray();
            $markup = (string) ($result['serialized_blocks'] ?? '');
            $label = $class . ($customProperty ? ' inherited variable' : ' literal') . ($resetFirst ? ' reset first' : ' reset last');
            $assert(1 === preg_match('/<div\b[^>]*class="[^"]*\b' . preg_quote($class, '/') . '\b[^"]*"[^>]*>/', $markup, $group), $label . ': group survives.');
            preg_match('/\bstyle="([^"]*)"/', $group[0], $style);
            $inline = html_entity_decode($style[1] ?? '', ENT_QUOTES);
            $assert(! preg_match('/(?:^|;)\s*padding(?:-[a-z]+)?\s*:\s*0(?:px)?(?:\s|;|$)/', $inline), $label . ': no inline zero padding.');
            if (preg_match('/(?:^|;)\s*padding(?:-[a-z]+)?\s*:/', $inline)) {
                $assert(str_contains($inline, '14px') || ($customProperty && str_contains($inline, 'var(--padding')), $label . ': inline padding retains the author value.');
            } else {
                $assets = implode("\n", array_column($result['assets'] ?? array(), 'content'));
                $assert(str_contains($assets, 'padding:' . $padding), $label . ': stylesheet retains author padding.');
            }
            if ($customProperty && ! str_contains($inline, '14px')) {
                $assets = implode("\n", array_column($result['assets'] ?? array(), 'content'));
                $assert(str_contains($markup, '--padding:14px') || str_contains($assets, '--padding:14px'), $label . ': inherited custom property remains available.');
            }
        }
    }
}

// A reset is still a real declaration when no stronger padding rule exists.
$control = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        's.css' => '.header-layout{display:grid}*{padding:0}',
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><header><div class="header-layout"><p>Reset control</p></div></header></body></html>',
    ),
))->toArray();
$assert(1 === preg_match('/<div\b[^>]*class="[^"]*header-layout[^>]*>/', $control['serialized_blocks'] ?? '', $group), 'Reset-only group survives.');
$assert(str_contains($group[0], 'padding-top:0') && str_contains($group[0], 'padding-right:0') && str_contains($group[0], 'padding-bottom:0') && str_contains($group[0], 'padding-left:0'), 'Universal reset alone still projects zero padding.');

fwrite(STDOUT, "Projected spacing universal reset specificity contract passed\n");
