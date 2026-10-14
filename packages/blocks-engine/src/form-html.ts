import type { CheerioAPI } from 'cheerio';

import type { SectionSpecForm, SectionSpecFormField } from './theme/section-spec.js';

type FormNode = NonNullable<Parameters<CheerioAPI>[0]> & { tagName: string };
type Control = ReturnType<CheerioAPI>;

const SIMPLE_INPUT_KINDS: Record<string, SectionSpecFormField['kind']> = {
  text: 'text',
  password: 'text',
  search: 'text',
  email: 'email',
  tel: 'tel',
  url: 'url',
  number: 'number',
  date: 'date',
};

function collapse(s: string): string {
  return s.replace(/\s+/g, ' ').trim();
}

function attrText($control: Control, attr: string): string {
  return collapse($control.attr(attr) ?? '');
}

function isRequired($control: Control): boolean {
  return $control.attr('required') !== undefined || $control.attr('aria-required') === 'true';
}

function controlLabel($: CheerioAPI, $form: ReturnType<CheerioAPI>, $control: Control): string {
  const id = $control.attr('id');
  if (id) {
    const forLabel = $form
      .find('label')
      .filter((_, label) => $(label).attr('for') === id)
      .first();
    if (forLabel.length > 0) return collapse(forLabel.text());
  }
  const wrapping = $control.closest('label');
  if (wrapping.length > 0) {
    return collapse(
      wrapping
        .clone()
        .children('input,select,textarea')
        .remove()
        .end()
        .text(),
    );
  }
  return attrText($control, 'aria-label') || attrText($control, 'placeholder') || attrText($control, 'name');
}

function choiceOption($: CheerioAPI, $form: ReturnType<CheerioAPI>, $control: Control): string {
  return controlLabel($, $form, $control) || attrText($control, 'value');
}

function selectOptions($: CheerioAPI, $control: Control): string[] {
  return $control
    .children('option')
    .toArray()
    .map((option) => $(option))
    .filter(($option) => ($option.attr('value') ?? '') !== '')
    .map(($option) => collapse($option.text()))
    .filter(Boolean);
}

function submitLabel($: CheerioAPI, $form: ReturnType<CheerioAPI>): string {
  const typelessButton = $form
    .find('button')
    .toArray()
    .find((button) => $(button).attr('type') === undefined);
  const candidates = [
    $form.find('button[type="submit"]').first(),
    typelessButton ? $(typelessButton) : $(),
    $form.find('input[type="submit"]').first(),
  ];
  for (const candidate of candidates) {
    if (candidate.length === 0) continue;
    const label = candidate.is('input') ? attrText(candidate, 'value') : collapse(candidate.text());
    if (label) return label;
  }
  return 'Submit';
}

function compact(field: SectionSpecFormField): SectionSpecFormField {
  const entries = Object.entries(field).filter(([, value]) => value !== undefined);
  if (Array.isArray(entries.find(([key]) => key === 'options')?.[1])) {
    const options = entries.find(([key]) => key === 'options')?.[1] as string[];
    if (options.length === 0) {
      return Object.fromEntries(entries.filter(([key]) => key !== 'options')) as SectionSpecFormField;
    }
  }
  return Object.fromEntries(entries) as SectionSpecFormField;
}

/**
 * Parse a DOM `<form>` element into the engine's SectionSpecForm grammar so it
 * can be emitted through the same `formToBlocks` mapper the theme assembler
 * uses — one form grammar, one block idiom (jetpack/contact-form).
 *
 * Returns null for forms with no representable fields (degenerate or
 * layout-only forms), leaving them on the caller's fallback path.
 *
 * Determinism: document-order traversal, stable key order, no ids — two calls
 * on the same markup are byte-identical.
 */
export function parseHtmlForm($: CheerioAPI, form: FormNode): SectionSpecForm | null {
  const $form = $(form);
  const controls = $form.find('input,textarea,select').toArray();

  const groups = new Map<
    string,
    { kind: 'radio' | 'checkbox'; field: SectionSpecFormField; count: number }
  >();
  const singleCheckboxes: SectionSpecFormField[] = [];
  const fields: SectionSpecFormField[] = [];

  for (const control of controls) {
    const $control = $(control);
    const tag = control.tagName;

    if (tag === 'textarea') {
      fields.push(
        compact({
          kind: 'textarea',
          label: controlLabel($, $form, $control),
          required: isRequired($control),
          placeholder: attrText($control, 'placeholder') || undefined,
          defaultValue: collapse($control.text()) || undefined,
        }),
      );
      continue;
    }

    if (tag === 'select') {
      fields.push(
        compact({
          kind: 'select',
          label: controlLabel($, $form, $control),
          required: isRequired($control),
          options: selectOptions($, $control),
        }),
      );
      continue;
    }

    const type = ($control.attr('type') || 'text').toLowerCase();
    if (type === 'submit' || type === 'button' || type === 'reset' || type === 'image') continue;

    if (type === 'radio' || type === 'checkbox') {
      const name = $control.attr('name') || '';
      const option = choiceOption($, $form, $control);
      const existing = name ? groups.get(name) : undefined;
      if (existing) {
        existing.count += 1;
        if (option) existing.field.options = [...(existing.field.options ?? []), option];
        continue;
      }
      const field: SectionSpecFormField = compact({
        kind: type,
        label: controlLabel($, $form, $control),
        required: isRequired($control),
        options: option ? [option] : undefined,
      });
      if (name) groups.set(name, { kind: type, field, count: 1 });
      else if (type === 'checkbox') singleCheckboxes.push(field);
      fields.push(field);
      continue;
    }

    const kind = SIMPLE_INPUT_KINDS[type] ?? (type === 'hidden' || type === 'file' ? type : 'text');
    fields.push(
      compact({
        kind,
        label: controlLabel($, $form, $control),
        required: isRequired($control),
        placeholder: attrText($control, 'placeholder') || undefined,
        defaultValue:
          kind === 'file' || kind === 'hidden'
            ? undefined
            : (attrText($control, 'value') || undefined),
      }),
    );
  }

  for (const group of groups.values()) {
    if (group.kind === 'checkbox' && group.count === 1) delete group.field.options;
  }
  for (const field of singleCheckboxes) delete field.options;

  if (fields.length === 0) return null;
  return { fields, submitLabel: submitLabel($, $form) };
}
