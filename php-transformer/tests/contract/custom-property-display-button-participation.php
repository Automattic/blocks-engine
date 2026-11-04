<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Issue #4254: a parent whose `display` resolves through a custom property
// (`display:var(--d)` with `--d:flex`) is a flex container just like the
// literal `display:flex` variant. Layout participation has to resolve the
// `var()` before classifying, so the link keeps its `min-width` on the
// `wp-block-button__link` control rule in both variants.
$fixture = static function (string $rowRule): string {
    return <<<HTML
<style>
$rowRule
.pill{box-sizing:border-box;display:flex;justify-content:center;align-items:center;width:min-content;min-width:120px;aspect-ratio:1/0.25;background:#222;color:#fff;border:1px solid #fff;padding:0 4px;text-decoration:none}
.pill__label{font-size:12px}
</style>
<main><p>Intro text.</p><div class="row"><a class="pill" href="/a"><span class="pill__label">SHOP</span></a></div><p>After.</p></main>
HTML;
};

$linkDeclaresMinimumWidth = static function (string $html) use ($assert): bool {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $assert(str_contains((string) $result['serialized_blocks'], 'wp-block-button__link'), 'Fixture becomes a native button.');
    $css = '';
    array_walk_recursive($result, static function ($value) use (&$css): void {
        if (is_string($value) && str_contains($value, 'blocks-engine-control-')) $css .= $value . "\n";
    });
    $minimum = false;
    preg_match_all('/([^{}]*)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    foreach ($rules as [, $selector, $body]) {
        if (! str_contains($selector, 'blocks-engine-control-')) continue;
        if (str_contains($selector, 'wp-block-button__link')) {
            $minimum = $minimum || (bool) preg_match('/(?:^|;)min-width:120px/', $body);
        }
    }
    return $minimum;
};

$assert(
    $linkDeclaresMinimumWidth($fixture('.row{display:flex;align-items:flex-start}')),
    'The literal display:flex variant must declare min-width:120px on the link control.'
);
$assert(
    $linkDeclaresMinimumWidth($fixture('.row{--d:flex;display:var(--d);align-items:flex-start}')),
    'The custom-property display variant must declare min-width:120px on the link control like the literal variant.'
);

echo "Custom property display button participation contract passed.\n";
