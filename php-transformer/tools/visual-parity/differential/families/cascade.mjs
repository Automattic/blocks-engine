/**
 * Cascade: source order, !important (stylesheet and inline), @layer,
 * @media, and custom properties (name case, fallbacks, scope, and
 * declarations inside @media).
 */
import { BACKGROUNDS, COLORS, conflictingDeclarations, descendants, flowTree, length, simpleSelector } from '../grammar.mjs';

const FEATURES = [
  'source-order',
  'important-stylesheet',
  'important-inline',
  'layer',
  'media',
  'custom-property-case',
  'custom-property-fallback',
  'custom-property-scope',
  'custom-property-media',
];

export const cascade = {
  name: 'cascade',
  features: FEATURES,
  generate(builder, budget) {
    const random = builder.random;
    builder.feature('cascade');
    const root = builder.root(flowTree(builder, { budget, depth: random.int(1, 2), minChildren: 2, maxChildren: 5 }));
    const nodes = descendants(root);
    for (const feature of random.sample(FEATURES, random.int(1, 2))) {
      builder.feature(`cascade:${feature}`);
      FEATURE_BUILDERS[feature](builder, root, nodes);
    }
  },
};

/** Value pools for custom properties, keyed by what they can feed. */
const TOKEN_KINDS = {
  color: {
    properties: ['color', 'border-color', 'outline-color'],
    value: (builder) => builder.random.pick(COLORS),
  },
  background: {
    properties: ['background-color'],
    value: (builder) => builder.random.pick(BACKGROUNDS),
  },
  length: {
    properties: ['padding', 'padding-left', 'margin-left', 'font-size', 'letter-spacing', 'border-width'],
    value: (builder) => length(builder.random, { min: 2, max: 22, units: ['px', 'em', 'rem'] })[0],
  },
};

function token(builder) {
  const kind = builder.random.pick(Object.keys(TOKEN_KINDS));
  const pool = TOKEN_KINDS[kind];
  const property = builder.random.pick(pool.properties);
  return { kind, property, value: () => pool.value(builder) };
}

/** Declarations that make a token's property visible (borders need a style). */
function consume(property, value) {
  const declarations = [[property, value]];
  if (property === 'border-color' || property === 'border-width') declarations.push(['border-style', 'solid']);
  if (property === 'outline-color') declarations.push(['outline-style', 'solid']);
  return declarations;
}

function targetSelector(builder, target) {
  return simpleSelector(builder, target, builder.random.pick(['class', 'type-class', 'type']));
}

const FEATURE_BUILDERS = {
  'source-order'(builder, root, nodes) {
    const random = builder.random;
    const target = random.pick(nodes);
    const [first, second] = conflictingDeclarations(builder);
    if (random.chance(0.4)) {
      builder.tag('cascade:duplicate-declaration');
      builder.rule(targetSelector(builder, target), [first, second]);
      return;
    }
    const selector = targetSelector(builder, target);
    builder.rule(selector, [first]);
    builder.rule(random.chance(0.5) ? selector : simpleSelector(builder, target, 'class'), [second]);
  },

  'important-stylesheet'(builder, root, nodes) {
    const random = builder.random;
    const target = random.pick(nodes);
    const [weak, strong] = conflictingDeclarations(builder);
    const rules = [
      [target.tag, [[...weak, true]]],
      [`${simpleSelector(builder, root, random.pick(['class', 'id']))} ${simpleSelector(builder, target, 'class')}`, [strong]],
    ];
    for (const [selector, declarations] of random.shuffle(rules)) builder.rule(selector, declarations);
  },

  'important-inline'(builder, root, nodes) {
    const random = builder.random;
    const target = random.pick(nodes);
    const [inline, sheet] = conflictingDeclarations(builder);
    const shape = random.pick(['inline-normal', 'sheet-important', 'both-important']);
    builder.tag(`cascade:inline-${shape}`);
    const inlineImportant = shape === 'both-important';
    const sheetImportant = shape !== 'inline-normal';
    target.style = [target.style, `${inline[0]}: ${inline[1]}${inlineImportant ? ' !important' : ''}`].filter(Boolean).join('; ');
    builder.rule(`${simpleSelector(builder, root, 'id')} ${simpleSelector(builder, target, 'class')}`, [[...sheet, sheetImportant]]);
  },

  layer(builder, root, nodes) {
    const random = builder.random;
    const target = random.pick(nodes);
    const [low, high] = conflictingDeclarations(builder, random.pick(['color', 'background-color', 'font-size', 'padding', 'border']));
    const lower = builder.layerName();
    const upper = builder.layerName();
    const important = random.chance(0.3);
    if (important) builder.tag('cascade:layer-important');
    const rules = [
      { selector: `${simpleSelector(builder, root, 'id')} ${target.tag}`, declarations: [[...low, important]], layer: lower },
      { selector: target.tag, declarations: [[...high, important]], layer: upper },
    ];
    for (const rule of random.shuffle(rules)) builder.rule(rule.selector, rule.declarations, { layer: rule.layer });
    if (random.chance(0.5)) {
      builder.tag('cascade:unlayered');
      const [unlayered] = conflictingDeclarations(builder, low[0]);
      builder.rule(random.pick([target.tag, `:where(${target.tag})`]), [unlayered]);
    }
  },

  media(builder, root, nodes) {
    const random = builder.random;
    const target = random.pick(nodes);
    const [base, conditional] = conflictingDeclarations(builder);
    const selector = targetSelector(builder, target);
    const rules = [
      { declarations: [base], media: null },
      { declarations: [conditional], media: builder.mediaQuery() },
    ];
    // Usually the conditional rule follows the base; sometimes it precedes it and must lose.
    const ordered = random.chance(0.75) ? rules : [...rules].reverse();
    for (const rule of ordered) builder.rule(selector, rule.declarations, { media: rule.media });
    if (random.chance(0.3)) {
      builder.tag('cascade:media-nested-selector');
      builder.rule(`${simpleSelector(builder, root, 'class')} ${selector}`, [conflictingDeclarations(builder, base[0])[0]], { media: builder.mediaQuery() });
    }
  },

  'custom-property-case'(builder, root, nodes) {
    const random = builder.random;
    const { property, value } = token(builder);
    let name = builder.customProperty();
    if (name === name.toLowerCase()) name = `--${name.slice(2, 3).toUpperCase()}${name.slice(3)}`;
    const lower = name.toLowerCase();
    const scope = random.pick([':root', simpleSelector(builder, root, 'class')]);
    const values = [value(), value()];
    const declarations = random.shuffle([[name, values[0]], [lower, values[1]]]);
    builder.rule(scope, declarations);
    const target = random.pick(nodes);
    const referenced = random.pick([name, lower]);
    builder.rule(targetSelector(builder, target), consume(property, `var(${referenced})`));
  },

  'custom-property-fallback'(builder, root, nodes) {
    const random = builder.random;
    const { property, value } = token(builder);
    const defined = builder.customProperty();
    const missing = builder.customProperty();
    builder.rule(':root', [[defined, value()]]);
    const shape = random.pick(['missing', 'defined', 'nested-missing', 'nested-defined', 'empty-fallback']);
    builder.tag(`cascade:fallback-${shape}`);
    const reference = {
      missing: `var(${missing}, ${value()})`,
      defined: `var(${defined}, ${value()})`,
      'nested-missing': `var(${missing}, var(${defined}, ${value()}))`,
      'nested-defined': `var(${defined}, var(${missing}))`,
      'empty-fallback': `var(${missing},)`,
    }[shape];
    const target = random.pick(nodes);
    builder.rule(targetSelector(builder, target), consume(property, reference));
  },

  'custom-property-scope'(builder, root, nodes) {
    const random = builder.random;
    const { property, value } = token(builder);
    const name = builder.customProperty();
    builder.rule(':root', [[name, value()]]);
    const scopes = [root, ...nodes.filter((node) => node.children.length)];
    const scope = random.pick(scopes);
    builder.rule(simpleSelector(builder, scope, random.pick(['class', 'attribute'])), [[name, value()]]);
    // Consumers inside and outside the overriding scope share one rule.
    const consumerTag = random.pick(nodes).tag;
    builder.rule(`${simpleSelector(builder, root, 'type')} ${consumerTag}`, consume(property, `var(${name})`));
    if (random.chance(0.4)) {
      builder.tag('cascade:custom-property-inherit-chain');
      const alias = builder.customProperty();
      builder.rule(simpleSelector(builder, root, 'class'), [[alias, `var(${name})`]]);
      builder.rule(simpleSelector(builder, random.pick(nodes), 'class'), consume(property, `var(${alias})`));
    }
  },

  'custom-property-media'(builder, root, nodes) {
    const random = builder.random;
    const { property, value } = token(builder);
    const name = builder.customProperty();
    const scope = random.pick([':root', simpleSelector(builder, root, 'class'), simpleSelector(builder, root, 'id')]);
    const shape = random.pick(['media-only', 'base-and-override', 'override-only-max']);
    builder.tag(`cascade:custom-property-${shape}`);
    if (shape !== 'media-only') builder.rule(scope, [[name, value()]]);
    const query = shape === 'override-only-max' ? builder.mediaQuery(['max']) : builder.mediaQuery(['min', 'range']);
    builder.rule(scope, [[name, value()]], { media: query });
    const target = random.pick(nodes);
    const fallback = random.chance(0.5) ? `, ${value()}` : '';
    builder.rule(targetSelector(builder, target), consume(property, `var(${name}${fallback})`));
  },
};
