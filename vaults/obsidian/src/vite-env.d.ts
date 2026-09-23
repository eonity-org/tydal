/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_VAULT?: string
  readonly VITE_VAULT_KEY?: string
  readonly VITE_TYDAL_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
