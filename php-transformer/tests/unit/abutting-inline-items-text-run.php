<?php
declare(strict_types=1);

// Classed inline items the author's stylesheet sets side by side with nothing
// between them read as one text run ("New York(212) 555-0123"). A paragraph
// per item breaks the line between them: every <p> boundary is a line break in
// the page text, so the imported page reads more characters than the source
// (#5860). The run stays one paragraph that keeps each item's class.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fail = static function (string $message, string $markup): void { fwrite(STDERR, "FAIL: {$message}\n{$markup}\n"); exit(1); };
$page = static fn(string $css, string $info): string => '<!doctype html><html><head><style>' . $css . '</style></head><body><main><p>Body.</p></main><footer id="footer"><div class="footer-inner">' . $info . '</div></footer></body></html>';
$info = '<div id="siteInfo"><span class="site-address">459 Broadway, New York</span><span rel="tel" class="site-phone">(212) 555-0123</span><a href="mailto:hello@example.com" class="site-email"><span>hello@example.com</span></a></div>';
$css = '.site-phone,.site-email{white-space:nowrap}.site-address+.site-phone,.site-phone+.site-email{margin-left:1em}@media (max-width:640px){.site-phone,.site-email{display:block;margin-left:0 !important}}';

$markup = (string) (new HtmlTransformer())->transform($page($css, $info))->toArray()['serialized_blocks'];
if (1 !== preg_match('/<p id="siteInfo"[^>]*>(.*?)<\/p>/s', $markup, $run)) $fail('the abutting items stay one paragraph', $markup);
if (!str_contains($run[0], 'blocks-engine-lowered-paragraph')) $fail('a source div does not acquire paragraph margins', $markup);
foreach (array('site-address', 'site-phone', 'site-email') as $class) {
    if (!str_contains($run[1], 'class="' . $class)) $fail("the run keeps the {$class} item's class", $markup);
}
if (!str_contains(strip_tags($run[1]), '459 Broadway, New York(212) 555-0123hello@example.com')) $fail('the run reads as the source text, with no break between items', $markup);
if (1 === preg_match('/<p[^>]*class="[^"]*site-(?:address|phone|email)/', $markup)) $fail('no item becomes its own paragraph', $markup);

// Whitespace already separates items in the source text: they keep their own
// paragraphs, as do unstyled items whose classes name their structure.
$spaced = (string) (new HtmlTransformer())->transform($page($css, str_replace(array('</span><span', '</span><a'), array('</span> <span', '</span> <a'), $info)))->toArray()['serialized_blocks'];
if (!str_contains($spaced, '<p class="site-address blocks-engine-synthetic-paragraph">')) $fail('whitespace-separated items keep their own paragraphs', $spaced);
$unstyled = (string) (new HtmlTransformer())->transform($page('', $info))->toArray()['serialized_blocks'];
if (!str_contains($unstyled, '<p class="site-address blocks-engine-synthetic-paragraph">')) $fail('unstyled items keep their own paragraphs', $unstyled);

// Captured engine annotations are not runtime state, but an author counter
// is: its item must keep its independent paragraph/source identity. Combining
// it with the unit adds a wrapper paragraph and turns the counter into a mark.
$counter = '<div class="stat"><div class="num"><span class="value" data-count="1271">0</span><span class="unit">M km</span></div></div>';
$counterCss = '.stat .num{font-size:2rem}.stat .num .unit{font-size:.9rem;margin-left:.25rem}';
$counted = (string) (new HtmlTransformer())->transform($page($counterCss, $counter))->toArray()['serialized_blocks'];
if (!preg_match('/<p[^>]*class="[^"]*value[^\"]*"[^>]*>0<\/p>/', $counted)) $fail('author counter retains its independent paragraph', $counted);

fwrite(STDOUT, "abutting inline items text run tests: passed\n");
