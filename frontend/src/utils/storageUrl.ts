import { API_BASE_URL } from '../constants/api'

export function resolveStorageUrl(url?: string | null): string {
  if (!url) return ''
  if (url.startsWith('blob:') || url.startsWith('data:')) return url
  if (url.startsWith('http://') || url.startsWith('https://')) return url

  if (url.startsWith('/storage/')) {
    return `${API_BASE_URL.replace(/\/api\/v1$/, '')}${url}`
  }

  return url
}
