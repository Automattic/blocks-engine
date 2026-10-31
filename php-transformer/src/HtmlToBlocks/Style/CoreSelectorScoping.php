<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Compile-time invariant: every emitted selector that targets a core block
 * class must also carry an importer-emitted class somewhere in its compound or
 * ancestor chain.
 *
 * Core block classes (`.wp-block-*`, `.has-*`) name classes WordPress core
 * renders onto its own blocks as well as the ones this importer emits. A rule
 * keyed only on those tokens leaks past the imported content and reaches
 * blocks an author added to the served page later, so the compiler must either
 * scope such rules with an importer-emitted class (engine markers, role
 * classes, or source classes copied onto the markup) or drop the rule.
 *
 * Scope of the check: compiler-emitted and compiler-rewritten CSS. Pass-through
 * authored CSS is projected through the author-selector pipeline before it is
 * emitted, so by the time it reaches an asset it is either rewritten or copied
 * with the authored classes it targets. Bare element selectors are out of
 * scope: the importer only answers for the core class names it emits.
 *
 * The invariant is deliberately class-based and names the two core class
 * families that mark whole blocks (`.wp-block-*`, `.has-*`); other core
 * rendered classes (partials such as `.wp-block-search__button`, state classes
 * such as `.is-style-logos-only`) are not block identity on their own and are
 * acceptable as long as one of these families appears alongside an
 * importer-emitted class.
 */
final class CoreSelectorScoping
{
	/**
	 * Class families that mark core block identity in a selector.
	 *
	 * @var string
	 */
	private const CORE_CLASS_PATTERN = '/^(?:wp-block-|has-)/';

	/**
	 * Matches a CSS class token inside a selector, tolerating escapes.
	 *
	 * @var string
	 */
	private const CLASS_TOKEN_PATTERN = '/\\.((?:\\\\.|[^\x00-\x7f]|[^\s.#:>+~,\[\]()\'"])+)/';

	/** @var array<string, true> Stylesheets already proven scoped, by content hash. */
	private static array $cleanStylesheets = array();

	/** @var array<string, bool> Scoping verdict per selector text. */
	private static array $selectorVerdicts = array();

	/** Drop unsafe selector arms, preserving surviving declaration bodies verbatim.
	 * @param list<array<string, mixed>> $diagnostics
	 */
	public function dropUnscoped(string $css, string $origin, array &$diagnostics): string
	{
		return (new CssStylesheetTransformer())->transformStyleRules($css, function (string $prelude, string $body) use ($origin, &$diagnostics): string {
			$selectors = CssStylesheetTransformer::splitSelectorList($prelude);
			if ( null === $selectors ) {
				return $prelude . '{' . $body . '}';
			}
			$kept = array();
			foreach ( $selectors as $selector ) {
				if ( $this->selectorIsScoped($selector) ) {
					$kept[] = $selector;
				} else {
					$diagnostics[] = array(
						'code' => 'core_selector_unscoped_rule_dropped',
						'severity' => 'warning',
						'message' => 'Dropped a generated selector targeting a core block class without importer scope.',
						'origin' => $origin,
						'selector' => $selector,
					);
				}
			}
			if ( array() === $kept ) {
				return '';
			}
			return (count($kept) === count($selectors) ? $prelude : implode(',', $kept)) . '{' . $body . '}';
		});
	}

	/** @return list<string> Unscoped selectors found in the stylesheet. */
	public function violations(string $css): array
	{
		// Identical stylesheets recur across the routes of one site; each
		// distinct stylesheet is parsed once per process.
		$key = hash('xxh128', $css);
		if ( isset(self::$cleanStylesheets[$key]) ) {
			return array();
		}
		$violations = array();
		( new CssStylesheetTransformer() )->visitStyleRules($css, function (string $selector, string $body) use (&$violations): void {
			foreach ( CssStylesheetTransformer::splitSelectorList($selector) as $single ) {
				if ( ! $this->selectorIsScoped($single) ) {
					$violations[] = $single;
				}
			}
		});
		if ( array() === $violations ) {
			self::$cleanStylesheets[$key] = true;
		}
		return array_values(array_unique($violations));
	}

	/**
	 * A selector is scoped when no core block class in it stands without an
	 * importer-emitted class anywhere in the same compound or ancestor chain.
	 */
	public function selectorIsScoped(string $selector): bool
	{
		return self::$selectorVerdicts[$selector] ??= $this->computeSelectorIsScoped($selector);
	}

	private function computeSelectorIsScoped(string $selector): bool
	{
		$selectors = CssStylesheetTransformer::splitSelectorList($selector);
		if ( null === $selectors ) {
			return true;
		}
		foreach ( $selectors as $single ) {
			$hasCoreClass = false;
			$hasImporterClass = false;
			foreach ( $this->classTokens($single) as $class ) {
				if ( 1 === preg_match(self::CORE_CLASS_PATTERN, $class) ) {
					$hasCoreClass = true;
				} else {
					$hasImporterClass = true;
				}
			}
			if ( $hasCoreClass && ! $hasImporterClass ) {
				return false;
			}
		}
		return true;
	}

	/** @return list<string> */
	private function classTokens(string $selector): array
	{
		$classes = array();
		if ( ! preg_match_all(self::CLASS_TOKEN_PATTERN, $selector, $matches) ) {
			return array();
		}
		foreach ( $matches[1] as $class ) {
			$class = str_replace('\\', '', $class);
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}
		return $classes;
	}
}
