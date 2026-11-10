<?php
/**
 * A print stylesheet's rules never paint on screen. Hiding everything for
 * print (`@media print{body *{visibility:hidden}}`) must leave the screen
 * plan identical to the same page without that rule, including inline
 * SVG icons inside a button link.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>';
$html = '<!doctype html><html><head><title>t</title><link rel="stylesheet" href="s.css"></head><body><main><p>Intro.</p><a class="button" href="/next/">Open the calculator ' . $svg . '</a></main></body></html>';
$button = '.button{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1rem;font-weight:800;background:#f50;color:#fff;border:1px solid #0000}';
$plan = static fn (string $css): string => json_encode(
    (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 's.css' => $css)))->toArray()['source_reports']['wordpress_site_plan']['pages'] ?? null,
    JSON_THROW_ON_ERROR
);
$icons = static fn (string $serialized): int => substr_count($serialized, '<svg') + substr_count($serialized, '<img');

$screen = $plan($button);
$withPrint = $plan('@media print{body *{visibility:hidden!important}}' . $button);
if ( 0 === $icons($screen) || $icons($withPrint) !== $icons($screen) ) {
    fwrite(STDERR, sprintf("FAIL: icon carriers without print rule %d, with print rule %d\n", $icons($screen), $icons($withPrint)));
    exit(1);
}
echo "print media rules keep screen content: ok\n";
