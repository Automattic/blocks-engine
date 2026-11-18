<?php
declare(strict_types=1);

// A form provider renders a declared submit as `core/button`, outside every
// page's markup. The theme button element reaches it like any other button,
// so the element keeps only the fields that submit's stated presentation
// also carries: a source submit whose cascade never declared letter-spacing
// must not take the dominant link buttons' tracking.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fixture = require dirname(__DIR__) . '/fixtures/design-system/buttons.php';
$form = '<form method="post" action="https://example.com/subscribe"><label for="email">Email</label><input id="email" type="email" name="email">'
    . '<button type="submit" class="signup"><span class="signup-label">Sign Up</span></button></form>';
foreach ($fixture['files'] as &$html) {
    $html = str_replace(
        array('</style>', '</body>'),
        array('.signup{background:#262626;color:#fff;border:1px solid #262626;padding:1em 2.25em;font-size:15px;font-weight:400;text-transform:uppercase;border-radius:0}</style>', $form . '</body>'),
        $html
    );
}
unset($html);

$plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
$theme = array();
$markup = '';
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    elseif (is_string($write['payload']['data'] ?? null)) $markup .= $write['payload']['data'];
}
$submits = array();
foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] as $entity) {
        foreach ($entity['controls'] as $control) if ('submit' === ($control['type'] ?? null)) $submits[] = $control;
    }
}
$assert(array() !== $submits, 'the form is declared for a provider with its submit');
foreach ($submits as $submit) $assert(!isset($submit['presentation']['style']['typography']['letterSpacing']), 'the source submit states no letter-spacing');

$button = $theme['styles']['elements']['button'] ?? array();
$assert('9px' === ($button['border']['radius'] ?? null), 'the dominant look still reaches the theme button element');
$assert('uppercase' === ($button['typography']['textTransform'] ?? null), 'a field the submit carries stays on the element');
$assert(!isset($button['typography']['letterSpacing']), 'the element must not give the provider submit letter-spacing its source never had');

preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $matches);
$assert(count($matches[1]) >= 6, 'the link buttons stay native buttons');
foreach ($matches[1] as $json) {
    $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $assert('.07em' === ($attributes['style']['typography']['letterSpacing'] ?? null), 'a link button keeps its own letter-spacing once the element no longer owns it');
}

echo "provider submit button element ownership passed\n";
