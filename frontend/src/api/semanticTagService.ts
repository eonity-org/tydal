import { tydal } from './tydalClient'
import { TydalApiError } from '@tydal/client'
import type { SemanticTag, TagReviewer, TagVocabulary } from './resourceService'

export interface CreateSemanticTagData {
  label: string
  description?: string
  entity_type?: string
  vocabulary?: TagVocabulary
  reviewer?: TagReviewer
}

export interface UpdateSemanticTagData extends Partial<CreateSemanticTagData> {
  is_active?: boolean
}

const semanticTagService = {
  async list(search?: string, filters?: { vocabulary?: TagVocabulary; reviewer?: TagReviewer }): Promise<SemanticTag[]> {
    // SDK unwraps → the tag array.
    const tags = await tydal.semanticTags.list<SemanticTag[]>({
      search,
      vocabulary: filters?.vocabulary,
      reviewer: filters?.reviewer,
    })
    return tags ?? []
  },

  async create(payload: CreateSemanticTagData): Promise<SemanticTag> {
    try {
      return await tydal.semanticTags.create<SemanticTag>(payload as unknown as Record<string, unknown>)
    } catch (error) {
      throw new Error(tagError(error, 'Failed to save tag'))
    }
  },

  async update(id: number, payload: UpdateSemanticTagData): Promise<SemanticTag> {
    try {
      return await tydal.semanticTags.update<SemanticTag>(id, payload as unknown as Record<string, unknown>)
    } catch (error) {
      throw new Error(tagError(error, 'Failed to save tag'))
    }
  },

  async delete(id: number): Promise<void> {
    try {
      await tydal.semanticTags.delete(id)
    } catch (error) {
      throw new Error(tagError(error, 'Failed to delete tag'))
    }
  },

  async syncResource(resourceId: string | number, tagIds: number[]): Promise<SemanticTag[]> {
    try {
      return await tydal.semanticTags.syncResource<SemanticTag[]>(resourceId, tagIds)
    } catch (error) {
      throw new Error(tagError(error, 'Failed to sync tags'))
    }
  },

  async clearFileAiSuggestions(resourceId: string, fileId: string): Promise<void> {
    try {
      await tydal.resources.clearFileAiSuggestions(resourceId, fileId)
    } catch (error) {
      throw new Error(tagError(error, 'Failed to clear AI suggestions'))
    }
  },

}

/** Prefer the first Laravel validation error over the generic top-level message */
function tagError(error: unknown, fallback: string): string {
  if (error instanceof TydalApiError) {
    const first = error.errors ? Object.values(error.errors).flat()[0] : undefined
    return first || error.message || fallback
  }
  return error instanceof Error ? error.message : fallback
}

export default semanticTagService
