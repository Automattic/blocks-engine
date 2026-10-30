<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Issue #3613: the more specific auto width wins over the intrinsic base.
$html = <<<'HTML'
<style>
.slot{display:grid;grid-template-columns:minmax(0,1fr);width:150px;aspect-ratio:150/44}
.pill{box-sizing:border-box;display:flex;justify-content:center;align-items:center;width:max-content;min-width:100%;background:#aa6133;padding:2px;color:#fff;text-decoration:none}
.slot .pill{width:auto}
.pill__label{font-size:18px;line-height:1.4}
</style>
<main><p>Intro text.</p>
<div class="slot"><a class="pill button" href="/a"><span class="pill__label">Learn More</span></a></div>
<p>After.</p></main>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$css = '';
array_walk_recursive($result, static function ($value) use (&$css): void {
    if (is_string($value) && str_contains($value, 'blocks-engine-control-')) $css .= $value . "\n";
});
$assert(str_contains((string) $result['serialized_blocks'], 'wp-block-button__link'), 'Fixture becomes a native button.');
$minimum = $auto = $stretch = $fill = false;
preg_match_all('/([^{}]*)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
foreach ($rules as [, $selector, $body]) {
    if (! str_contains($selector, 'blocks-engine-control-')) continue;
    if (str_contains($selector, 'wp-block-button__link')) {
        $minimum = $minimum || (bool) preg_match('/(?:^|;)min-width:100%/', $body);
        $auto = $auto || (bool) preg_match('/(?:^|;)width:auto(?:;|$|!)/', $body);
        $assert(! preg_match('/(?:^|;)width:max-content\s*!important/', $body), 'A losing intrinsic width must not be promoted on the link.');
    }
    if (str_contains($selector, 'wp-block-button)') && ! str_contains($selector, 'wp-block-button__link')) {
        $stretch = $stretch || str_contains($body, 'display:flex') && str_contains($body, 'align-items:stretch');
        $fill = $fill || str_contains($body, 'width:100%');
    }
}
$assert($minimum, 'The source min-width:100% must constrain the emitted link.');
$assert($auto, 'The winning descendant width:auto must reach the emitted link.');
$assert($fill, 'The intermediate button must fill the percentage width basis.');
$assert($stretch, 'The intermediate button must carry the stretched track height to the link.');
echo "Grid item button link box contract passed.\n";
