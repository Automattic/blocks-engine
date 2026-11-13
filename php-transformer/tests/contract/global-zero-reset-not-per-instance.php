<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

foreach (array(false, true) as $override) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            's.css' => '*,:after,:before{box-sizing:border-box;border:0 solid;margin:0;padding:0}.card{display:grid;gap:1rem}',
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><main><section class="card"><div class="meta">Guide</div><ul><li'
                . ($override ? ' style="padding-left:1rem"' : '')
                . '>One</li><li>Two</li></ul><p>Body</p></section></main></body></html>',
        ),
    ))->toArray();
    $markup = $result['serialized_blocks'] ?? '';
    preg_match_all('/<!-- wp:([a-z0-9\/-]+)(?: (\{.*?\}))? (?:\/)?-->/', $markup, $comments, PREG_SET_ORDER);
    $overrides = 0;
    foreach ($comments as $comment) {
        $attrs = json_decode($comment[2] ?? '{}', true);
        foreach (array('padding', 'margin') as $box) {
            $sides = $attrs['style']['spacing'][$box] ?? array();
            $assert(array() === $sides || array() !== array_filter($sides, static fn(string $value): bool => '0' !== $value && '0px' !== $value), $comment[1] . ' has no all-zero ' . $box);
        }
        if ('list-item' === $comment[1] && '1rem' === ($attrs['style']['spacing']['padding']['left'] ?? null)) ++$overrides;
    }
    $assert(count($comments) > 0, 'Canonical blocks were inspected');
    $assert(($override ? 1 : 0) === $overrides, 'Only the differing list item retains padding-left');
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    foreach (array('group', 'list-item') as $block) {
        foreach (array('padding', 'margin') as $box) {
            // A box zeroed on every side shares `{box:0}`; a partial box shares a side-specific rule.
            $assert(1 === preg_match('/body \.(blocks-engine-zero-core-' . $block . '-' . $box . '(?:-[trbl]+)?)\.\1\{' . $box . '(?:-[a-z]+)?:0[;}]/', $css), $block . ' shared ' . $box . ' reset beats core layout defaults');
        }
    }
}
fwrite(STDOUT, "Global zero reset not per instance contract passed\n");
