<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

// Lucide's runtime leaves its non-standard `size` prop on every rendered icon.
// SVG does not define it and browsers ignore it, so it must not reject the icon.
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" size="18"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>';
$cases = array(
    'bare' => $svg,
    'inside link' => '<a href="/x" class="btn">Go ' . $svg . '</a>',
);

foreach ( $cases as $label => $html ) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $json = (string) json_encode($result['blocks'], JSON_UNESCAPED_SLASHES);
    $assert(! str_contains($json, 'core/html'), "{$label}: no core/html");
    $assert(! preg_match('/<svg\b[^>]*\ssize=/i', $json), "{$label}: block markup svg has no size attribute");

    $svgs = array();
    foreach ( $result['assets'] ?? array() as $asset ) {
        $content = (string) ($asset['content'] ?? '');
        if ( str_contains($content, '<svg') ) {
            $svgs[] = $content;
        }
    }
    preg_match_all('/<svg\b[^>]*>/i', str_replace('\\"', '"', $json), $inline);
    $roots = array_merge($inline[0], array_map(static fn (string $content): string => preg_match('/<svg\b[^>]*>/i', $content, $match) ? $match[0] : '', $svgs));
    $roots = array_values(array_filter($roots));
    $assert(array() !== $roots, "{$label}: an output svg exists");
    foreach ( $roots as $root ) {
        $assert(! preg_match('/\ssize=/i', $root), "{$label}: output svg has no size attribute");
        $assert(str_contains($root, 'width="24"') && str_contains($root, 'height="24"'), "{$label}: width/height kept");
        $assert(1 === preg_match('/\sviewBox="0 0 24 24"/i', $root), "{$label}: viewBox kept");
        $assert(1 === preg_match('/\sstroke="[^"]+"/', $root) && str_contains($root, 'stroke-width="2"'), "{$label}: stroke kept");
    }
}

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "svg-nonstandard-size-attribute: passed\n");
