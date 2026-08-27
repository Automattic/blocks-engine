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
$assert(str_contains($editor, 'RichText') && 1 === substr_count($editor, 'createElement( RichText,') && str_contains($editor, 'allowedFormats: []'), 'the companion edits one plain-text value without duplicating editor content');
$assert(!str_contains($editor, 'RawHTML') && str_contains($editor, 'RichText.Content') && str_contains($editor, "'aria-hidden': true") && str_contains($editor, "inert: ''"), 'the static save shape escapes RichText content and makes the continuous-motion duplicate inert and hidden');
$assert(str_contains($style, 'overflow-x:clip') && str_contains($style, 'max-width:100%'), 'the static stylesheet clips the duplicate track in narrow viewports');
$assert(str_contains($style, 'prefers-reduced-motion:reduce') && str_contains($style, 'animation:none') && str_contains($style, 'display:none'), 'reduced motion leaves one readable static track');
$assert(str_contains((string) ($result['serialized_blocks'] ?? ''), '--blocks-engine-marquee-duration:17.5s') && str_contains((string) ($result['serialized_blocks'] ?? ''), 'data-direction="left"'), 'static block markup preserves bounded duration and authored direction');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'static marquee serialization is editor-valid');
$serialized = ( new Runtime() )->serializeBlocks(array($block));
$assert('custom/authored-marquee' === (new Runtime())->parseBlocks($serialized)[0]['blockName'], 'the companion reference persists through parse and serialize');
$escaped = ( new HtmlTransformer() )->transform('<div style="--marquee-duration: 0s"><p><span data-marquee-animation="left"><span>Tom &amp; Jerry &lt; 3</span></span></p></div>')->toArray();
$escapedMarkup = (string) (($escaped['blocks'][0]['innerHTML'] ?? ''));
$assert(str_contains($escapedMarkup, 'Tom &amp; Jerry &lt; 3') && !str_contains($escapedMarkup, 'Tom & Jerry < 3') && str_contains($escapedMarkup, 'data-direction="left"') && str_contains($escapedMarkup, '--blocks-engine-marquee-duration:1s'), 'source text is escaped while duration is deterministically bounded');
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
