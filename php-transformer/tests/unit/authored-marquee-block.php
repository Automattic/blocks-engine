<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredMarqueeBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$source = '<div style="--marquee-duration: 17.5s"><p><span data-marquee-animation="left"><span><span>Protecting what matters</span></span><span aria-hidden="true">Protecting what matters</span></span></p></div>';
$result = ( new HtmlTransformer() )->transform($source)->toArray();
$block = $result['blocks'][0] ?? array();
$assert('custom/authored-marquee' === ($block['blockName'] ?? null), 'generic marquee metadata uses the authored marquee companion');
$assert('Protecting what matters' === ($block['attrs']['content'] ?? null), 'the first visible authored text remains directly editable');
$assert('left' === ($block['attrs']['direction'] ?? null) && 17.5 === ($block['attrs']['duration'] ?? null), 'direction and timing intent are preserved');
$assert(!str_contains((string) ($result['serialized_blocks'] ?? ''), '<!-- wp:html'), 'marquee content emits no raw HTML block');
$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$editor = (string) ($definition['assets']['index.js'] ?? '');
$style = (string) ($definition['assets']['style.css'] ?? '');
$assert(str_contains($editor, 'RichText') && str_contains($editor, 'authoredItems.map') && str_contains($editor, 'allowedFormats: []'), 'the companion edits each authored item without duplicating editor content');
$assert(!str_contains($editor, 'RawHTML') && str_contains($editor, 'RichText.Content') && str_contains($editor, "'aria-hidden': hidden ? true") && str_contains($editor, "inert: hidden ? ''"), 'the static save shape escapes RichText content and makes the continuous-motion duplicate inert and hidden');
$assert(str_contains($style, 'overflow-x:clip') && str_contains($style, 'max-width:100%'), 'the static stylesheet clips the duplicate track in narrow viewports');
$assert(str_contains($style, 'content--items{min-height:1lh'), 'item sequences preserve the source inline formatting context line box');
$assert(str_contains($style, 'prefers-reduced-motion:reduce') && str_contains($style, 'animation:none') && str_contains($style, 'display:none'), 'reduced motion leaves one readable static track');
$assert(str_contains((string) ($result['serialized_blocks'] ?? ''), '--blocks-engine-marquee-duration:17.5s') && str_contains((string) ($result['serialized_blocks'] ?? ''), 'data-direction="left"'), 'static block markup preserves bounded duration and authored direction');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'static marquee serialization is editor-valid');
$serialized = ( new Runtime() )->serializeBlocks(array($block));
$assert('custom/authored-marquee' === (new Runtime())->parseBlocks($serialized)[0]['blockName'], 'the companion reference persists through parse and serialize');
$escaped = ( new HtmlTransformer() )->transform('<div style="--marquee-duration: 0s"><p><span data-marquee-animation="left"><span>Tom &amp; Jerry &lt; 3</span></span></p></div>')->toArray();
$escapedMarkup = (string) (($escaped['blocks'][0]['innerHTML'] ?? ''));
$assert(str_contains($escapedMarkup, 'Tom &amp; Jerry &lt; 3') && !str_contains($escapedMarkup, 'Tom & Jerry < 3') && str_contains($escapedMarkup, 'data-direction="left"') && str_contains($escapedMarkup, '--blocks-engine-marquee-duration:1s'), 'source text is escaped while duration is deterministically bounded');

$tickerItems = '<span class="ticker-item">Small Batch</span><span class="ticker-item ticker-dot">✦</span><span class="ticker-item">Direct Trade</span><span class="ticker-item ticker-dot">✦</span>';
$tickerHtml = '<div class="ticker-band" aria-hidden="true"><div class="ticker-track">' . $tickerItems . $tickerItems . '</div></div>';
$tickerCss = '.ticker-band{overflow:hidden;background:#bf4219;padding:1rem 0}.ticker-track{display:inline-block;animation:marquee 26s linear infinite}.ticker-item{display:inline-block;padding:0 1.8rem}@keyframes marquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}';
$ticker = ( new HtmlTransformer() )->transform($tickerHtml, array( 'static_css' => $tickerCss ))->toArray();
$tickerGroup = $ticker['blocks'][0] ?? array();
$tickerBlock = $tickerGroup['innerBlocks'][0] ?? array();
$assert('core/group' === ($tickerGroup['blockName'] ?? null) && 'ticker-band' === ($tickerGroup['attrs']['className'] ?? null), 'the authored outer ticker band remains an editable native group');
$assert('custom/authored-marquee' === ($tickerBlock['blockName'] ?? null), 'a CSS-authored duplicated ticker track uses the authored marquee companion');
$assert(4 === count($tickerBlock['attrs']['items'] ?? array()) && 'Small Batch' === ($tickerBlock['attrs']['items'][0]['content'] ?? null) && 'ticker-item ticker-dot' === ($tickerBlock['attrs']['items'][1]['className'] ?? null), 'the repeated sequence deduplicates into editable styled items');
$assert('left' === ($tickerBlock['attrs']['direction'] ?? null) && 26.0 === ($tickerBlock['attrs']['duration'] ?? null), 'CSS-authored marquee direction and duration are preserved');
$assert(true === ($tickerBlock['attrs']['decorative'] ?? null) && str_contains((string) ($tickerBlock['innerHTML'] ?? ''), 'data-direction="left" aria-hidden="true"'), 'decorative source bands remain hidden from assistive technology');
$assert(str_contains((string) ($tickerBlock['innerHTML'] ?? ''), 'data-blocks-engine-richtext-marker=') && 2 === substr_count((string) ($tickerBlock['innerHTML'] ?? ''), 'Small Batch'), 'projected item selectors retain carriers across the visible and inert sequences');
$assert('pass' === ($ticker['source_reports']['wp_block_validity']['status'] ?? null), 'CSS-authored ticker serialization remains editor-valid');

$reverseHtml = '<div class="ticker-track" style="animation-direction:reverse;animation-duration:32s">' . $tickerItems . $tickerItems . '</div>';
$reverse = ( new HtmlTransformer() )->transform($reverseHtml, array( 'static_css' => $tickerCss ))->toArray();
$assert('custom/authored-marquee' === ($reverse['blocks'][0]['blockName'] ?? null) && 'right' === ($reverse['blocks'][0]['attrs']['direction'] ?? null) && 32.0 === ($reverse['blocks'][0]['attrs']['duration'] ?? null), 'inline direction and duration override the authored animation shorthand');

$resetCss = '.ticker-track{animation-duration:26s;animation:marquee 2s linear infinite}@keyframes marquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}';
$reset = ( new HtmlTransformer() )->transform('<div class="ticker-track">' . $tickerItems . $tickerItems . '</div>', array( 'static_css' => $resetCss ))->toArray();
$assert(2.0 === ($reset['blocks'][0]['attrs']['duration'] ?? null), 'animation shorthand resets an earlier longhand duration in declaration order');

$specificityHtml = '<div id="specific-ticker" class="ticker-track">' . $tickerItems . $tickerItems . '</div>';
$specificityCss = '#specific-ticker{animation:marquee 26s linear infinite}.ticker-track{animation:other 2s linear infinite}@keyframes marquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}@keyframes other{from{transform:translateX(0)}to{transform:translateX(-50%)}}';
$specificity = ( new HtmlTransformer() )->transform($specificityHtml, array( 'static_css' => $specificityCss ))->toArray();
$assert(26.0 === ($specificity['blocks'][0]['attrs']['duration'] ?? null), 'higher-specificity animation declarations beat later class rules');

$rightwardCss = '.ticker-track{animation:marquee 18s linear infinite}@keyframes marquee{from{transform:translateX(-50%)}to{transform:translateX(0)}}';
$rightward = ( new HtmlTransformer() )->transform('<div class="ticker-track">' . $tickerItems . $tickerItems . '</div>', array( 'static_css' => $rightwardCss ))->toArray();
$assert('right' === ($rightward['blocks'][0]['attrs']['direction'] ?? null), 'keyframe boundary direction is retained before animation-direction reversal');

$finiteCss = '.ticker-track{animation:marquee 18s linear infinite}@keyframes marquee{from{transform:translateX(0)}to{transform:translateX(-10px)}}';
$finite = ( new HtmlTransformer() )->transform('<div class="ticker-track">' . $tickerItems . $tickerItems . '</div>', array( 'static_css' => $finiteCss ))->toArray();
$assert('custom/authored-marquee' !== ($finite['blocks'][0]['blockName'] ?? null), 'finite-distance horizontal motion does not become a continuous marquee');

$nonRepeating = ( new HtmlTransformer() )->transform('<div class="ticker-track">' . $tickerItems . '</div>', array( 'static_css' => $tickerCss ))->toArray();
$assert('custom/authored-marquee' !== ($nonRepeating['blocks'][0]['blockName'] ?? null), 'motion identity without a repeated sequence stays on generic native lowering');
$maximumMarkup = ( new AuthoredMarqueeBlockGenerator() )->markup(array( 'content' => 'Bounded', 'direction' => 'right', 'duration' => 900 ));
$invalidDirectionMarkup = ( new AuthoredMarqueeBlockGenerator() )->markup(array( 'content' => 'Bounded', 'direction' => 'up', 'duration' => 40 ));
$assert(str_contains($maximumMarkup, 'data-direction="right"') && str_contains($maximumMarkup, '--blocks-engine-marquee-duration:600s') && str_contains($maximumMarkup, 'aria-hidden="true" inert=""') && str_contains($invalidDirectionMarkup, 'data-direction="left"'), 'the frontend markup bounds direction and duration and keeps duplicate content inaccessible');

$payload = ( new CompanionPluginPayload() )->fromBlockTypes(array(), array(), array(), array( $definition ));
$payloadBlock = $payload['blocks'][0] ?? array();
$assets = $payloadBlock['assets'] ?? array();
$isSafeCompanionAsset = static function (mixed $path, mixed $content): bool {
    if ( ! is_string($path) || ! is_scalar($content) || '' === $path || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, '../') || str_contains($path, './') ) {
        return false;
    }
    foreach ( explode('/', $path) as $segment ) {
        if ( '' === $segment || '.' === $segment || '..' === $segment || 1 !== preg_match('/^[A-Za-z0-9._-]+$/', $segment) ) {
            return false;
        }
    }
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, array( 'js', 'mjs', 'css', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot' ), true)
        && ! preg_match('/<\\?(?:php|=|[[:space:]])/i', (string) $content);
};
$assert(CompanionPluginPayload::SCHEMA === ($payload['schema'] ?? null) && array( 'index.js', 'style.css' ) === array_keys($assets), 'the complete companion payload contains the established static asset shape');
$assert(array_reduce(array_keys($assets), static fn (bool $safe, string $path): bool => $safe && $isSafeCompanionAsset($path, $assets[$path]), true), 'every generated marquee asset passes SSI static path and content constraints');
$assert(!isset($payloadBlock['render'], $payloadBlock['renderer'], $payloadBlock['block_json']['render']) && !array_filter(array_keys($assets), static fn (string $path): bool => 'php' === strtolower((string) pathinfo($path, PATHINFO_EXTENSION))), 'the generated marquee payload emits no executable PHP asset or renderer');

fwrite(STDOUT, "Authored marquee companion tests passed\n");
