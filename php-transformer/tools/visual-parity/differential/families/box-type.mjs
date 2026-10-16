/**
 * Box model and typography: shorthands against longhands, logical
 * properties, units (em/rem/%/vw/clamp()), line-height and inheritance.
 */
import { COLORS, declaration, descendants, flowTree, fontSize, length, simpleSelector } from '../grammar.mjs';

const FEATURES = ['shorthand-longhand', 'logical-properties', 'units', 'line-height', 'inheritance'];

export const boxType = {
  name: 'box-type',
  features: FEATURES,
  generate(builder, budget) {
    const random = builder.random;
    builder.feature('box-type');
    const root = builder.root(flowTree(builder, { budget, depth: random.int(1, 3), minChildren: 2, maxChildren: 4, inline: 0.3 }));
    const nodes = descendants(root);
    for (const feature of random.sample(FEATURES, random.int(1, 2))) {
      builder.feature(`box-type:${feature}`);
      FEATURE_BUILDERS[feature](builder, root, nodes);
    }
  },
};

function sides(builder, count, units = ['px', 'em', 'rem']) {
  return Array.from({ length: count }, () => {
    const [value, unit] = length(builder.random, { min: 0, max: 24, units });
    builder.tag(`unit:${unit}`);
    return value;
  }).join(' ');
}

function blockTarget(builder, nodes) {
  const blocks = nodes.filter((node) => !['span', 'strong', 'em', 'a'].includes(node.tag));
  return builder.random.pick(blocks.length ? blocks : nodes);
}

const SHORTHANDS = {
  padding: (builder) => ({ shorthand: ['padding', sides(builder, builder.random.int(1, 4))], longhand: [builder.random.pick(['padding-top', 'padding-left', 'padding-right', 'padding-bottom']), sides(builder, 1)] }),
  margin: (builder) => ({ shorthand: ['margin', sides(builder, builder.random.int(1, 4))], longhand: [builder.random.pick(['margin-top', 'margin-left', 'margin-bottom']), sides(builder, 1, ['px', 'em'])] }),
  border: (builder) => ({
    shorthand: ['border', `${builder.random.int(1, 6)}px solid ${builder.random.pick(COLORS)}`],
    longhand: builder.random.pick([['border-top-color', builder.random.pick(COLORS)], ['border-left-width', `${builder.random.int(0, 10)}px`], ['border-bottom-style', 'dotted']]),
  }),
  font: (builder) => ({
    shorthand: ['font', `${builder.random.pick(['italic ', '', 'small-caps '])}${builder.random.pick(['400', '700'])} ${fontSize(builder.random, ['px', 'rem'])[0]}/${builder.random.pick(['1.2', '1.6', '28px'])} ${builder.random.pick(['serif', 'sans-serif', 'monospace'])}`],
    longhand: builder.random.pick([['font-size', fontSize(builder.random, ['px', 'em'])[0]], ['font-weight', '300'], ['line-height', '2']]),
  }),
  background: (builder) => ({ shorthand: ['background', builder.random.pick(['#e8f8f5', 'lavender', 'linear-gradient(#fff, #ddd)'])], longhand: ['background-color', builder.random.pick(['mistyrose', '#fef9e7'])] }),
  'border-radius': (builder) => ({ shorthand: ['border-radius', sides(builder, builder.random.int(1, 4), ['px'])], longhand: ['border-top-left-radius', `${builder.random.int(0, 30)}px`] }),
};

const FEATURE_BUILDERS = {
  'shorthand-longhand'(builder, root, nodes) {
    const random = builder.random;
    const target = blockTarget(builder, nodes);
    const family = random.pick(Object.keys(SHORTHANDS));
    const { shorthand, longhand } = SHORTHANDS[family](builder);
    const order = random.pick(['shorthand-first', 'longhand-first', 'same-rule-longhand-last', 'same-rule-shorthand-last']);
    builder.tag(`box-type:shorthand-${family}`, `box-type:${order}`);
    const extra = family === 'border-radius' ? [['background-color', 'lavender']] : [];
    const selector = simpleSelector(builder, target, 'class');
    if (order === 'shorthand-first') {
      builder.rule(selector, [shorthand, ...extra]);
      builder.rule(selector, [longhand]);
    } else if (order === 'longhand-first') {
      builder.rule(`${simpleSelector(builder, root, 'class')} ${selector}`, [longhand]);
      builder.rule(selector, [shorthand, ...extra]);
    } else if (order === 'same-rule-longhand-last') {
      builder.rule(selector, [shorthand, longhand, ...extra]);
    } else {
      builder.rule(selector, [longhand, shorthand, ...extra]);
    }
  },

  'logical-properties'(builder, root, nodes) {
    const random = builder.random;
    if (random.chance(0.35)) {
      root.attrs.dir = 'rtl';
      builder.tag('box-type:rtl');
    }
    const target = blockTarget(builder, nodes);
    const pool = [
      ['padding-inline', sides(builder, random.int(1, 2))],
      ['padding-block', sides(builder, random.int(1, 2))],
      ['padding-inline-start', sides(builder, 1)],
      ['margin-inline-start', sides(builder, 1)],
      ['margin-block-end', sides(builder, 1)],
      ['border-inline-start', `${random.int(2, 8)}px solid ${random.pick(COLORS)}`],
      ['inline-size', `${random.pick([50, 70, 90])}%`],
      ['max-inline-size', `${random.int(140, 300)}px`],
      ['text-align', random.pick(['start', 'end'])],
      ['inset-inline-end', '0'],
    ];
    const declarations = random.sample(pool, random.int(1, 3));
    for (const [property] of declarations) builder.tag(`box-type:logical-${property}`);
    if (declarations.some(([property]) => property === 'inset-inline-end')) {
      builder.rule(simpleSelector(builder, root, 'class'), [['position', 'relative'], ['min-height', '60px']]);
      declarations.push(['position', 'absolute']);
    }
    builder.rule(simpleSelector(builder, target, 'class'), [...declarations, ['background-color', 'honeydew']]);
  },

  units(builder, root, nodes) {
    const random = builder.random;
    if (random.chance(0.3)) {
      builder.tag('unit:root-font-size');
      builder.rule('html', [['font-size', random.pick(['18px', '14px', '112.5%', '62.5%'])]]);
    }
    const [rootSize, rootUnit] = fontSize(random, ['px', '%', 'rem', 'clamp']);
    builder.tag(`unit:${rootUnit}`);
    builder.rule(simpleSelector(builder, root, 'class'), [['font-size', rootSize]]);
    for (const target of random.sample(nodes, Math.min(nodes.length, random.int(1, 3)))) {
      const [size, unit] = fontSize(random);
      const [width, widthUnit] = length(random, { min: 120, max: 360, units: ['px', '%', 'vw', 'em'] });
      const [pad, padUnit] = length(random, { min: 2, max: 16, units: ['em', 'rem', '%'] });
      builder.tag(`unit:${unit}`, `unit:${widthUnit}`, `unit:${padUnit}`);
      const declarations = [['font-size', size], ['padding', pad]];
      if (!['span', 'strong', 'em', 'a'].includes(target.tag)) declarations.push([random.pick(['width', 'max-width']), widthUnit === '%' ? `${random.int(30, 90)}%` : width]);
      builder.rule(simpleSelector(builder, target, 'class'), declarations);
    }
  },

  'line-height'(builder, root, nodes) {
    const random = builder.random;
    const value = random.pick(['1.6', '1.2', '24px', '150%', '1.5em', 'normal', '2']);
    const kind = /px$/.test(value) ? 'px' : /%$/.test(value) ? 'percent' : /em$/.test(value) ? 'em' : value === 'normal' ? 'normal' : 'unitless';
    builder.tag(`box-type:line-height-${kind}`);
    builder.rule(simpleSelector(builder, root, 'class'), [['line-height', value], ['font-size', `${random.int(14, 18)}px`], ['width', `${random.int(180, 320)}px`]]);
    const target = blockTarget(builder, nodes);
    target.text = builder.longText(target, random.int(8, 16));
    builder.rule(simpleSelector(builder, target, 'class'), [['font-size', fontSize(random, ['px', 'em'])[0]]]);
    if (random.chance(0.4)) {
      builder.tag('box-type:line-height-override');
      builder.rule(simpleSelector(builder, random.pick(nodes), 'type'), [['line-height', random.pick(['1', '30px', 'inherit', '120%'])]]);
    }
  },

  inheritance(builder, root, nodes) {
    const random = builder.random;
    const inherited = random.sample([
      declaration(builder, 'color'),
      declaration(builder, 'font-size'),
      declaration(builder, 'letter-spacing'),
      declaration(builder, 'text-transform'),
      declaration(builder, 'font-style'),
      ['font-weight', '700'],
      ['text-align', 'center'],
    ], random.int(1, 3));
    for (const [property] of inherited) builder.tag(`box-type:inherit-${property}`);
    builder.rule(simpleSelector(builder, root, 'class'), [...inherited, ['background-color', 'aliceblue']]);
    const target = random.pick(nodes);
    const [property] = random.pick(inherited);
    const keyword = random.pick(['inherit', 'initial', 'unset', 'revert', 'current-color']);
    builder.tag(`box-type:keyword-${keyword}`);
    if (keyword === 'current-color') {
      builder.rule(simpleSelector(builder, target, 'class'), [['border', '3px solid currentColor']]);
      return;
    }
    const [override] = [declaration(builder, ['letter-spacing', 'text-transform', 'font-style', 'font-size', 'color'].includes(property) ? property : 'color')];
    builder.rule(simpleSelector(builder, target, 'class'), [override]);
    builder.rule(`${simpleSelector(builder, target, 'class')} ${random.pick(['', '> *'])}`.trim(), [[property, keyword]]);
  },
};
