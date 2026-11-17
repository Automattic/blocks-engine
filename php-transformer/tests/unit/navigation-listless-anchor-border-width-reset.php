<?php
declare(strict_types=1);

/**
 * A list-less menu (`nav > a`) has no source list item, so the li core
 * synthesizes around each link had no border. When a hoisted anchor class sets
 * a border width, `revert` restores the user-agent `medium` (3px), which a
 * universal `border-style:solid` reset (Tailwind preflight) then paints as a
 * thick second frame around the button. The synthesized item resets the width
 * to zero instead (#6378).
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$html = '<!doctype html><html><head><style>'
    . '*,:before,:after{box-sizing:border-box;border-width:0;border-style:solid;border-color:#e5e7eb}'
    . '.hidden{display:none}.flex{display:flex}.items-center{align-items:center}.gap-10{gap:2.5rem}'
    . '.border{border-width:1px}.border-ink{border-color:#1d1d1b}.px-5{padding-left:1.25rem;padding-right:1.25rem}'
    . '@media (min-width:768px){.md\\:flex{display:flex}.md\\:hidden{display:none}}'
    . '</style></head><body><header><div class="flex items-center"><a href="#top">Lumen</a>'
    . '<nav class="hidden items-center gap-10 md:flex"><a href="#services">Services</a><a href="#gallery">Gallery</a>'
    . '<a href="#reserve" class="border border-ink px-5">Reserve</a></nav>'
    . '<button aria-label="Menu" class="flex md:hidden"><span></span></button></div></header>'
    . '<main><h1>Home</h1></main></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''))));

preg_match_all('/[^{}]*\.wp-block-navigation-item\.border\{([^}]*)\}/', $css, $itemMatches);
$itemReset = implode(';', $itemMatches[1]);
preg_match_all('/[^{}]*\.wp-block-navigation-item\.border\s*>\s*\.wp-block-navigation-item__content\{([^}]*)\}/', $css, $anchorMatches);
$anchorPaint = implode(';', $anchorMatches[1]);

$assert(str_contains($anchorPaint, 'border-width:1px'), 'the authored border is projected onto the rendered anchor');
$assert('' !== $itemReset, 'the synthesized item resets the hoisted border class');
$assert(!str_contains($itemReset, 'border-width:revert'), 'the synthesized item does not revert its border width to the user-agent medium');
$assert(1 === preg_match('/(?:^|;)border-width:0(?:;|$)/', $itemReset), 'the synthesized item had no border, so its width resets to zero');
echo "Navigation list-less anchor border width reset passed\n";
