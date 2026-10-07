<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = require dirname(__DIR__) . '/fixtures/conditional-empty-visual-boundary.php';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$assert(1 === preg_match('/class="[^"]*\bpaint\b/', $markup), 'Conditional background boundary must survive native conversion');
$assert(1 === preg_match('/class="[^"]*\bdivider\b/', $markup), 'Conditional divider geometry must survive native conversion');
$assert(1 === preg_match('/class="[^"]*\bdesktop-paint\b/', $markup), 'Conditional background-image boundary must survive native conversion');
$assert(0 === preg_match('/class="[^"]*\binert\b/', $markup), 'Inert empty source container remains omitted');
$assert(0 === preg_match('/class="[^"]*\bzero-box\b/', $markup), 'Conditional zero/reset declarations do not prove a visual boundary');
$assert(0 === preg_match('/class="[^"]*\bstate-only\b/', $markup), 'Interaction-only paint does not prove a resting boundary');
$assert(! str_contains($markup, '<!-- wp:html'), 'Proven empty paint remains native editable blocks');
echo "Conditional empty visual boundary passed (7 assertions)\n";
