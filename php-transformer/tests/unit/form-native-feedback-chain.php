<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Validation text is not a second component of a composite control. An empty
// capture style attribute must not force otherwise class-only boxes into shells.
foreach (array(array('', 1), array('', 2), array(' style=""', 1), array(' style=""', 2)) as [$style, $feedbackCount]) {
    $html = '<style>textarea{background:#fff;border:1px solid #ccc;padding:12px}.column{width:50%}.field{margin-bottom:1rem}.invalid-feedback{display:none}</style><form><div class="column"' . $style . '><div class="field">'
        . '<textarea id="message" name="message" placeholder="Message" required></textarea>'
        . str_repeat('<div class="invalid-feedback">A message is required.</div>', $feedbackCount)
        . '</div></div><button type="submit">Send</button></form>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = $result['serialized_blocks'];
    if (str_contains($markup, '/layout-shell')) {
        throw new RuntimeException('A control with feedback must retain native wrapper Groups.');
    }
    $runtime = new Runtime();
    $parsed = $runtime->parseBlocks($markup);
    $checkFrames = static function (array $blocks) use (&$checkFrames): void {
        foreach ($blocks as $block) {
            $classes = explode(' ', $block['attrs']['className'] ?? '');
            if (in_array('column', $classes, true) || in_array('field', $classes, true)) {
                if ('core/group' !== $block['blockName']
                    || !in_array('blocks-engine-css-owned-flow', $classes, true)
                    || isset($block['attrs']['layout'])) {
                    throw new RuntimeException('Native feedback frames must not acquire WordPress flow spacing.');
                }
            }
            $checkFrames($block['innerBlocks'] ?? array());
        }
    };
    $checkFrames($parsed);
    $saved = $runtime->serializeBlocks($parsed);
    $dom = new DOMDocument();
    @$dom->loadHTML($saved);
    $xpath = new DOMXPath($dom);
    $field = '//div[contains(concat(" ",normalize-space(@class)," ")," column ")]/div[contains(concat(" ",normalize-space(@class)," ")," field ")]';
    if (1 !== $xpath->query($field . '/textarea[@id="message"]')->length
        || $feedbackCount !== $xpath->query($field . '/*[contains(@class,"invalid-feedback")]')->length
        || 1 !== $xpath->query('//textarea')->length) {
        throw new RuntimeException('Editor save must retain source edges, feedback and exactly one control.');
    }
}
echo "Native form feedback chain passed\n";
