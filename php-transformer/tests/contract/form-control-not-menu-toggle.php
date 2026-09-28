<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// The header's phone menu is a captured disclosure whose dialog holds the site
// navigation. Far below it, a contact form has a phone field whose country
// picker is a labelless button with `aria-expanded`. That picker opens its own
// list; it must not be taken for the menu toggle and claim the menu dialog.
$disclosure = '<details class="dla-disclosure"><summary aria-label="Menu"></summary>'
    . '<div class="dla-dialog" role="dialog" aria-modal="true" aria-label="Site"><nav aria-label="Site"><ul>'
    . '<li><a href="about.html">About</a></li><li><a href="services.html">Services</a></li><li><a href="team.html">Team</a></li>'
    . '</ul></nav></div></details>';
$page = static fn (string $form): string => '<!doctype html><html><head><style>[data-hook=cell]{padding:2px}</style><style data-dla-disclosure="true">details.dla-disclosure:not([open])>.dla-dialog{display:none!important}</style></head><body><div><div>'
    . '<header><div class="phone"><nav class="toggle-wrap"><div>' . $disclosure . '</div></nav></div></header>'
    . '<main><section id="about"><h2>About</h2></section><section id="services"><h2>Services</h2></section><section id="team"><h2>Team</h2>' . $form . '</section></main>'
    . '</div></div></body></html>';
$form = '<form method="post"><fieldset><div data-hook="cell"><label for="phone">Phone</label>'
    . '<span><span><button type="button" aria-label="Phone. Select a country code" aria-expanded="false" aria-haspopup="listbox"><span><svg viewBox="0 0 24 24" width="24" height="24"><path d="M1 1h22v22H1z"></path></svg></span></button></span></span>'
    . '<input id="phone" type="tel" name="phone"></div><button type="submit">Send</button></fieldset></form>';

foreach (array('without a form' => '', 'with a form picker' => $form) as $label => $body) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page($body), 'about.html' => '<main>About</main>', 'services.html' => '<main>Services</main>', 'team.html' => '<main>Team</main>')))->toArray();
    $markup = (string) (array_column($result['source_reports']['compiled_site']['pages'] ?? array(), 'block_markup', 'source_path')['index.html'] ?? '');
    $start = strpos($markup, '<!-- wp:details');
    $details = false === $start ? '' : substr($markup, $start, strpos($markup, '<!-- /wp:details -->', $start) - $start);
    $assert('' !== $details, "The phone menu disclosure converts ({$label}).");
    $assert(str_contains($details, '<!-- wp:navigation') && str_contains($details, 'Services'), "The disclosure keeps its menu navigation ({$label}).");
}

echo "Form control not menu toggle contract passed.\n";
