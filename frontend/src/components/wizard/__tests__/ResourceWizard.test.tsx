/**
 * The New Resources wizard in collections whose scheme has required fields
 * (#9), and Auto mode not hiding its failures (#10).
 *
 * The wizard creates every resource at the Upload step, before Review, and the
 * server refuses one whose required scheme fields are empty. So step 1 asks for
 * them once for the whole upload and every create-resource call carries them.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { ResourceWizard } from '../ResourceWizard'
import { ModeUploadStep } from '../steps/ModeUploadStep'
import type { SchemeField } from '../../../api/collectionService'

// Plain vi.fn()s (not methods read off the mocked services), so assertions
// don't trip @typescript-eslint/unbound-method.
const api = vi.hoisted(() => ({
  getCollection: vi.fn(),
  createResource: vi.fn(),
  uploadFile: vi.fn(),
  updateResource: vi.fn(),
  getAityStatus: vi.fn(),
  createAityReviewBatch: vi.fn(),
  addResource: vi.fn(),
}))

vi.mock('../../../api/collectionService', () => ({
  default: { getCollection: api.getCollection },
}))
vi.mock('../../../api/resourceService', () => ({
  default: {
    createResource: api.createResource,
    uploadFile: api.uploadFile,
    updateResource: api.updateResource,
    deleteResource: vi.fn(),
    setFileSnapshot: vi.fn(),
    aityEnrichFile: vi.fn(),
    getAityStatus: api.getAityStatus,
  },
}))
vi.mock('../../../api/workspaceService', () => ({
  default: { createAityReviewBatch: api.createAityReviewBatch, addResource: api.addResource },
}))
vi.mock('../../../api/semanticTagService', () => ({
  default: { create: vi.fn(), syncResource: vi.fn() },
}))
vi.mock('../../../api/aityService', () => ({
  dispatchAutoApproveWorkspace: vi.fn(),
}))

function field(overrides: Partial<SchemeField>): SchemeField {
  return {
    name: 'field',
    display_name: 'Field',
    type: 'string',
    required: false,
    storage: 'metadata',
    is_facet: false,
    display_in_form: true,
    order: 0,
    ...overrides,
  }
}

const AUTHOR = field({ name: 'author', display_name: 'Author', required: true, order: 1 })
const YEAR = field({ name: 'year', display_name: 'Year', type: 'integer', order: 2 })

function collectionWith(fields: SchemeField[]) {
  return {
    id: 1,
    name: 'Photos',
    scheme: { id: 's', name: 'photo', display_name: 'Photo', fields, accepted_mimetypes: [] },
  } as never
}

const photo = () => new File(['x'], 'army-0001.jpg', { type: 'image/jpeg' })

async function chooseBatchAndFile(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByText('Each file becomes a separate resource'))
  // The dialog renders in a portal, outside render()'s container.
  const input = document.querySelector('input[type="file"]') as HTMLInputElement
  await user.upload(input, photo())
  await screen.findByText('army-0001.jpg')
}

const nextButton = () => screen.getByRole('button', { name: /Next/ })

describe('ModeUploadStep — "Required by this collection" panel', () => {
  const base = {
    mode: 'batch' as const,
    files: [],
    collectionMimeTypes: [],
    onModeChange: () => {},
    onFilesChange: () => {},
  }

  it('is absent when the scheme has no required fields', () => {
    render(<ModeUploadStep {...base} requiredFields={[]} />)
    expect(screen.queryByText('Required by this collection')).toBeNull()
  })

  it('shows one input per required field, with the batch-wide helper text', () => {
    render(<ModeUploadStep {...base} requiredFields={[AUTHOR]} requiredValues={{}} />)
    expect(screen.getByText('Required by this collection')).not.toBeNull()
    expect(screen.getByLabelText(/Author/)).not.toBeNull()
    expect(screen.getByText('Applied to every resource; you can change it per resource in Review.')).not.toBeNull()
    expect(screen.getByText('Fill in Author to continue.')).not.toBeNull()
  })
})

describe('ResourceWizard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.createResource.mockResolvedValue({ id: 'r1' } as never)
    api.uploadFile.mockResolvedValue({ id: 'f1' } as never)
    api.updateResource.mockResolvedValue({ id: 'r1' } as never)
    api.getAityStatus.mockResolvedValue(null as never)
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:preview')
  })

  it('shows no required panel for a scheme without required fields, and creates with no metadata', async () => {
    api.getCollection.mockResolvedValue(collectionWith([YEAR]))
    const user = userEvent.setup()
    render(<ResourceWizard open collectionId={1} onClose={() => {}} onSaved={() => {}} />)

    await chooseBatchAndFile(user)
    expect(screen.queryByText('Required by this collection')).toBeNull()
    expect(nextButton()).toHaveProperty('disabled', false)

    await user.click(nextButton())
    await waitFor(() => expect(api.createResource).toHaveBeenCalled())
    expect(api.createResource.mock.calls[0][0]).not.toHaveProperty('metadata')
  })

  it('keeps Next disabled until every required field is filled, then sends the values as metadata', async () => {
    api.getCollection.mockResolvedValue(collectionWith([AUTHOR, YEAR]))
    const user = userEvent.setup()
    render(<ResourceWizard open collectionId={1} onClose={() => {}} onSaved={() => {}} />)

    await chooseBatchAndFile(user)
    expect(await screen.findByText('Required by this collection')).not.toBeNull()
    // Only the required field is asked for here; optional ones stay in Review.
    expect(screen.queryByRole('spinbutton', { name: /Year/ })).toBeNull()
    expect(nextButton()).toHaveProperty('disabled', true)
    expect(screen.getByText('Fill in Author to continue.')).not.toBeNull()

    await user.type(screen.getByRole('textbox', { name: /Author/ }), 'Robert Capa')
    expect(nextButton()).toHaveProperty('disabled', false)
    expect(screen.queryByText('Fill in Author to continue.')).toBeNull()

    await user.click(nextButton())
    await waitFor(() => expect(api.createResource).toHaveBeenCalledTimes(1))
    expect(api.createResource).toHaveBeenCalledWith(expect.objectContaining({
      collection_id: 1,
      state: 'draft',
      metadata: { author: 'Robert Capa' },
    }))
  })

  it('says why when every upload fails, and offers no way forward', async () => {
    api.getCollection.mockResolvedValue(collectionWith([YEAR]))
    api.createResource.mockRejectedValue(
      new Error('Metadata does not match the collection schema.'),
    )
    const user = userEvent.setup()
    render(<ResourceWizard open collectionId={1} onClose={() => {}} onSaved={() => {}} />)

    await chooseBatchAndFile(user)
    await user.click(nextButton())

    expect(await screen.findByText('Nothing was uploaded, so there is nothing to review.')).not.toBeNull()
    expect(screen.getAllByText('Metadata does not match the collection schema.').length).toBeGreaterThan(0)
    expect(screen.queryByText('Choose how to proceed:')).toBeNull()
    expect(screen.getByRole('button', { name: /Interactive/ })).toHaveProperty('disabled', true)
  })

  it('keeps the wizard open with the reason when Auto cannot create the batch', async () => {
    api.getCollection.mockResolvedValue(collectionWith([YEAR]))
    api.createAityReviewBatch.mockRejectedValue(new Error('This action is unauthorized.'))
    const onClose = vi.fn()
    const user = userEvent.setup()
    render(<ResourceWizard open collectionId={1} onClose={onClose} onSaved={() => {}} />)

    await chooseBatchAndFile(user)
    await user.click(nextButton())
    await screen.findByText('1 resource uploaded')

    await user.click(screen.getByText('Auto'))
    await user.click(screen.getByRole('button', { name: /Save & exit/ }))

    expect(await screen.findByText('Could not create the AiTy Review batch: This action is unauthorized.')).not.toBeNull()
    expect(onClose).not.toHaveBeenCalled()
  })
})
