/**
 * Feature catalog for the differential corpus.
 *
 * A case is one or two families composed into a single standalone page of
 * at most `maxElements` elements. The same seed and index always produce the
 * same case.
 */
import { CaseBuilder } from './grammar.mjs';
import { caseRandom } from './random.mjs';
import { boxType } from './families/box-type.mjs';
import { cascade } from './families/cascade.mjs';
import { layout } from './families/layout.mjs';
import { recognizers } from './families/recognizers.mjs';
import { selectors } from './families/selectors.mjs';

export const FAMILIES = Object.fromEntries([selectors, cascade, layout, boxType, recognizers].map((family) => [family.name, family]));
export const familyNames = Object.keys(FAMILIES);

export const DEFAULT_GENERATION = {
  seed: 1,
  count: 200,
  families: familyNames,
  composeRate: 0.35,
  maxElements: 15,
  wrapRate: 0.12,
};

export function documentFor(css, body, title = 'differential case') {
  return [
    '<!doctype html>',
    '<html lang="en">',
    '<head>',
    '<meta charset="utf-8">',
    `<title>${title}</title>`,
    '<style>',
    css,
    '</style>',
    '</head>',
    '<body>',
    body,
    '</body>',
    '</html>',
    '',
  ].join('\n');
}

/** Generate case `index` (0-based) of a run. */
export function generateCase(index, options = {}) {
  const { seed, families, composeRate, maxElements, wrapRate } = { ...DEFAULT_GENERATION, ...options };
  for (const name of families) {
    if (!FAMILIES[name]) throw new Error(`Unknown family "${name}"; known families: ${familyNames.join(', ')}`);
  }
  const random = caseRandom(seed, index);
  const composed = families.length > 1 && random.chance(composeRate);
  const chosen = composed ? random.sample(families, 2) : [random.pick(families)];
  const builder = new CaseBuilder(random, { maxElements });
  const firstBudget = composed ? Math.floor(maxElements / 2) : maxElements;
  chosen.forEach((name, position) => {
    const budget = position === 0 ? firstBudget : builder.remaining();
    FAMILIES[name].generate(builder, Math.min(budget, builder.remaining()));
  });
  builder.wrapSomeRules(wrapRate);
  if (composed) builder.tag('composed');

  // Coarse tags name families and their declared features; failures group on these.
  const coarse = new Set(['composed', 'wrap:media', 'wrap:layer']);
  for (const name of chosen) {
    coarse.add(name);
    for (const feature of FAMILIES[name].features) coarse.add(`${name}:${feature}`);
  }
  const allTags = [...builder.tags].sort();

  const id = `${String(index + 1).padStart(4, '0')}-${chosen.join('+')}`;
  const css = builder.css();
  const body = builder.body();
  return {
    id,
    index,
    family: chosen.join('+'),
    families: chosen,
    tags: allTags.filter((tag) => coarse.has(tag)),
    detail_tags: allTags.filter((tag) => !coarse.has(tag)),
    element_count: builder.count,
    rule_count: builder.rules.length,
    markers: builder.markers().map(({ id: marker }) => marker),
    attribution: builder.attribution(),
    html: documentFor(css, body, id),
  };
}

export function generateCases(options = {}) {
  const { count } = { ...DEFAULT_GENERATION, ...options };
  return Array.from({ length: count }, (_, index) => generateCase(index, options));
}
