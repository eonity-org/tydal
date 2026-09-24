# TYDAL Frontend

*The semantic layer between your files and your AI.*


Vite + React web application for the TYDAL API.

## What It Provides

- Authenticated vault dashboard
- Workspace and catalogue browsing
- Resource detail, editing, upload, preview, trash, and restore workflows
- Admin screens for organizations, users, collections, semantic tags, Vaults, and AI service checks
- AITY review and suggestion workflows
- File viewers for images, audio, and PDFs
- Reusable Material UI based components and theme support

## Stack

- React 18
- TypeScript 5.6
- Vite 6
- Material UI 6 with Emotion
- React Router 7
- Vitest and Testing Library

## Setup

```bash
npm install
cp .env.example .env
npm run dev
```

The development server runs on `http://localhost:3005` (configured in `vite.config.ts`).

Configure the backend URL in `.env`:

```env
VITE_API_BASE_URL=http://localhost:8000/api/v1
```

## Scripts

```bash
npm run dev
npm run build
npm run preview
npm run lint
npm run test
npm run test:run
npm run test:coverage
```

## Routes

- `/login` - public login page
- `/` - protected main dashboard
- `/designing` - protected design/demo page
- `/admin` - protected platform/admin UI
- `/trash` - protected resource trash page
- `/aity-review` - protected AITY review page

Unknown routes redirect to `/`.

## Project Structure

```text
src/api/          API service modules and tests
src/components/   Layout, resource, modal, admin, file-viewer, wizard, suggestion, and UI components
src/contexts/     Theme context
src/hooks/        Polling and notification hooks
src/pages/        Route-level pages
src/test/         Test fixtures and MSW mocks
src/theme/        Shared theme state helpers
src/utils/        Shared utility functions
```

## Authentication

The frontend uses the backend token returned by `POST /api/v1/login`. The token is stored in a browser cookie named `JWT` and sent as a Bearer token by the API service layer.

## Notes for Contributors

- Keep `VITE_API_BASE_URL` pointed at the backend `/api/v1` root.
- Prefer the existing API service modules over ad hoc `fetch` calls.
- Keep route-level behavior in `src/pages` and reusable behavior in `src/components`, `src/hooks`, or `src/api`.
- Run `npm run build` and `npm run test:run` before opening a pull request.
