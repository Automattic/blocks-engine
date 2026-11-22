<?php
declare(strict_types=1);

/**
 * A button an author names apart from its content renders one caption and
 * announces another (#7363): `<button aria-label="Request email updates">↗</button>`
 * shows an arrow. The control metadata keeps `text` as the accessible name
 * and publishes the rendered caption and the name separately, so a consumer
 * does not paint the aria-label as the visible caption.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    }
};
$submit = static function (string $html): array {
    $controls = (new HtmlTransformer())->transform($html)->toArray()['fallbacks'][0]['controls'] ?? array();
    return current(array_filter($controls, static fn(array $control): bool => in_array($control['type'] ?? '', array( 'submit', 'button' ), true))) ?: array();
};

$icon = $submit('<style>.sr{position:absolute;clip:rect(0 0 0 0)}</style><form class="newsletter-form"><label class="sr" for="e">Email address</label><input id="e" name="email" type="email" required><button aria-label="Request email updates">↗</button></form>');
$assert('↗' === ($icon['visible_text'] ?? null), 'the icon button publishes the glyph it renders as its caption', json_encode($icon));
$assert('Request email updates' === ($icon['accessible_name'] ?? null), 'the icon button publishes its aria-label as its accessible name', json_encode($icon));
$assert('Request email updates' === ($icon['text'] ?? null), 'text keeps the accessible name for existing consumers', json_encode($icon));

$hiddenGlyph = $submit('<form><input name="email" type="email"><button type="submit" aria-label="Send now"><span aria-hidden="true">×</span><span hidden>unused</span></button></form>');
$assert('×' === ($hiddenGlyph['visible_text'] ?? null) && 'Send now' === ($hiddenGlyph['accessible_name'] ?? null), 'an aria-hidden glyph is painted, a hidden span is not', json_encode($hiddenGlyph));

$plain = $submit('<form><input name="email" type="email"><button type="submit">Subscribe</button></form>');
$assert('Subscribe' === ($plain['text'] ?? null) && ! isset($plain['visible_text']) && ! isset($plain['accessible_name']), 'a button whose caption is its name publishes text alone', json_encode($plain));

$same = $submit('<form><input name="email" type="email"><button type="submit" aria-label="Subscribe"> Subscribe </button></form>');
$assert(! isset($same['visible_text']) && ! isset($same['accessible_name']), 'an aria-label equal to the caption adds nothing', json_encode($same));

$labelled = $submit('<style>.go{font-weight:700}</style><form><input name="email" type="email"><button type="submit" aria-label="Join the list"><span class="go">Go</span></button></form>');
$assert('go' === ($labelled['label_classes'] ?? null), 'the caption span is reported as the label element of a named button', json_encode($labelled));

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "form-button-visible-caption-accessible-name: ok\n");
