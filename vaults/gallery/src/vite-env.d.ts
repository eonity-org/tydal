/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_TYDAL_URL?: string
  readonly VITE_VAULT?: string
  readonly VITE_VAULT_KEY?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
