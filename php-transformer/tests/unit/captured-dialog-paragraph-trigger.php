<?php
declare(strict_types=1);

/**
 * A paragraph whose whole content is a captured dialog trigger converts the
 * trigger natively, as the same trigger does in a `<div>`.
 *
 * The trigger carries `aria-controls`, so RichText cannot lower it into an
 * inline mark, and the paragraph path used to preserve the paragraph as
 * core/html. The container path already turns the trigger into an operable
 * button that keeps its dialog binding; the paragraph keeps its box as a
 * group. Passive inline wrappers (a tracking `<span>`) are crossed; a styled
 * wrapper, or text beside the button, still declines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $context = '') use (&$failures): void {
    if ($condition) return;
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n" . ('' !== $context ? substr($context, 0, 1600) . "\n" : ''));
};

$runtime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$trigger = static fn (string $key, string $label, string $class): string => '<button class="' . $class . '" data-blocks-engine-capture-disclosure-label="' . $label . '" data-blocks-engine-capture-dialog-trigger="' . $key . '" aria-controls="' . $key . '" aria-expanded="false" aria-haspopup="dialog">' . $label . '</button>';
$panel = static fn (string $key, string $body): string => '<div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden id="' . $key . '" data-blocks-engine-capture-dialog-panel="' . $key . '">' . $body . '</div>';
$compile = static function (string $header, string $byline) use ($runtime, $panel): string {
    $html = '<!doctype html><html><head><title>Neutral</title>'
        . '<style>.pill{background:#111;color:#fff;border:0;border-radius:99px;padding:8px 16px}.link{background:none;border:0;color:#1a8917;padding:0}</style>'
        . '<script data-blocks-engine-capture-disclosure-runtime="true">' . $runtime . '</script></head><body>'
        . '<div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope="">'
        . '<header class="bar">' . $header . $panel('join-panel', '<h2>Join</h2><p>Create an account.</p>') . '</header>'
        . '<main><h1>Title</h1>' . $byline . $panel('follow-panel', '<p>Follow this writer.</p>') . '</main></div></body></html>';

    return (string) ((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'] ?? '');
};
$triggerButtons = static function (string $markup): array {
    preg_match_all('/<button\b[^>]*data-blocks-engine-capture-dialog-trigger="([^"]+)"[^>]*>/', $markup, $matches);
    sort($matches[1]);
    return $matches[1];
};

// The reduced shape: a header CTA paragraph wrapping its trigger in a tracking
// span, and a byline paragraph holding a bare trigger.
$paragraphs = $compile(
    '<p class="cta"><span data-track="nav">' . $trigger('join-panel', 'Sign up', 'pill') . '</span></p>',
    '<p class="byline">' . $trigger('follow-panel', 'Follow', 'link') . '</p>'
);
$assert(0 === substr_count($paragraphs, '<!-- wp:html'), 'a paragraph holding only a dialog trigger emits no core/html', $paragraphs);
$assert(array('follow-panel', 'join-panel') === $triggerButtons($paragraphs), 'both triggers stay operable buttons bound to their dialogs', $paragraphs);
$assert(str_contains($paragraphs, 'wp-block-group cta') && str_contains($paragraphs, 'wp-block-group byline'), 'each paragraph keeps its box as a group carrying its classes', $paragraphs);
$assert(str_contains($paragraphs, '>Sign up</button>') && str_contains($paragraphs, '>Follow</button>'), 'the trigger labels are kept', $paragraphs);

// The same bare trigger in a div converts to the same button.
$divs = $compile('', '<div class="byline">' . $trigger('follow-panel', 'Follow', 'link') . '</div>');
preg_match('/<button\b[^>]*follow-panel[^>]*>/', $divs, $divButton);
preg_match('/<button\b[^>]*follow-panel[^>]*>/', $paragraphs, $paragraphButton);
$assert(isset($divButton[0], $paragraphButton[0]) && $divButton[0] === $paragraphButton[0], 'the paragraph trigger matches the div trigger', ($divButton[0] ?? '') . "\n" . ($paragraphButton[0] ?? ''));

// Declines: text beside the control, or a styled wrapper whose presentation
// the group would drop, keep the existing preservation.
$transform = static fn (string $html): string => (string) ((new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'] ?? '');
$assert(str_contains($transform('<p>Read more <button type="button" aria-controls="x" aria-expanded="false">here</button></p><div id="x" hidden>More</div>'), '<!-- wp:html'), 'text beside a behavior button is not split out of its paragraph');
$assert(str_contains($transform('<p><span class="badge"><button type="button" aria-controls="x" aria-expanded="false">Open</button></span></p><div id="x" hidden>More</div>'), '<!-- wp:html'), 'a styled inline wrapper is not dropped');

if (0 !== $failures) exit(1);
echo "captured-dialog-paragraph-trigger: ok\n";
