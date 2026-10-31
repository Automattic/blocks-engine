<?php
declare(strict_types=1);

/**
 * Builder rich text wraps a navigation label in a chain of single-child spans
 * (one per typography property). The chain collapses to one carrier span when
 * that changes nothing observable (#1944).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$wix = '<p class="font_8"><span style="color:#292929;" class="wixui-rich-text__text"><span style="letter-spacing:normal;" class="wixui-rich-text__text"><span style="font-family:futura-lt-w01-book,sans-serif;" class="wixui-rich-text__text"><span style="font-size:27px;" class="wixui-rich-text__text">Work</span></span></span></span></p>';
foreach (array(
    'wix' => array($wix, 'Work', 1),
    'bare' => array('Work', 'Work', 0),
    'empty wrappers' => array('<span><span>Work</span></span>', 'Work', 0),
    'semantic' => array('<strong>Sale</strong>', 'Sale', 0),
    'conflict' => array('<span class="shared" style="color:red!important"><span class="shared" style="color:blue;letter-spacing:normal">Work</span></span>', 'Work', 1),
    // Distinct classes stay apart so `.outer .inner` keeps matching; a nested
    // box (padding, background) is never merged away.
    'distinct classes' => array('<span class="outer"><span class="inner">Work</span></span>', 'Work', 2),
    'nested boxes' => array('<span style="padding:4px"><span style="padding:2px;background:red">Work</span></span>', 'Work', 2),
) as $case => [$markup, $text, $spanCount]) {
    // The mobile overlay and desktop list dispatch to the same item builder.
    foreach (array('', ' style="display:none"') as $hidden) {
        $source = '<header><nav' . $hidden . '><ul><li><a href="/work">' . $markup . '</a></li><li><a href="/about">About</a></li></ul></nav></header><main><p>Body</p></main>';
        $result = (new HtmlTransformer())->transform($source, array())->toArray();
        preg_match_all('/<!-- wp:navigation-link (.*?) \/-->/s', $result['serialized_blocks'], $matches);
        $label = null;
        foreach ($matches[1] as $json) {
            $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if ('/work' === ($attrs['url'] ?? '')) $label = $attrs['label'];
        }
        if (!is_string($label) || $spanCount !== substr_count($label, '<span') || $text !== strip_tags($label)) {
            throw new RuntimeException($case . ': unexpected label ' . var_export($label, true));
        }
        if ('bare' === $case || 'empty wrappers' === $case) {
            if ('Work' !== $label) throw new RuntimeException($case . ': wrapper survived');
        }
        if ('semantic' === $case && '<strong>Sale</strong>' !== $label) throw new RuntimeException('Semantic markup changed: ' . $label);
        if ('wix' === $case) {
            foreach (array('color:#292929', 'letter-spacing:normal', 'font-family:futura-lt-w01-book,sans-serif', 'font-size:27px') as $declaration) {
                if (!str_contains($label, $declaration)) throw new RuntimeException('Missing ' . $declaration . ': ' . $label);
            }
            if (1 !== substr_count($label, 'wixui-rich-text__text')) throw new RuntimeException('Duplicate Wix class: ' . $label);
        }
        if ('conflict' === $case && (str_contains($label, 'color:red') || !str_contains($label, 'color:blue') || 1 !== substr_count($label, 'shared'))) {
            throw new RuntimeException('Class order or inner declaration precedence lost: ' . $label);
        }
    }
}
echo "Navigation labels compact presentation chains and retain semantic markup\n";
