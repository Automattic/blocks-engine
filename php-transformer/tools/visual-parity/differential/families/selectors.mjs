/**
 * Selectors: combinators, :where()/:is()/:not(), attribute selectors and
 * specificity ties. Each feature styles one target through the selector
 * under test and competes with a plain rule for the same property, so the
 * winner depends on the transformer reading the selector the way CSS does.
 */
import { ancestors, conflictingDeclarations, descendants, flowTree, previousSiblings, simpleSelector } from '../grammar.mjs';

const FEATURES = ['descendant', 'child', 'adjacent-sibling', 'general-sibling', 'where', 'is', 'not', 'attribute', 'specificity-tie'];

export const selectors = {
  name: 'selectors',
  features: FEATURES,
  generate(builder, budget) {
    const random = builder.random;
    builder.feature('selectors');
    const root = builder.root(flowTree(builder, { budget, depth: random.int(1, 3), minChildren: 3, maxChildren: 5 }));
    const nodes = descendants(root);
    for (const feature of random.sample(FEATURES, random.int(1, 2))) {
      builder.feature(`selectors:${feature}`);
      FEATURE_BUILDERS[feature](builder, nodes);
    }
  },
};

/** Emit the feature rule and a competing baseline rule in a random order. */
function compete(builder, featureSelector, baselineSelector) {
  const [featureDeclaration, baselineDeclaration] = conflictingDeclarations(builder);
  const rules = [
    [featureSelector, [featureDeclaration]],
    [baselineSelector, [baselineDeclaration]],
  ];
  for (const [selector, declarations] of builder.random.shuffle(rules)) builder.rule(selector, declarations);
}

function pickTarget(builder, nodes, predicate = () => true) {
  const candidates = nodes.filter(predicate);
  return builder.random.pick(candidates.length ? candidates : nodes);
}

const FEATURE_BUILDERS = {
  descendant(builder, nodes) {
    const random = builder.random;
    const target = pickTarget(builder, nodes);
    const ancestor = random.pick(ancestors(target));
    const selector = `${simpleSelector(builder, ancestor, random.pick(['class', 'type', 'attribute']))} ${simpleSelector(builder, target, random.pick(['type', 'class']))}`;
    compete(builder, selector, simpleSelector(builder, target, random.pick(['type', 'class'])));
  },

  child(builder, nodes) {
    const random = builder.random;
    const target = pickTarget(builder, nodes);
    const selector = `${simpleSelector(builder, target.parent, random.pick(['class', 'type']))} > ${simpleSelector(builder, target, random.pick(['type', 'type-class']))}`;
    compete(builder, selector, simpleSelector(builder, target, 'class'));
  },

  'adjacent-sibling'(builder, nodes) {
    const random = builder.random;
    const target = pickTarget(builder, nodes, (node) => previousSiblings(node).length > 0);
    const previous = previousSiblings(target).at(-1) ?? target.parent;
    const combinator = previousSiblings(target).length ? '+' : '>';
    const selector = `${simpleSelector(builder, previous, random.pick(['type', 'class']))} ${combinator} ${simpleSelector(builder, target, random.pick(['type', 'class']))}`;
    compete(builder, selector, simpleSelector(builder, target, 'type'));
  },

  'general-sibling'(builder, nodes) {
    const random = builder.random;
    const target = pickTarget(builder, nodes, (node) => previousSiblings(node).length > 0);
    const earlier = previousSiblings(target);
    const anchor = earlier.length ? random.pick(earlier) : target.parent;
    const combinator = earlier.length ? '~' : '';
    const selector = `${simpleSelector(builder, anchor, random.pick(['class', 'type']))} ${combinator} ${target.tag}`.replace(/\s+/g, ' ');
    compete(builder, selector, simpleSelector(builder, target, random.pick(['type', 'class'])));
  },

  where(builder, nodes) {
    const random = builder.random;
    const target = pickTarget(builder, nodes);
    const ancestor = random.pick(ancestors(target));
    const inner = `${simpleSelector(builder, ancestor, 'class')} ${simpleSelector(builder, target, random.pick(['class', 'type-class']))}`;
    const shape = random.pick(['wrapped-context', 'wrapped-all', 'suffix']);
    const selector = {
      'wrapped-context': `:where(${simpleSelector(builder, ancestor, 'class')}) ${target.tag}`,
      'wrapped-all': `:where(${inner})`,
      suffix: `${target.tag}:where(${simpleSelector(builder, target, 'class')})`,
    }[shape];
    compete(builder, selector, random.pick([target.tag, simpleSelector(builder, target, 'class')]));
  },

  is(builder, nodes) {
    const random = builder.random;
    const target = pickTarget(builder, nodes);
    const ancestor = random.pick(ancestors(target));
    const strong = random.chance(0.5) ? `#${ancestor.id ?? simpleSelector(builder, ancestor, 'id').slice(1)}` : simpleSelector(builder, ancestor, 'class');
    const selector = `:is(${simpleSelector(builder, ancestor, 'type')}, ${strong}) ${target.tag}`;
    compete(builder, selector, `${simpleSelector(builder, ancestor, 'class')} ${simpleSelector(builder, target, 'class')}`);
  },

  not(builder, nodes) {
    const random = builder.random;
    const target = pickTarget(builder, nodes);
    const excluded = builder.className('muted');
    const shape = random.pick(['self', 'children']);
    const selector = shape === 'self'
      ? `${target.tag}:not(.${excluded})`
      : `${simpleSelector(builder, target.parent, 'class')} > :not(${random.pick(['h2', 'h3', 'blockquote', `.${excluded}`])})`;
    compete(builder, selector, simpleSelector(builder, target, random.pick(['class', 'type'])));
  },

  attribute(builder, nodes) {
    const random = builder.random;
    const target = pickTarget(builder, nodes);
    const tone = random.pick(['warm', 'cool', 'muted', 'bold']);
    target.attrs['data-tone'] = tone;
    const shape = random.pick(['present', 'equals', 'prefix', 'substring', 'insensitive', 'class-word', 'lang']);
    let selector;
    switch (shape) {
      case 'present':
        selector = '[data-tone]';
        break;
      case 'equals':
        selector = `${target.tag}[data-tone="${tone}"]`;
        break;
      case 'prefix':
        selector = `[data-tone^="${tone.slice(0, 2)}"]`;
        break;
      case 'substring':
        selector = `[data-tone*="${tone.slice(1, 3)}"]`;
        break;
      case 'insensitive':
        selector = `[data-tone="${tone.toUpperCase()}" i]`;
        break;
      case 'class-word':
        selector = `[class~="${target.classes[0] ?? builder.classFor(target)}"]`;
        break;
      case 'lang':
        target.attrs.lang = random.pick(['en-GB', 'en-US', 'fr-CA']);
        selector = `[lang|="${target.attrs.lang.slice(0, 2)}"]`;
        break;
    }
    builder.tag(`selectors:attribute-${shape}`);
    compete(builder, selector, simpleSelector(builder, target, random.pick(['type', 'class'])));
  },

  'specificity-tie'(builder, nodes) {
    const random = builder.random;
    const target = pickTarget(builder, nodes);
    const ancestor = random.pick(ancestors(target));
    const selectorPairs = [
      [`${simpleSelector(builder, ancestor, 'class')} ${target.tag}`, `${target.tag}.${target.classes[0] ?? builder.classFor(target)}`],
      [simpleSelector(builder, target, 'class'), simpleSelector(builder, target, 'attribute')],
      [`${ancestor.tag} ${simpleSelector(builder, target, 'class')}`, `${simpleSelector(builder, target, 'type-class')}`],
    ];
    const [first, second] = random.pick(selectorPairs);
    compete(builder, first, second);
  },
};
