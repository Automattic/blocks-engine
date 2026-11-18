<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceContentConservationReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Issue #6600: the focus-revealed skip link is intentionally omitted because
// Core renders its own, so content conservation must not report it as dropped.

$html = '<!DOCTYPE html><html lang="en"><head><title>Skip</title>'
    . '<style>.visually-hidden-focusable:not(:focus):not(:focus-within){position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}</style>'
    . '</head><body><a class="visually-hidden-focusable" href="#main">Skip to content</a>'
    . '<main id="main"><h1>Welcome</h1><p>Page copy.</p></main></body></html>';

$result = ( new HtmlTransformer() )->transform($html)->toArray();
$dropped = array_values(array_filter(
    $result['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => SourceContentConservationReporter::CODE === ( $diagnostic['code'] ?? null )
        && in_array('Skip to content', (array) ( $diagnostic['lost_text'] ?? array() ), true)
));

if ( array() !== $dropped ) {
    throw new RuntimeException('The omitted focus-revealed skip link is reported as html_content_dropped: ' . json_encode($dropped, JSON_UNESCAPED_SLASHES));
}
if ( str_contains((string) $result['serialized_blocks'], 'Skip to content') ) {
    throw new RuntimeException('The focus-revealed skip link is still omitted from the blocks');
}

echo "focus-revealed skip link conservation: ok\n";
