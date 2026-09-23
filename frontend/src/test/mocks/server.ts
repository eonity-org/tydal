/**
 * MSW (Mock Service Worker) server setup for TYDAL Frontend2 tests
 */

import { setupServer } from 'msw/node'
import { handlers } from './handlers'

export const server = setupServer(...handlers)
