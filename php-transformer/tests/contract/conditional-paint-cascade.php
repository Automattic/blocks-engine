<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #4558: conditional groups do not add selector specificity.
$fixture = <<<'HTML'
<style>
a { background: transparent }
#main :where(.root) { --b: var(--a); --a: #E4F2D4 }
#main :where(.btn) { background-color: var(--b); padding: 8px 16px; border-radius: 99px }
EXTRA_RULE
</style>
<div id="main"><div class="root" style="--p: z1"><header><div class="w-p">
  <a class="btn button" href="#c">Contact Us</a>
</div></header><main><p>Hi</p></main></div></div>
HTML;
$rule = '.w-p .button { background-color: transparent }';
$cases = array(
    'none' => '',
    'plain' => $rule,
    'media' => '@media (min-width: 1px) { ' . $rule . ' }',
    'container style query' => '@container style(--p: z1) { ' . $rule . ' }',
);
$failed = false;
foreach ($cases as $label => $extra) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => str_replace('EXTRA_RULE', $extra, $fixture)),
    ))->toArray();
    preg_match_all('/<!-- wp:button (\{.*?\}) -->/', (string) ($result['serialized_blocks'] ?? ''), $matches);
    $attrs = json_decode($matches[1][0] ?? '{}', true);
    if (1 !== count($matches[1]) || '#E4F2D4' !== ($attrs['style']['color']['background'] ?? null)) {
        fwrite(STDERR, "FAIL: {$label}: core/button background must be #E4F2D4\n");
        $failed = true;
    }
}
exit($failed ? 1 : 0);
