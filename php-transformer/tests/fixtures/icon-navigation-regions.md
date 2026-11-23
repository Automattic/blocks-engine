# Icon navigation region regression

`icon-navigation-regions.html` is a neutral fixture: header, content, and footer
landmarks repeat destinations but use different source artwork and carriers.
The contract compiles it through ArtifactCompiler and exercises navigation entity
projection. On trunk it fails because the accessible name becomes visible and
the sprite artwork is absent.

## Real capture investigation (#7356)

Read the complete issue and its linked capture:
https://gist.githubusercontent.com/chubes4/06a958286a691afa55dd7aec8fc44a9d/raw/813bb357f67ed3c84621adc0c5225e31cb07b4d7/squarespace-contact-social-icons.html

Downloaded the capture and replaced every external stylesheet link with a style
tag containing the downloaded stylesheet (including the site-relative plugin
stylesheet). Compiled that same input through ArtifactCompiler before and after
the changes.

For `#block-2102cd1f50dbd6fe1020`, the compiler output before the change includes
this navigation-link (engine markers omitted, JSON escapes decoded):

```html
<!-- wp:navigation {"className":"sqs-svg-icon--list","overlayMenu":"never"} -->
<!-- wp:navigation-link {"className":"sqs-svg-icon--wrapper email","label":"hello@beehyve.health","url":"mailto:hello@beehyve.health","kind":"custom"} /-->
```

The same five links after the change keep their source classes and store hidden
labels plus their original artwork. The email item's significant attributes are:

```json
{
  "className": "sqs-svg-icon--wrapper email",
  "label": "<span class=\"screen-reader-text\" style=\"position:absolute;top:0;left:0;width:1px;height:1px;padding:0;margin:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0\">hello@beehyve.health</span>",
  "url": "mailto:hello@beehyve.health",
  "kind": "custom",
  "metadata": {
    "blocksEngineNavigationAnchor": {
      "svg": "<div><svg class=\"sqs-svg-icon--social\" viewBox=\"0 0 64 64\"><use class=\"sqs-use--icon\" xlink:href=\"/universal/svg/social-accounts.svg#email-icon\"></use><use class=\"sqs-use--mask\" xlink:href=\"/universal/svg/social-accounts.svg#email-mask\"></use></svg></div>"
    }
  }
}
```

The Facebook, Instagram, LinkedIn and YouTube items follow the same pattern;
the content landmark now contains ten preserved uses rather than none. The
footer copy likewise retains its artwork. These are compiler markup observations,
not browser height measurements.

Rechecked against trunk `4441fbded`: all 17 external stylesheet links were
inlined (site-relative `/s/` links resolved against `https://www.beehyve.health`).
The neutral contract fails on trunk and passes on the fix. The responsive icon
box, nested-list inventory, icon-label artwork, and navigation compilation
contracts pass. The compilation fingerprint changed only because the site plan
embeds the updated navigation runtime; its blocks and menu entities are unchanged.
The full `tests/contract/run.php` also passes; full-suite verification is left
to the declared Homeboy gates.

The direct ArtifactCompiler output on this checkout already had content classes,
not the header classes reported on the issue's imported page. The later entity
projection was still folding these icon-only lists into larger menus based only
on semantic destinations. Projecting the real compiler output before the fix
produced only the two larger entities; after the fix the two five-item icon lists
remain distinct entities as well. Artwork structure and its source carrier now
participate in sharing/folding identity, so equal hrefs alone cannot substitute
a different region's icon items.
