<?php
declare(strict_types=1);

/**
 * A list-less menu (`nav > a`) has no source list item, so the li core
 * synthesizes around each link had no box. An anchor class hoisted onto that
 * li must not paint its button a second time (#2912).
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$document = static function (string $buttonWrapper = ''): string {
    $button = '<a class="btn-primary" href="mailto:hello@example.test">Start a project</a>';
    if ('' !== $buttonWrapper) $button = '<span class="' . $buttonWrapper . '">' . $button . '</span>';
    $links = '<a href="/#services">Services</a><a href="/#pricing">Pricing</a>';
    return '<!doctype html><html><head><style>'
        . '.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}'
        . '.pill{padding:2px;background:#123}'
        . '.btn-primary{display:inline-flex;padding:.85rem 1.35rem;border-radius:10px;background:linear-gradient(100deg,#2b6bff,#21d9c0);color:#03101f}'
        . '</style></head><body><header><div class="bar"><a href="/">Brand</a><nav>' . $links . $button . '</nav>'
        . '<button aria-label="Toggle menu" aria-expanded="false" aria-controls="menu-panel"><span class="sr-only">Menu</span><svg width="18" height="14" viewBox="0 0 18 14" aria-hidden="true"><path d="M1 1h16M1 7h16M1 13h16" stroke="#000"/></svg></button>'
        . '</div><div class="panel" hidden id="menu-panel">' . $links . '<a class="btn-primary" href="mailto:hello@example.test">Start a project</a></div></header>'
        . '<main><h1>Home</h1></main></body></html>';
};
$compile = static function (string $html): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''))));
    return array($result['serialized_blocks'], $css);
};
// The rendered li that core wraps around the button carries the hoisted class.
$itemRules = static function (string $css): array {
    preg_match_all('/([^{}]*\.wp-block-navigation-item\.btn-primary)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER);
    return array_map(static fn(array $match): string => $match[2], $matches);
};

[$blocks, $css] = $compile($document());
$assert(str_contains($blocks, 'wp:navigation-link {"className":"btn-primary'), 'core moves the anchor class onto its synthesized navigation item');
$assert(str_contains($css, '.wp-block-navigation-item.btn-primary>.wp-block-navigation-item__content{') && str_contains($css, 'background:linear-gradient'), 'the button paint is projected onto the rendered anchor');
$reset = implode(';', $itemRules($css));
foreach (array('padding', 'border-radius', 'background') as $property) {
    $assert(str_contains($reset, $property . ':revert'), "the synthesized item reverts the hoisted {$property}, so the button paints once");
}
$assert(!preg_match('/(?:^|;)display:revert/', $reset), 'core keeps its own item layout');

[, $wrappedCss] = $compile($document('pill'));
$assert(!str_contains(implode(';', $itemRules($wrappedCss)), 'background:revert'), 'a per-item wrapper that painted a real box fails closed');
echo "Navigation list-less anchor item reset passed\n";
