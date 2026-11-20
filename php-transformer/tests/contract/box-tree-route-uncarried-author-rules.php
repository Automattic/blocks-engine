<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

// The notes page sets its family and leading on `body`, sizes its header
// logo with `clamp()`, and colours links by context (`article a`,
// `footer a`). The home page has no stylesheet, so no root typography is
// unanimous and the theme root carries none. Once the box-tree route
// retires the author stylesheet, carriers prune values equal to their
// parent's, so the body has to restate the family and leading its content
// inherits. The header row spreads the logo and its action to opposite ends,
// so it stays a group of a core/image and core/buttons (core/media-text would
// stretch both panes across the row); the logo's `clamp()` width still needs
// a carrier of its own once the stylesheet is retired.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$fixture = __DIR__ . '/../fixtures/box-tree-route-uncarried-author-rules';
$files = array();
foreach (array('index.html', 'notes/index.html', 'site.css', 'logo.svg', 'box-tree/notes/index.json') as $path) $files['website/' . $path] = file_get_contents($fixture . '/' . $path);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$route = 'website/notes/index.html';

$assert(1.0 === (float) $result['source_reports']['box_tree'][$route]['coverage'], 'The notes route has complete box-tree evidence.');
$assert(array() === array_filter($plan['assets'], static fn(array $asset): bool => str_ends_with((string) $asset['target_path'], 'site.css') && '' !== trim((string) $asset['content'])), 'The notes route retires the author stylesheet.');

$theme = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === ($write['target_path'] ?? null)) $theme = json_decode((string) $write['payload']['data'], true);
}
$assert(isset($theme['styles']) && !isset($theme['styles']['typography']['lineHeight']) && !isset($theme['styles']['typography']['fontFamily']), 'The theme root carries no typography the two pages disagree on.');
$families = array_column($theme['settings']['typography']['fontFamilies'] ?? array(), 'fontFamily', 'slug');

// Unconditional style rules the notes route delivers, selector => declarations.
$rules = array();
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    $scoped = false;
    foreach ($asset['scopes'] ?? array() as $scope) $scoped = $scoped || 'global' === $scope['kind'] || $route === ($scope['source_path'] ?? null);
    if (!$scoped) continue;
    (new CssStylesheetTransformer())->visitStyleRules((string) $asset['content'], static function (string $prelude, string $body, array $ancestors) use (&$rules): void {
        if (array() !== $ancestors) return;
        $prelude = trim($prelude);
        $rules[$prelude] = array_replace($rules[$prelude] ?? array(), SourceDeclarations::parse($body));
    });
}

$body = $rules[':root body'] ?? array();
$assert('1.75' === ($body['line-height'] ?? null), 'The body restates the leading its paragraphs inherit (' . json_encode($body) . ').');
$family = (string) ($body['font-family'] ?? '');
if (1 === preg_match('/^var\(--wp--preset--font-family--([a-z0-9-]+)\)$/', $family, $preset)) $family = (string) ($families[$preset[1]] ?? '');
$assert('Georgia,serif' === str_replace(', ', ',', $family), 'The body restates the family its content inherits (' . json_encode($body['font-family'] ?? null) . ').');

$parts = array_column($plan['template_parts'], 'canonical_block_markup', 'slug');
$header = (string) ($parts['header'] ?? '');
$assert(!str_contains($header, '<!-- wp:media-text'), 'The header row is not lowered to core/media-text.');
$assert(1 === preg_match('/<!-- wp:image \{[^\n]*?"className":"([^"]*)"/', $header, $image), 'The header logo becomes a core/image block.');
$assert(str_contains($header, '<!-- wp:buttons'), 'The header action stays a core/buttons block beside the logo.');
$logo = null;
foreach ($rules as $selector => $declarations) {
    foreach (preg_split('/\s+/', $image[1]) as $class) {
        if (str_starts_with($class, 'be-inline-geometry-') && 1 === preg_match('/\.' . preg_quote($class, '/') . '(?:\.' . preg_quote($class, '/') . ')*\s+img$/', $selector)) $logo = $declarations;
    }
}
$assert('clamp(120px,12vw,160px)' === ($logo['width'] ?? null), 'The logo image keeps the author logo width (' . json_encode($logo) . ').');

$notes = (string) (array_column($plan['pages'], 'canonical_block_markup', 'slug')['notes'] ?? '');
$linkColor = static function (string $markup, string $text) use ($rules): string {
    if (1 !== preg_match('/<a [^>]*class="([^"]*)"[^>]*>' . preg_quote($text, '/') . '</', $markup, $match)) return '';
    foreach (preg_split('/\s+/', $match[1]) as $class) {
        $color = (string) ($rules['.' . $class]['color'] ?? '');
        if ('' !== $color) return $color;
    }
    return '';
};
$assert(str_contains($linkColor($notes, 'an inline link'), 'rgb(11, 74, 73)'), 'The article link keeps its contextual colour.');
$assert(str_contains($linkColor(implode('', $parts) . $notes, 'a footer link'), 'rgb(255, 251, 247)'), 'The footer link keeps its contextual colour.');
