<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$filter = '<svg width="0" height="0"><defs><filter id="f"><feFlood flood-color="#E04F00" result="flood"></feFlood><feComposite in="flood" in2="SourceAlpha" operator="in"></feComposite></filter></defs></svg>';
$cases = array(
    'unused definitions' => array($filter, 0),
    'hidden unused symbol sheet' => array('<svg style="display: none"><symbol id="alert-icon" viewBox="0 0 16 16"><path d="M0 0h16v16H0z"></path></symbol><symbol id="email-icon" viewBox="0 0 16 16"><rect x="1" y="1" width="14" height="14"></rect></symbol></svg>', 0),
    'referenced hidden symbol sheet' => array('<svg style="display: none"><symbol id="alert-icon"><path d="M0 0h16v16H0z"></path></symbol></svg><svg><use href="#alert-icon"></use></svg>', 1),
    'referenced sprite' => array('<svg width="0" height="0"><defs><symbol id="i"><path d="M0 0h10v10z"/></symbol></defs></svg><svg width="10" height="10"><use href="#i"/></svg>', 2),
    'CSS filter' => array('<style>.x{filter:url(#f)}</style>' . $filter, 1),
    'visible artwork' => array('<svg width="10" height="10"><path d="M0 0h10v10z"/></svg>', 1),
    'metadata and style' => array(str_replace('<defs>', '<title>Resources</title><desc>Unused</desc><style>.unused{color:red}</style><defs>', $filter), 0),
    'inline style' => array($filter . '<div style="filter:url(&quot;#f&quot;)">Filtered</div>', 1),
    'unused but box-occupying' => array(str_replace(' width="0" height="0"', '', $filter), 1),
    'zero inline style' => array(str_replace(' width="0" height="0"', ' style="width:0;height:0"', $filter), 0),
    'xlink reference' => array($filter . '<svg><use xlink:href="#f"/></svg>', 2),
);
foreach (array('mask', 'filter', 'clip-path', 'fill', 'stroke') as $attribute) {
    $cases[$attribute . ' presentation reference'] = array($filter . '<svg width="10" height="10"><path ' . $attribute . '="url(#f)" d="M0 0h10v10z"/></svg>', 2);
}

foreach ($cases as $name => [$source, $expectedSvgCount]) {
    $result = (new HtmlTransformer())->transform($source . '<h1 class="x">Title</h1><p>Body copy.</p>')->toArray();
    $markup = $result['serialized_blocks'];
    $actualSvgCount = substr_count($markup, '<!-- wp:custom/svg-artwork') + substr_count($markup, '<img ');
    if ($expectedSvgCount !== $actualSvgCount) {
        throw new RuntimeException($name . ': expected ' . $expectedSvgCount . ' SVG blocks, got ' . $actualSvgCount . "\n" . $markup);
    }
    if (0 === $expectedSvgCount && array('core/heading', 'core/paragraph') !== array_column($result['blocks'], 'blockName')) {
        throw new RuntimeException($name . ': unused SVG must emit no block.');
    }
    if (!str_contains($markup, 'Title') || !str_contains($markup, 'Body copy.')) {
        throw new RuntimeException($name . ': surrounding content must survive.');
    }
}
echo "Unused SVG definitions contract passed.\n";
