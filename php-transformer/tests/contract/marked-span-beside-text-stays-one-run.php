<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A block wrapper holding text around one styled inline span is one line box.
// It must lower to one paragraph whose text keeps the spaces around the span,
// with or without framework text-split comments (React's `<!---->`).
foreach (array(
    'plain' => 'R$ 410 <span class="unit">a</span> R$ 500',
    'framework markers' => 'R$ <!---->410<!----> <span class="unit">a</span> R$ <!---->500',
) as $case => $inner) {
    $result = (new HtmlTransformer())->transform(
        '<style>.price{font-size:36px;font-weight:800}.unit{font-size:18px;color:#94a3b8}</style>'
        . '<div class="card"><span>Estimate:</span><div class="price">' . $inner . '</div></div>'
    )->toArray();
    $paragraphs = array();
    $walk = static function (array $blocks) use (&$walk, &$paragraphs): void {
        foreach ($blocks as $block) {
            if ('core/paragraph' === ($block['blockName'] ?? '')) {
                $paragraphs[] = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) ($block['attrs']['content'] ?? '')))));
            }
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($result['blocks'] ?? array());
    if (! in_array('R$ 410 a R$ 500', $paragraphs, true)) {
        throw new RuntimeException($case . ': price run must stay one paragraph, got ' . json_encode($paragraphs));
    }
}
echo "marked-span-beside-text-stays-one-run: ok\n";
