<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// The same component compiled in two documents carries different marker seeds
// and counters. Its generated block identity must agree, so every page
// references one block type and chrome containing it still matches.
$render = static fn (string $seed, int $counter): string => '<div class="grid blocks-engine-source-div-' . $seed . '-' . $counter . '"><button type="button"><span data-blocks-engine-richtext-marker="blocks-engine-richtext-' . $seed . '-' . ($counter + 20) . '">Submit</span></button></div>';
$signature = 'div(div(button(span)))';
$home = FallbackEmitter::generatedBlockIdentity($signature, $render('7a14f5cc0982', 3));
$about = FallbackEmitter::generatedBlockIdentity($signature, $render('cd205c7017f5', 9));
$assert($home === $about, 'One component compiled in two documents has one generated block identity.');

// Different content, or a different marker kind, is still a different block.
$assert($home !== FallbackEmitter::generatedBlockIdentity($signature, str_replace('Submit', 'Send', $render('cd205c7017f5', 9))), 'Different content keeps a different identity.');
$assert($home !== FallbackEmitter::generatedBlockIdentity($signature, str_replace('source-div', 'control', $render('cd205c7017f5', 9))), 'A different marker kind keeps a different identity.');
$assert($home !== FallbackEmitter::generatedBlockIdentity('div(p)', $render('7a14f5cc0982', 3)), 'A different structure keeps a different identity.');

// Only allocated markers are neutralized; other engine classes and look-alikes stay.
$assert('a blocks-engine-control b blocks-engine-source-nav blocks-engine-css-owned-grid blocks-engine-control-abc-1' === EngineMarker::withoutDocumentSeeds('a blocks-engine-control-0123456789ab-12 b blocks-engine-source-nav-0123456789ab-6 blocks-engine-css-owned-grid blocks-engine-control-abc-1'), 'Only allocated document markers lose their seed and counter.');

echo "Generated block document identity contract passed.\n";
