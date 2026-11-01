# Chained button paint regression (#3925)

Reduced to one anchor and one rule. Neither the menu ancestor, `:where()` root scope nor responsive captures are necessary: six variable hops exceed the former five-level expansion limit. The flat two-level case does not. The important text color is an independent failure in native button value parsing.

The companion `tests/unit/button-custom-property-chain.php` was run against the unmodified implementation before fixing it and failed with:

```json
{"color":{"background":"var(--surface)"},"border":{"radius":"21px"},"spacing":{"padding":{"top":"7.5px","right":"7.5px","bottom":"7.5px","left":"7.5px"}}}
```

After the fix the same page passes and emits:

```json
{"color":{"background":"#cacaca","text":"#5b5b5b"},"border":{"radius":"21px"},"spacing":{"padding":{"top":"7.5px","right":"7.5px","bottom":"7.5px","left":"7.5px"}}}
```

The test also varies the page with an undefined radius token (refuse only that attribute, retain author CSS and report the refusal), and `calc(2.5px * 3)` padding under a 62.5% root (retain `7.5px`, not a 16px-root-derived rem value).
