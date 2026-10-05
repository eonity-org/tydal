import type { SchemeField } from '../../api/collectionService'

/**
 * The scheme fields the server refuses to create a resource without:
 * `required` and stored in `resources.metadata` (CollectionSchemaService
 * only validates metadata-storage fields). The wizard asks for these at
 * step 1, because it creates every resource before the Review step.
 */
export function requiredMetadataFields(fields: SchemeField[]): SchemeField[] {
  return fields
    .filter((f) => f.required && (f.storage ?? 'metadata') === 'metadata')
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
}

/** Does `value` satisfy a required field? A boolean is always a value (an unset switch is `false`). */
export function hasFieldValue(field: SchemeField, value: unknown): boolean {
  if (field.type === 'boolean') return true
  if (value === undefined || value === null) return false
  if (Array.isArray(value)) return value.length > 0
  return String(value).trim() !== ''
}

/**
 * The step-1 values as a metadata document: only the required fields, with a
 * required boolean left untouched sent as `false` (the switch shows it off).
 */
export function requiredFieldsMetadata(
  fields: SchemeField[],
  values: Record<string, any>,
): Record<string, any> {
  const out: Record<string, any> = {}
  for (const f of fields) {
    if (f.type === 'boolean') out[f.name] = !!values[f.name]
    else if (hasFieldValue(f, values[f.name])) out[f.name] = values[f.name]
  }
  return out
}
