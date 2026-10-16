/**
 * Pinned regression cases: one minimal page per fixed bug class the corpus
 * must keep reproducing. Each passes on trunk and fails with its fix
 * reverted (see the README for the recorded revert runs).
 */
import { documentFor } from './catalog.mjs';
import { imageSource } from './grammar.mjs';

function pinned({ issue, fix, slug, tags, css, body }) {
  const id = `pinned-${issue}-${slug}`;
  return {
    id,
    issue,
    fix,
    family: 'pinned',
    families: ['pinned'],
    tags: ['pinned', `pinned:issue-${issue}`, ...tags],
    detail_tags: [],
    element_count: (body.match(/<[a-z][^>]*>/gi) ?? []).length,
    html: documentFor(css.trim(), body.trim(), id),
  };
}

const wide = imageSource(1600, 1000);

export const PINNED_CASES = [
  // :where() contributes no specificity. Read as if it counted, the
  // `:where(.hero) a` rule scores (0,1,1), outranks `.btn` (0,1,0), and the
  // projected button CSS lets it restyle the link.
  pinned({
    issue: 1914,
    fix: '3128cb9c',
    slug: 'where-specificity',
    tags: ['selectors', 'selectors:where', 'recognizers', 'recognizers:button-link'],
    css: `
.btn { display: inline-block; padding: 10px 20px; background-color: #2471a3; color: #ffffff; font-style: normal; text-decoration: none; }
:where(.hero) a { font-style: italic; text-decoration: underline; }
`,
    body: `
<section id="pin1914a" class="hero">
  <div class="actions"><a id="pin1914b" class="btn" href="/start">Get started pin1914b</a></div>
</section>
`,
  }),

  // Custom property names are case-sensitive. Lowercased, the section's
  // `--btnBg` override never matches `var(--btnBg)` and the button keeps
  // the :root default.
  pinned({
    issue: 2183,
    fix: '8c1db132',
    slug: 'custom-property-case',
    tags: ['cascade', 'cascade:custom-property-case', 'cascade:custom-property-scope', 'recognizers', 'recognizers:button-link'],
    css: `
:root { --btnBg: #bfe8e8; --btnPad: 4px; }
.theme-dark { --btnBg: #3f4141; --btnPad: 20px; }
.btn { display: inline-block; background-color: var(--btnBg); padding: var(--btnPad) 30px; color: #ffffff; }
`,
    body: `
<section id="pin2183a" class="theme-dark">
  <p id="pin2183b">Intro pin2183b</p>
  <div class="actions"><a id="pin2183c" class="btn" href="/about">About pin2183c</a></div>
</section>
<section id="pin2183d">
  <div class="actions"><a id="pin2183e" class="btn" href="/other">Other pin2183e</a></div>
</section>
`,
  }),

  // A custom property declared only inside @media still feeds var() at a
  // matching viewport. The form layout graph must see `--fieldHeight`, or it
  // reads the attribute-less field wrapper as layout-free and drops it,
  // pulling the submit button up beside the field.
  pinned({
    issue: 1837,
    fix: 'c91861f3',
    slug: 'custom-property-in-media',
    tags: ['cascade', 'cascade:custom-property-media', 'wrap:media', 'recognizers', 'recognizers:form'],
    css: `
@media (min-width: 320px) { form { --fieldHeight: 40px; } }
form > div { height: var(--fieldHeight); }
`,
    body: `
<form id="pin1837a" method="post" action="#">
  <div><input name="name" placeholder="Name"></div>
  <button id="pin1837b" type="submit">Send pin1837b</button>
</form>
`,
  }),

  // Grid items keep their automatic minimum size. The `overflow: hidden`
  // frame gives the source item a zero automatic minimum; the converted
  // figure (frame coalesced away) needs `min-width: 0` or the 1600px image
  // forces the single implicit track wider than a 390px viewport.
  pinned({
    issue: 1941,
    fix: '21866fb3',
    slug: 'grid-automatic-minimum',
    tags: ['layout', 'layout:grid-automatic-minimum', 'recognizers', 'recognizers:figure'],
    css: `
.work-samples { display: grid; gap: 2rem; }
@media (min-width: 640px) { .work-samples { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.work-samples > figure { margin: 0; }
.frame { overflow: hidden; }
.shot { display: block; aspect-ratio: 8 / 5; width: 100%; object-fit: cover; }
`,
    body: `
<section id="pin1941a" class="work-samples">
  <figure id="pin1941b"><div class="frame"><img src="${wide}" class="shot" alt=""></div><figcaption id="pin1941c">One pin1941c</figcaption></figure>
  <figure id="pin1941d"><div class="frame"><img src="${wide}" class="shot" alt=""></div><figcaption id="pin1941e">Two pin1941e</figcaption></figure>
</section>
`,
  }),
];
