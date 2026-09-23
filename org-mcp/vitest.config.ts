import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['src/__tests__/**/*.test.ts'],
    // Provide dummy env vars so config.ts does not throw during test collection.
    // axios-mock-adapter intercepts all real HTTP so no actual TYDAL instance is needed.
    env: {
      TYDAL_TOKEN:    '1|L3uQufFpEP86CTAZWihi7Iofe7aVlZTBr38fJjCNc4887d74',
      TYDAL_ORG_ID:   'a18665b0-c184-45ad-8c63-d57a66d84273',
      TYDAL_BASE_URL: 'http://localhost:8000/api/v1',
    },
  },
});
