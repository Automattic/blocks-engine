<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$fixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/navigation-item-conditions.html');
foreach (array(':first-child' => array(' ', '/', '/'), ':last-child' => array('/', '/', ' '), ':nth-child(2)' => array('/', ' ', '/'), ':not(:first-child)' => array('/', ' ', ' '), ':not(:nth-child(2))' => array(' ', '/', ' '), ':nth-child(odd):not(:last-child)' => array(' ', '/', '/')) as $condition => $expected) {
    $html = str_replace(':first-child', $condition, $fixture);
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toWordPressSitePlanView()['wordpress_site_plan'];
    $css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $markup = $plan['pages'][0]['canonical_block_markup'];
    $blocks = (new Runtime())->parseBlocks($markup);
    $navigation = $blocks[0];
    $navigation['innerBlocks'] = (new Runtime())->parseBlocks($plan['menus'][0]['block_markup']);
    if ('core/navigation' !== $navigation['blockName'] || 3 !== count($navigation['innerBlocks'])) throw new RuntimeException('The fixture must lower to three native navigation items.');
    // Core's dynamic navigation renderer owns these wrappers. Render its item
    // classes from the compiled attributes rather than copying source markup;
    // Runtime's standalone renderer only supports static blocks.
    $rendered = '<nav class="wp-block-navigation ' . htmlspecialchars($navigation['attrs']['className']) . '"><ul class="wp-block-navigation__container">';
    foreach ($navigation['innerBlocks'] as $item) {
        $rendered .= '<li class="wp-block-navigation-item ' . htmlspecialchars($item['attrs']['className']) . '"><a class="wp-block-navigation-item__content">' . htmlspecialchars($item['attrs']['label']) . '</a></li>';
    }
    $rendered .= '</ul></nav>';
    $dom = new DOMDocument();
    @$dom->loadHTML($rendered);
    $contents = array();
    foreach ($dom->getElementsByTagName('li') as $item) {
        $content = null;
        $weight = -1;
        foreach (preg_split('/(?<=\})/', $css) as $rule) {
            if (!preg_match('/^\s*([^{}@]+)\{([^{}]*)\}$/', $rule, $parts)) continue;
            $declarations = array_column(CssRuleAnalyzer::declarations($parts[2], array('content')), 'value', 'name');
            if (!isset($declarations['content'])) continue;
            foreach (CssStylesheetTransformer::splitSelectorList($parts[1]) ?? array() as $selector) {
                $host = CssSelectorMatcher::pseudoElementHost($selector);
                if (null === $host || !CssSelectorMatcher::matches($item, $host['parsed'])['matches']) continue;
                $specificity = CssSelectorMatcher::specificity($host['parsed']);
                if ($specificity >= $weight) {
                    $content = trim($declarations['content'], '"\'');
                    $weight = $specificity;
                }
            }
        }
        $contents[] = $content;
    }
    if ($expected !== $contents) throw new RuntimeException($condition . ' must retain source before-content per rendered item: ' . json_encode($contents));
}
echo "navigation item conditions passed\n";
