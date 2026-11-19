<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #6107: a block box whose only content is one inline-level button
// anchor is the line that button sits on. core/buttons is that line, so the
// box becomes the core/buttons itself instead of a Group wrapping one.
$assert = static function (bool $ok, string $message, string $markup = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' === $markup ? '' : "\n" . $markup));
    echo "PASS: $message\n";
};
$compile = static fn (string $html): string => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><style>'
        . '*,:after,:before{border:0 solid #e5e7eb}'
        . '.cta-row{margin-top:2.5rem;text-align:center}'
        . '.boxed{margin-top:2.5rem;text-align:center;max-width:40rem;margin-left:auto;margin-right:auto;padding:0 1.25rem}'
        . '.cta{display:inline-flex;align-items:center;height:2.25rem;padding:.5rem 1.5rem;border-radius:10px;background-color:#1c362b;color:#faf8f5}'
        . '</style></head><body><main>' . $html . '</main></body></html>'),
))->toArray()['serialized_blocks'];

$markup = $compile('<p>Menu</p><div class="cta-row"><a class="cta" href="/booking/">Book School Catering</a></div>');
$assert(!str_contains($markup, '<!-- wp:group {"className":"cta-row'), 'The line box emits no Group.', $markup);
$assert(1 === preg_match('/<!-- wp:buttons (\{[^\n]*?\}) -->/', $markup, $match), 'A core/buttons stands in for the line box.', $markup);
$attrs = json_decode($match[1], true);
$assert(str_contains((string) ($attrs['className'] ?? ''), 'cta-row'), 'The core/buttons carries the line box identity.', $markup);
$assert('center' === ($attrs['layout']['justifyContent'] ?? null), "The line box's text-align centers the button row.", $markup);
$assert(1 === substr_count($markup, '<!-- wp:button ') && str_contains($markup, 'href="/booking/"') && str_contains($markup, 'Book School Catering'), 'The anchor stays one editable button.', $markup);

// A box with its own width and horizontal placement is more than the line.
$boxed = $compile('<p>Menu</p><div class="boxed"><a class="cta" href="/booking/">Book School Catering</a></div>');
$assert(str_contains($boxed, '<!-- wp:group {"className":"boxed'), 'A sized box keeps its Group.', $boxed);
