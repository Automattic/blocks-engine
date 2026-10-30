<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Inline header includes from the reduction in Automattic/blocks-engine#3829.
$header = <<<'HTML'
<header class="wp-block-template-part">
		<nav style="font-size:0.75rem" class="is-responsive items-justified-right wp-block-navigation is-content-justification-right is-layout-flex wp-container-core-navigation-is-layout-d24e2b65 wp-block-navigation-is-layout-flex" data-wp-interactive="core/navigation" data-wp-context="{&quot;overlayOpenedBy&quot;:{&quot;click&quot;:false,&quot;hover&quot;:false,&quot;focus&quot;:false},&quot;type&quot;:&quot;overlay&quot;,&quot;roleAttribute&quot;:&quot;&quot;,&quot;ariaLabel&quot;:&quot;Menu&quot;}"><button aria-haspopup="dialog" aria-label="Open menu" class="wp-block-navigation__responsive-container-open" data-wp-on--click="actions.openMenuOnClick" data-wp-on--keydown="actions.handleMenuKeydown" data-blocks-engine-capture-disclosure-label="Open menu" data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0" aria-controls="data-blocks-engine-capture-dialog-0" aria-expanded="false"><svg width="24" height="24" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7.5h16v1.5H4z"></path><path d="M4 15h16v1.5H4z"></path></svg></button><div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden="" id="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0"><div class="wp-block-navigation__responsive-container has-text-color has-primary-color has-background has-secondary-background-color has-modal-open is-menu-open" id="modal-2" data-wp-class--has-modal-open="state.isMenuOpen" data-wp-class--is-menu-open="state.isMenuOpen" data-wp-watch="callbacks.initMenu" data-wp-on--keydown="actions.handleMenuKeydown" data-wp-on--focusout="actions.handleMenuFocusout" tabindex="-1" style="display: flex !important; visibility: visible !important;">
					<div class="wp-block-navigation__responsive-close" tabindex="-1">
						<div class="wp-block-navigation__responsive-dialog" data-wp-bind--aria-modal="state.ariaModal" data-wp-bind--aria-label="state.ariaLabel" data-wp-bind--role="state.roleAttribute" aria-label="Menu" aria-modal="true" role="dialog">
							<button aria-label="Close menu" class="wp-block-navigation__responsive-container-close" data-wp-on--click="actions.closeMenuOnClick"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="m13.06 12 6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12Z"></path></svg></button>
							<div class="wp-block-navigation__responsive-container-content" data-wp-watch="callbacks.focusFirstElement" id="modal-2-content">
								<ul style="font-size:0.75rem" class="wp-block-navigation__container is-responsive items-justified-right wp-block-navigation"><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="/category/blog/index.html"><span class="wp-block-navigation-item__label">Blog</span></a></li><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="/category/foto/index.html"><span class="wp-block-navigation-item__label">Foto</span></a></li><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="/que-es-este-blog/index.html"><span class="wp-block-navigation-item__label">Qué es esto?</span></a></li><li data-wp-context="{ &quot;submenuOpenedBy&quot;: { &quot;click&quot;: false, &quot;hover&quot;: false, &quot;focus&quot;: false }, &quot;type&quot;: &quot;submenu&quot;, &quot;modal&quot;: null, &quot;previousFocus&quot;: null }" data-wp-interactive="core/navigation" data-wp-on--focusout="actions.handleMenuFocusout" data-wp-on--keydown="actions.handleMenuKeydown" data-wp-on--pointerenter="actions.openMenuOnHover" data-wp-on--pointerleave="actions.closeMenuOnHover" data-wp-watch="callbacks.initMenu" tabindex="-1" class="wp-block-navigation-item has-child open-on-hover-click wp-block-navigation-submenu"><a class="wp-block-navigation-item__content" href="#"><span class="wp-block-navigation-item__label">Links</span></a><button data-wp-bind--aria-expanded="state.isSubmenuOpen" data-wp-on--click="actions.toggleMenuOnClick" aria-label="Links submenu" class="wp-block-navigation__submenu-icon wp-block-navigation-submenu__toggle" aria-expanded="true"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" focusable="false"><path d="M1.50002 4L6.00002 8L10.5 4" stroke-width="1.5"></path></svg></button><ul data-wp-on--focus="actions.openMenuOnFocus" class="wp-block-navigation__submenu-container has-text-color has-primary-color has-background has-secondary-background-color wp-block-navigation-submenu"><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="https://x.com/mebenedetto" target="_blank"><span class="wp-block-navigation-item__label">X</span></a></li><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="https://www.linkedin.com/in/matias-benedetto/" target="_blank"><span class="wp-block-navigation-item__label">LinkedIn</span></a></li><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="https://github.com/matiasbenedetto" target="_blank"><span class="wp-block-navigation-item__label">GitHub</span></a></li></ul></li></ul>
								
							</div>
						</div>
					</div>
				</div></div><button type="button" hidden="" data-blocks-engine-capture-dialog-close="data-blocks-engine-capture-dialog-0" aria-label="Close Open menu">Close</button>
				</nav>
</header>
HTML;
$copy = static fn(string $device): string => '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope="">' . $header . '<main><h1>Blog</h1></main></div>';
$html = '<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $copy('desktop') . $copy('mobile') . '</body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'about/index.html' => $html,
)))->toArray();
$menus = $result['source_reports']['wordpress_site_plan']['menus'] ?? array();
if (!$menus) throw new RuntimeException('Expected a shared navigation entity.');
foreach ($menus as $menu) {
    preg_match_all('/<!-- wp:navigation-(?:link|submenu) (\{.*?\})/s', $menu['block_markup'], $matches);
    $labels = array_map(static fn(string $json): string => strip_tags(json_decode($json, true, 512, JSON_THROW_ON_ERROR)['label']), $matches[1]);
    if (array('Blog', 'Foto', 'Qué es esto?', 'Links', 'X', 'LinkedIn', 'GitHub') !== $labels) {
        throw new RuntimeException('Navigation hierarchy lost: ' . json_encode($labels));
    }
    if (!preg_match('/<!-- wp:navigation-submenu (\{.*?\}) -->(.*?)<!-- \/wp:navigation-submenu -->/s', $menu['block_markup'], $submenu)) {
        throw new RuntimeException('Expected a nested Links submenu.');
    }
    $attrs = json_decode($submenu[1], true, 512, JSON_THROW_ON_ERROR);
    preg_match_all('/<!-- wp:navigation-link (\{.*?\})/s', $submenu[2], $children);
    $childLabels = array_map(static fn(string $json): string => strip_tags(json_decode($json, true, 512, JSON_THROW_ON_ERROR)['label']), $children[1]);
    if ('Links' !== strip_tags($attrs['label']) || array('X', 'LinkedIn', 'GitHub') !== $childLabels) {
        throw new RuntimeException('Links must own the three social destinations.');
    }
}
echo "navigation-device-document-hierarchy: passed\n";
