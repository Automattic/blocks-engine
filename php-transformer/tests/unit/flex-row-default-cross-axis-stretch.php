<?php
declare(strict_types=1);

/**
 * A flex row without `align-items` keeps its items top-aligned and stretched.
 *
 * The source's `align-items` is the initial `normal`, which flex items treat
 * as `stretch`. Core's horizontal flex layout centres items on the cross axis
 * unless `verticalAlignment` says otherwise, so the converted row states
 * `stretch`; an authored `align-items:center` keeps core's default
 * (Automattic/blocks-engine#5256).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    }
};

$footerLayout = static function (string $footerCss): ?array {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Flex default align</title><style>'
        . $footerCss
        . '</style></head><body><main><p>Body</p></main><footer><span>Line one<br><small>Line two</small></span><span>Right side</span></footer></body></html>';
    $markup = (string) (( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $html ),
    ))->toArray()['serialized_blocks'] ?? '');
    if ( 1 !== preg_match('/<!-- wp:group (\{[^>]*"tagName":"footer"[^>]*\}) -->/', $markup, $match) ) {
        return null;
    }
    $attrs = json_decode($match[1], true);

    return is_array($attrs['layout'] ?? null) ? $attrs['layout'] : null;
};

$default = $footerLayout('footer{display:flex;justify-content:space-between;padding:30px;line-height:1.9}');
$assert('flex' === ($default['type'] ?? null), 'default-align row converts to a core flex layout', (string) json_encode($default));
$assert('stretch' === ($default['verticalAlignment'] ?? null), 'default-align row keeps the source stretch cross-axis alignment', (string) json_encode($default));

$centered = $footerLayout('footer{display:flex;justify-content:space-between;align-items:center;padding:30px}');
$assert('flex' === ($centered['type'] ?? null), 'centered row converts to a core flex layout', (string) json_encode($centered));
$assert(! isset($centered['verticalAlignment']), 'centered row keeps core centre alignment', (string) json_encode($centered));

// core/navigation and core/social-links own their item alignment and opt out
// of the layout's verticalAlignment; core/group keeps it.
$runtime = new Runtime();
$stretchRow = array( 'layout' => array( 'type' => 'flex', 'verticalAlignment' => 'stretch' ) );
$assert(array( 'type' => 'flex' ) === ($runtime->normalizeBlockSupportAttributes('core/navigation', $stretchRow)['attrs']['layout'] ?? null), 'navigation drops the vertical alignment it does not allow');
$assert($stretchRow === $runtime->normalizeBlockSupportAttributes('core/group', $stretchRow)['attrs'], 'group keeps its vertical alignment');

if ( 0 < $failures ) {
    fwrite(STDERR, "flex row default cross-axis stretch FAILED: {$failures} failures\n");
    exit(1);
}
echo "flex row default cross-axis stretch passed\n";
