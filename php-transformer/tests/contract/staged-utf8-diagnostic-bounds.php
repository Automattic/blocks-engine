<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

/*
 * Diagnostics that quote source text are bounded in bytes. A bound that cuts a
 * multibyte UTF-8 character leaves an invalid string in the compiled page
 * receipt, and planDigest() then aborts the page worker with "Runtime
 * declaration payload is not serializable." Every shape below appears twice,
 * once with one extra ASCII byte in front, so a two-byte character straddles
 * each byte bound in one of the two copies.
 */
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$valid = static fn (string $value): bool => 1 === preg_match('//u', $value);
$invalidStrings = static function (mixed $value, string $path = '') use (&$invalidStrings, $valid): array {
    if (is_string($value)) return $valid($value) ? array() : array($path);
    if (!is_array($value)) return array();
    $found = array();
    foreach ($value as $key => $item) {
        if (is_string($key) && !$valid($key)) $found[] = $path . '/<key>';
        array_push($found, ...$invalidStrings($item, $path . '/' . $key));
    }
    return $found;
};

// Whole pages through the staged transport, as an importer's page workers run them.
$run = static fn (int $bytes): string => str_repeat("\xC3\xA9", intdiv($bytes, 2) + 1);
$shapes = static function (string $lead) use ($run): string {
    return '<form method="post" class="contact">'
        . '<input type="text" name="name" placeholder="' . $lead . $run(2400) . '">'
        . '<textarea name="message"></textarea>'
        . '<input type="submit" value="Send"></form>'
        . '<iframe src="https://player.example.com/embed/1" title="' . $lead . $run(400) . '"></iframe>'
        . '<canvas width="300" height="150" title="' . $lead . $run(400) . '" aria-label="' . $lead . $run(400) . '"></canvas>'
        . '<svg viewBox="0 0 10 10" aria-label="' . $lead . $run(300) . '"><foreignObject width="10" height="10"><p>' . $lead . $run(300) . '</p></foreignObject></svg>'
        . '<template class="card" data-note="' . $lead . $run(400) . '"><p>' . $lead . $run(400) . '</p></template>'
        . '<script type="text/x-template" class="' . $lead . $run(400) . '">' . $lead . $run(400) . '</script>'
        . '<div id="app' . ('' === $lead ? '1' : '2') . '" role="region" aria-label="' . $lead . $run(300) . '" data-config="' . $lead . $run(300) . '"></div>'
        . '<script>document.getElementById("app' . ('' === $lead ? '1' : '2') . '").addEventListener("click", function () { this.classList.toggle("open"); });</script>'
        . '<x-card' . ('' === $lead ? '' : '-b') . ' data-label="' . $lead . $run(400) . '">' . $lead . $run(2400) . '</x-card' . ('' === $lead ? '' : '-b') . '>'
        . '<script id="card-' . $lead . $run(400) . '">customElements.define("x-card' . ('' === $lead ? '' : '-b') . '", class extends HTMLElement {});</script>';
};
$page = '<!doctype html><html><head><meta charset="utf-8"><title>Bounds</title></head><body><main><h1>Bounds</h1>'
    . $shapes('') . $shapes('a')
    . '</main></body></html>';
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<!doctype html><html><body><main><h1>Home</h1><p><a href="bounds.html">Bounds</a></p></main></body></html>'),
    array('path' => 'bounds.html', 'content' => $page),
));
$roundTrip = static fn (array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$shared = $roundTrip((new ArtifactCompiler())->prepareShared($artifact));
$pages = $roundTrip((new ArtifactCompiler())->preparePages($artifact, $shared));
$receipts = array();
foreach ($pages as $pagePlan) {
    try {
        $receipts[] = (new ArtifactCompiler())->compilePreparedPages($shared, array($pagePlan))[$pagePlan['page_id']];
    } catch (InvalidArgumentException $error) {
        $assert(false, 'Page ' . $pagePlan['page_id'] . ' failed to compile: ' . $error->getMessage());
    }
}
$assert(2 === count($receipts), 'Both pages compile into receipts.');
$invalid = $invalidStrings($receipts);
$assert(array() === $invalid, 'Compiled page receipts hold only valid UTF-8: ' . implode(', ', array_slice($invalid, 0, 5)));
$fallbacks = array();
array_walk_recursive($receipts, static function (mixed $value, int|string $key) use (&$fallbacks): void { if ('html' === $key && is_string($value) && str_ends_with($value, '...')) $fallbacks[] = $value; });
$assert(array() !== array_filter($fallbacks, static fn (string $value): bool => 2002 === strlen($value)), 'One bounded form fallback stopped one byte short of a split character.');
$assert(array() !== array_filter($fallbacks, static fn (string $value): bool => 2003 === strlen($value)), 'One bounded form fallback kept the full 2000-byte bound.');
$plan = (new ArtifactCompiler())->compose($shared, $roundTrip($receipts))->toArray();
$assert(array() === $invalidStrings($plan), 'The composed result holds only valid UTF-8.');
json_encode($plan, JSON_THROW_ON_ERROR);

// The bounded fallback helpers keep their byte budget and stop at a character boundary.
$straddle = str_repeat('a', 1999) . "\xC3\xA9" . str_repeat('b', 10);
$assert(1 !== preg_match('//u', substr($straddle, 0, 2000)), 'The fixture splits a two-byte character at byte 2000.');
$html = SourceDom::boundedFallbackHtml($straddle);
$assert(str_repeat('a', 1999) . '...' === $html['html'] && strlen($straddle) === $html['bytes'] && true === $html['truncated'], 'Bounded fallback HTML keeps the 1999-byte prefix before a split character.');
$text = SourceDom::boundedFallbackText($straddle);
$assert(str_repeat('a', 1999) . '...' === $text['text'] && true === $text['truncated'], 'Bounded fallback text keeps the 1999-byte prefix before a split character.');
$ascii = SourceDom::boundedFallbackHtml(str_repeat('a', 2010));
$assert(str_repeat('a', 2000) . '...' === $ascii['html'], 'ASCII fallback HTML keeps the full 2000-byte bound.');

echo "staged UTF-8 diagnostic bounds: ok\n";
