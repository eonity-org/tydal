/**
 * Mock data factory for TYDAL Frontend2 tests
 */

export interface ResourceData {
  id: number
  type: string
  name: string
  active: boolean
  data: {
    description?: {
      title?: string
      text?: string
      [key: string]: any
    }
    img?: string
    thumbnail?: string
    preview?: string
    [key: string]: any
  }
  previews?: string[]
  created_at?: string
  updated_at?: string
  collection_id?: number
  user_owner_id?: number
  [key: string]: any
}

export interface FacetValue {
  count: number
  selected: boolean
  radio: boolean
}

export interface Facet {
  key: string
  label: string
  values: Record<string, FacetValue>
}

export interface Collection {
  id: number
  name: string
  coll_resource_count: number
  resource_type: string | null
  max_num_file: number
}

export interface Organization {
  id: number
  name: string
  org_resource_count: number
  collections: Collection[]
}

export const mockOrganizations: Organization[] = [
  {
    id: 1,
    name: 'Test Organization',
    org_resource_count: 150,
    collections: [
      {
        id: 1,
        name: 'Multimedia',
        coll_resource_count: 50,
        resource_type: 'multimedia',
        max_num_file: 10,
      },
      {
        id: 2,
        name: 'Documents',
        coll_resource_count: 100,
        resource_type: 'document',
        max_num_file: 5,
      },
    ],
  },
]

export const mockCollections: Collection[] = [
  {
    id: 1,
    name: 'Multimedia',
    coll_resource_count: 50,
    resource_type: 'multimedia',
    max_num_file: 10,
  },
  {
    id: 2,
    name: 'Documents',
    coll_resource_count: 100,
    resource_type: 'document',
    max_num_file: 5,
  },
]

export const mockResources: ResourceData[] = [
  {
    id: 1,
    type: 'image',
    name: 'Test Resource 1',
    active: true,
    data: {
      description: {
        title: 'Test Resource 1',
        text: 'Description 1',
      },
      img: 'https://example.com/img1.jpg',
      thumbnail: 'https://example.com/thumb1.jpg',
    },
    collection_id: 1,
    user_owner_id: 1,
  },
  {
    id: 2,
    type: 'video',
    name: 'Test Resource 2',
    active: true,
    data: {
      description: {
        title: 'Test Resource 2',
        text: 'Description 2',
      },
      preview: 'https://example.com/preview2.jpg',
    },
    collection_id: 1,
    user_owner_id: 1,
  },
]

export const mockFacets: Facet[] = [
  {
    key: 'type',
    label: 'Type',
    values: {
      image: { count: 25, selected: false, radio: false },
      video: { count: 15, selected: false, radio: false },
      document: { count: 10, selected: false, radio: false },
    },
  },
  {
    key: 'status',
    label: 'Status',
    values: {
      published: { count: 40, selected: false, radio: true },
      draft: { count: 10, selected: false, radio: true },
    },
  },
]

export const mockUser = {
  id: 1,
  email: 'test@tydal.com',
  name: 'Test User',
  organizations: mockOrganizations,
  selected_org_data: mockOrganizations[0],
}

// Shape matches AuthController@login: { data: { user, token, expires_in } }.
// authService.login reads data.data.token (and optionally data.data.expires_in).
export const mockLoginResponse = {
  data: {
    user: mockUser,
    token: 'mock-jwt-token-12345',
    token_type: 'Bearer',
    expires_in: 31536000,
  },
}

export const mockCatalogueResponse = {
  data: mockResources,
  facets: mockFacets,
  total: 2,
  per_page: 48,
  current_page: 1,
  last_page: 1,
}
