import { type ResourceData } from '../api/resourceService'

export interface ValidationError {
  field: string
  message: string
}

/**
 * Validate resource data before saving
 * @param requiredSchemaFields - field names from the collection schema that are required (checked in resource.metadata)
 * @param requiredRootFields - root-level resource field names that are required (e.g. 'description')
 */
export const validateResourceData = (
  resource: ResourceData,
  requiredSchemaFields: string[] = [],
  requiredRootFields: string[] = [],
): ValidationError[] => {
  const errors: ValidationError[] = []

  // Validate name
  const name = resource.name || resource.data?.description?.name

  if (!name || typeof name !== 'string' || name.trim() === '') {
    errors.push({ field: 'name', message: 'Name is required' })
  } else if (name.length > 255) {
    errors.push({ field: 'name', message: 'Name must be less than 255 characters' })
  }

  // Validate type
  if (!resource.type || typeof resource.type !== 'string' || resource.type.trim() === '') {
    errors.push({ field: 'type', message: 'Type is required' })
  }

  // Validate required schema fields (from collection's custom_schema)
  for (const fieldName of requiredSchemaFields) {
    const value = resource.metadata?.[fieldName]
    if (value === undefined || value === null || String(value).trim() === '') {
      errors.push({ field: `metadata.${fieldName}`, message: 'This field is required' })
    }
  }

  // Validate description
  const description = resource.description ?? resource.data?.description?.description
  if (requiredRootFields.includes('description') && (!description || String(description).trim() === '')) {
    errors.push({ field: 'description', message: 'Description is required' })
  } else if (description && typeof description === 'string' && description.length > 5000) {
    errors.push({ field: 'description', message: 'Description must be less than 5000 characters' })
  }

  // Validate LOM JSON if present
  try {
    const lom = resource.data?.lom
    if (lom && typeof lom === 'object') {
      JSON.stringify(lom) // Check if it's serializable
    }
  } catch (err) {
    errors.push({ field: 'lom', message: 'LOM data contains invalid JSON' })
  }

  // Validate LOM-ES JSON if present
  try {
    const lomes = resource.data?.lomes
    if (lomes && typeof lomes === 'object') {
      JSON.stringify(lomes) // Check if it's serializable
    }
  } catch (err) {
    errors.push({ field: 'lomes', message: 'LOM-ES data contains invalid JSON' })
  }

  return errors
}

/**
 * Format validation errors for display
 */
export const formatValidationErrors = (errors: ValidationError[]): string => {
  if (errors.length === 0) return ''

  const errorMessages = errors.map(err => `• ${err.field}: ${err.message}`)
  return `Please fix the following errors:\n${errorMessages.join('\n')}`
}

/**
 * Check if resource has unsaved changes
 */
export const hasResourceChanges = (original: ResourceData | null, edited: ResourceData | null): boolean => {
  if (!original || !edited) return false

  // Compare key fields
  const hasChanged =
    original.name !== edited.name ||
    original.state !== edited.state ||
    original.type !== edited.type ||
    original.description !== edited.description ||
    original.metadata?.language !== edited.metadata?.language ||
    JSON.stringify(original.metadata) !== JSON.stringify(edited.metadata) ||
    JSON.stringify(original.data?.lom) !== JSON.stringify(edited.data?.lom) ||
    JSON.stringify(original.data?.lomes) !== JSON.stringify(edited.data?.lomes)

  return hasChanged
}
