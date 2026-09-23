import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['src/__tests__/**/*.test.ts'],
    // Dummy env so config.ts does not throw during collection; axios-mock-adapter
    // intercepts all real HTTP so no running TYDAL instance is needed.
    env: {
      TYDAL_BASE_URL: 'http://localhost:8000',
      TYDAL_VAULT: 'acme/press-kit',
      TYDAL_VAULT_KEY: 'tvk_test-key-000000000000000000000000000000',
      TYDAL_VAULT_WRITE_KEY: 'tvk_write-key-00000000000000000000000000000',
    },
  },
});
