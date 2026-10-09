<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL . $detail . PHP_EOL);
        exit(1);
    }
};

// A two-way HTML <-> blocks sync stores the HTML a block saves (its markup
// without delimiter comments) and converts it again on the next read. That is
// only safe when transforming the engine's own saved markup is a fixed point.
$transform = static fn (string $html): string => trim((string) ((new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'] ?? ''));
$savedHtml = static fn (string $blocks): string => trim(preg_replace('/<!--\s*\/?wp:[^>]*?-->/s', '', $blocks) ?? $blocks);

$cases = array(
    'synthetic paragraphs around loose inline links' => '<div class="cta"><a href="https://example.com/a">Start a project</a><a href="https://example.com/b">Message us</a></div>',
    'link text with an inline SVG icon' => '<div><a class="btn" href="/contact/">Contact <svg viewBox="0 0 24 24" class="icon"><path d="M5 12h14"></path></svg></a></div>',
    'button labels with an inline SVG icon' => '<div><button type="button" class="pill like"><svg viewBox="0 0 24 24" class="icon"><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676"></path></svg><span>3</span><span>Like</span></button></div>',
    'button elements whose class moves onto the save wrapper' => '<div class="tags"><button type="button" class="pill">All</button><button type="button" class="pill">Strategy</button></div>',
    'buttons with more than eight author classes' => '<div><a class="btn inline-flex items-center gap-2 rounded-full border px-5 py-2 text-sm font-semibold" href="/go/">Go</a></div>',
    'framework text-split comments in RichText' => '<div class="row"><span class="eyebrow">Brand Identity<!-- --> <span class="star">*</span></span><span class="eyebrow">Two</span></div>',
    'card links propagated into their content' => '<div class="grid"><a class="card" href="/work/one/"><div class="p-6"><span class="eyebrow">Brand</span><h3>One</h3></div></a></div>',
    'accordion headings built from disclosure toggles' => '<div class="faq"><div class="item"><h2><button type="button" aria-expanded="false" class="flex w-full justify-between"><span class="q">What do you do?</span><span class="icon"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M5 12h14"></path></svg></span></button></h2></div><div class="item"><h2><button type="button" aria-expanded="false" class="flex w-full justify-between"><span class="q">Second?</span><span class="icon"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M5 12h14"></path></svg></span></button></h2></div></div>',
    'tables keep the engine marker their saved figure carries' => '<table><tr><td>a</td><td>b</td></tr></table>',
    'styled tables keep the engine marker their saved figure carries' => '<style>.prices td{padding:8px}</style><table class="prices"><thead><tr><th>Plan</th><th>Price</th></tr></thead><tbody><tr><td>Basic</td><td>$5</td></tr></tbody></table>',
    'wrappers preserved for source-only data attributes' => '<div class="grid"><div class="reveal" data-visible="true"><div class="card"><h3>One</h3><p>Body</p></div></div><div class="reveal" data-visible="true"><div class="card"><h3>Two</h3><p>Body</p></div></div></div>',
);

$savedMarkupOnly = array( 'accordion headings built from disclosure toggles' );

foreach ($cases as $name => $source) {
    $first = $transform($source);
    $second = $transform($savedHtml($first));
    // An accordion keeps its source icon payload in block metadata, which saved
    // markup cannot carry; its fixed point is the saved markup itself.
    if (in_array($name, $savedMarkupOnly, true)) {
        $first = $savedHtml($first);
        $second = $savedHtml($second);
    }
    $assert($first === $second, 'Transforming the saved markup of ' . $name . ' is a fixed point.', "first:  {$first}\nsecond: {$second}");
}

echo 'Own output round trip tests: ' . $assertions . ' passed' . PHP_EOL;
