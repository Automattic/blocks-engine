<?php
declare(strict_types=1);

/**
 * A marquee moves quotes across its clip: the source's empty-comment text runs
 * (`“<!---->{quote}<!---->”`) are painted one by one there, so the quote keeps
 * their boundaries. Outside such a clip empty comments still leave none.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$page = static fn (string $track): string => <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Quotes</title>
<style>.hero-quotes{overflow:hidden}.quote-marquee{width:max-content;display:flex}.moving .quote-marquee{animation:42s linear infinite quoteMarquee}
.quote-item{flex:0 0 340px;margin:0;padding:24px 36px 20px}.quote-item blockquote{margin:0 0 12px;font-size:14px;font-style:italic}
@keyframes quoteMarquee{to{transform:translate(-50%)}}</style></head>
<body><main><section class="{$track}"><div class="quote-marquee"><figure class="quote-item"><blockquote>“<!---->A rare thing: a rights argument.<!---->”</blockquote><figcaption>Publication</figcaption></figure>
<figure class="quote-item"><blockquote>“<!---->Left our conference talking.<!---->”</blockquote><figcaption>Organiser</figcaption></figure><p class="stat">80<!---->+ talks</p></div></section>
<p class="ticker">300<!---->+ stages</p></main></body></html>
HTML;

$serialized = static fn (string $track): string => (string) (( new HtmlTransformer() )->transform($page($track))->toArray()['serialized_blocks'] ?? '');
$carrier = '<mark style="background-color:transparent;color:inherit" role="none">';

$moving = $serialized('hero-quotes moving');
$assert(str_contains($moving, '“' . $carrier . 'A rare thing: a rights argument.</mark>”'), 'marquee quote keeps its mark runs apart', $moving);
$assert(str_contains($moving, '“' . $carrier . 'Left our conference talking.</mark>”'), 'every marquee quote keeps its runs', $moving);
$assert(! str_contains($moving, '<!--' . '-->') && ! str_contains($moving, '<!-- -->'), 'no source comment reaches RichText', $moving);
$assert(str_contains($moving, '80' . $carrier . '+ talks</mark>'), 'a marquee paragraph keeps its runs apart', $moving);
$assert(str_contains($moving, '300+ stages'), 'text outside the moving clip has no carrier', $moving);

$static = $serialized('hero-quotes');
$assert(str_contains($static, '“A rare thing: a rights argument.”'), 'a quote no motion carries across a clip stays one run', $static);

if ( 0 < $failures ) {
    fwrite(STDERR, "moving clip empty comment runs FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "moving clip empty comment runs passed: {$passes} assertions\n";
