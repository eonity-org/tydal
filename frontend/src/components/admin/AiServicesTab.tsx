import { useState, useEffect, useCallback } from 'react'
import {
  Box, Button, Chip, CircularProgress, Collapse, Divider, Stack, Typography, Tooltip,
} from '@mui/material'
import {
  Hub, Chat, Visibility, CheckCircle, Error as ErrorIcon, PlayArrow, InfoOutlined, Refresh,
  ExpandMore, ExpandLess,
} from '@mui/icons-material'
import adminService, { AiConfig, AiParam, AiTestResult } from '../../api/adminService'

// ─── Types ────────────────────────────────────────────────────────────────────

type ServiceKey = 'embedding' | 'chat' | 'vision'
type TestStatus = 'idle' | 'running' | 'ok' | 'error'

interface ServiceState {
  status: TestStatus
  result: AiTestResult | null
}

const INITIAL_STATE: ServiceState = { status: 'idle', result: null }

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Ollama stores models as `name:tag`; strip `:latest` for display/matching. */
function normalizeModel(name: string): string {
  return name.endsWith(':latest') ? name.slice(0, -7) : name
}

function ollamaHasModel(pulled: string[], configured: string): boolean {
  const norm = normalizeModel(configured)
  return pulled.some(m => normalizeModel(m) === norm)
}

// ─── Constants ────────────────────────────────────────────────────────────────

const SERVICES: { key: ServiceKey; label: string; Icon: React.ElementType; description: string }[] = [
  { key: 'embedding', label: 'Embeddings',  Icon: Hub,        description: 'Vectorises text for RAG search' },
  { key: 'chat',      label: 'Text (LLM)',  Icon: Chat,       description: 'RAG Q&A and text auto-tagging' },
  { key: 'vision',    label: 'Vision (LLM)',Icon: Visibility,  description: 'Image analysis and auto-tagging' },
]

const DRIVER_COLORS: Record<string, { bg: string; color: string }> = {
  ollama: { bg: '#e8f5e9', color: '#2e7d32' },
  openai: { bg: '#e3f2fd', color: '#1565c0' },
  claude: { bg: '#f3e5f5', color: '#6a1b9a' },
  gemini: { bg: '#fff3e0', color: '#e65100' },
  zai:    { bg: '#fce4ec', color: '#880e4f' },
  voyage: { bg: '#e8eaf6', color: '#283593' },
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function StatusChip({ status }: { status: TestStatus }) {
  if (status === 'idle')    return <Chip size="small" label="Idle"    sx={{ bgcolor: 'grey.100', color: 'text.secondary', fontWeight: 500 }} />
  if (status === 'running') return <Chip size="small" label="Running" sx={{ bgcolor: 'info.light', color: 'info.main', fontWeight: 500 }} icon={<CircularProgress size={12} sx={{ color: 'info.main !important' }} />} />
  if (status === 'ok')      return <Chip size="small" label="OK"      sx={{ bgcolor: 'success.light', color: 'success.main', fontWeight: 500 }} icon={<CheckCircle sx={{ fontSize: '14px !important' }} />} />
  return                          <Chip size="small" label="Failed"  sx={{ bgcolor: 'error.light', color: 'error.main', fontWeight: 500 }} icon={<ErrorIcon sx={{ fontSize: '14px !important' }} />} />
}

/**
 * Read-only summary of the live runtime parameters for a service (vector size,
 * chunk size, etc.). Kept visually separate from the test/result boxes so an
 * operator can verify the active configuration at a glance. Each row carries a
 * short hint (mirroring the .env docs) behind an info icon.
 */
function ConfigParams({ params }: { params?: AiParam[] }) {
  if (!params || params.length === 0) return null
  return (
    <Box
      sx={{
        border: '1px dashed',
        borderColor: 'divider',
        borderRadius: 1,
        px: 1.5,
        py: 1,
        bgcolor: 'grey.50',
      }}
    >
      <Typography variant="body2" fontSize="0.72rem" fontWeight={600} color="text.secondary" sx={{ mb: 0.5 }}>
        Configuration
      </Typography>
      <Stack spacing={0.4}>
        {params.map(p => (
          <Stack key={p.label} direction="row" alignItems="center" spacing={0.5} justifyContent="space-between">
            <Stack direction="row" alignItems="center" spacing={0.5}>
              <Typography variant="body2" fontSize="0.73rem" color="text.secondary">
                {p.label}
              </Typography>
              <Tooltip title={p.hint} arrow>
                <InfoOutlined sx={{ fontSize: 13, color: 'text.disabled', cursor: 'help' }} />
              </Tooltip>
            </Stack>
            <Typography variant="body2" fontSize="0.73rem" sx={{ fontFamily: 'monospace', fontWeight: 600 }}>
              {p.value}
            </Typography>
          </Stack>
        ))}
      </Stack>
    </Box>
  )
}

function DriverBadge({ driver }: { driver: string }) {
  const style = DRIVER_COLORS[driver] ?? { bg: 'grey.100', color: 'text.secondary' }
  return (
    <Chip
      size="small"
      label={driver}
      sx={{ bgcolor: style.bg, color: style.color, fontWeight: 600, fontSize: '0.7rem', letterSpacing: '0.02em' }}
    />
  )
}

/**
 * Collapsible guide explaining what the three model roles do and — more
 * importantly — the settings the operator must keep consistent (vector size ↔
 * index, chunk size ↔ context window, pulled models, multimodal vision). Mirrors
 * the "Role & Relation guide" pattern used in the file-upload panel.
 */
const MODEL_ROLES: { label: string; Icon: React.ElementType; desc: string }[] = [
  { label: 'Embeddings', Icon: Hub,        desc: 'Turns text chunks into vectors for semantic / RAG search. Fixed output size per model.' },
  { label: 'Text (LLM)', Icon: Chat,       desc: 'Answers questions over your content (RAG Q&A) and auto-tags documents.' },
  { label: 'Vision (LLM)', Icon: Visibility, desc: 'Describes and tags images — requires a multimodal model.' },
]

const CONSISTENCY_RULES: { label: string; desc: string }[] = [
  { label: 'Vector size ↔ index', desc: 'EMBEDDING_DIMENSIONS must equal the embed model’s output size AND the Elasticsearch chunks index. After changing it: search:setup-indices --recreate + search:reindex.' },
  { label: 'Chunk size ↔ context', desc: 'A text chunk must fit the embed model’s token context window, or embedding fails (HTTP 400 "input length exceeds the context length"). e.g. mxbai-embed-large 512 tok → ~256 words; nomic-embed-text 2048 tok → 400.' },
  { label: 'Models pulled (Ollama)', desc: 'Every configured model must be pulled locally — ollama pull <model>. Models missing from the instance are flagged in red above.' },
  { label: 'Vision is multimodal', desc: 'The vision driver/model must be multimodal (e.g. llama3.2-vision). A text-only model cannot process images.' },
  { label: 'Switching embed model', desc: 'A different embed model usually means a different vector size — recreate the index and re-embed, or k-NN search breaks.' },
]

function ModelsGuide() {
  const [expanded, setExpanded] = useState(false)
  return (
    <Box sx={{ mb: 3 }}>
      <Stack
        direction="row"
        alignItems="flex-start"
        spacing={0.75}
        sx={{ cursor: 'pointer', userSelect: 'none' }}
        onClick={() => { setExpanded(v => !v) }}
      >
        <InfoOutlined sx={{ fontSize: 18, color: 'info.main', flexShrink: 0, mt: '1px' }} />
        <Box sx={{ flex: 1 }}>
          <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', display: 'block' }}>
            Models &amp; consistency guide
          </Typography>
          {!expanded && (
            <Typography variant="caption" color="text.disabled" sx={{ display: 'block' }}>
              What each model role does — and the settings you must keep in sync to avoid indexing/search failures.
            </Typography>
          )}
        </Box>
        {expanded
          ? <ExpandLess sx={{ fontSize: '1rem', color: 'text.disabled', flexShrink: 0 }} />
          : <ExpandMore sx={{ fontSize: '1rem', color: 'text.disabled', flexShrink: 0 }} />}
      </Stack>

      <Collapse in={expanded}>
        <Stack spacing={1.5} sx={{ pl: 3, pt: 1 }}>
          <Box>
            <Typography variant="caption" sx={{ fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'text.disabled', display: 'block', mb: 0.5 }}>
              Model roles
            </Typography>
            <Stack spacing={0.5}>
              {MODEL_ROLES.map(({ label, Icon, desc }) => (
                <Stack key={label} direction="row" spacing={1} alignItems="flex-start">
                  <Icon sx={{ fontSize: 15, color: 'text.secondary', mt: 0.2, flexShrink: 0 }} />
                  <Typography variant="caption" color="text.secondary">
                    <Box component="span" sx={{ fontWeight: 600 }}>{label}</Box> — {desc}
                  </Typography>
                </Stack>
              ))}
            </Stack>
          </Box>

          <Divider sx={{ my: 0.25 }} />

          <Box>
            <Typography variant="caption" sx={{ fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'text.disabled', display: 'block', mb: 0.5 }}>
              Keep in sync <Box component="span" sx={{ fontWeight: 400, textTransform: 'none', letterSpacing: 0 }}>(common inconsistencies)</Box>
            </Typography>
            <Stack spacing={0.5}>
              {CONSISTENCY_RULES.map(({ label, desc }) => (
                <Stack key={label} direction="row" spacing={1} alignItems="flex-start">
                  <Chip label={label} size="small" variant="outlined" sx={{ height: 18, fontSize: '0.65rem', mt: 0.2, flexShrink: 0 }} />
                  <Typography variant="caption" color="text.secondary">{desc}</Typography>
                </Stack>
              ))}
            </Stack>
          </Box>
        </Stack>
      </Collapse>
    </Box>
  )
}

// ─── Main Component ───────────────────────────────────────────────────────────

function AiServicesTab() {
  const [config, setConfig] = useState<AiConfig | null>(null)
  const [configLoading, setConfigLoading] = useState(true)
  const [ollamaModels, setOllamaModels] = useState<string[] | null>(null)
  const [ollamaModelsLoading, setOllamaModelsLoading] = useState(false)

  const [states, setStates] = useState<Record<ServiceKey, ServiceState>>({
    embedding: INITIAL_STATE,
    chat:      INITIAL_STATE,
    vision:    INITIAL_STATE,
  })

  const refreshOllamaModels = useCallback(() => {
    setOllamaModelsLoading(true)
    adminService.ai.ollamaModels()
      .then(r => setOllamaModels(r.data.models))
      .catch(() => setOllamaModels([]))
      .finally(() => setOllamaModelsLoading(false))
  }, [])

  // Load config on mount
  useEffect(() => {
    let cancelled = false
    adminService.ai.getConfig()
      .then(res => {
        if (cancelled) return
        setConfig(res.data)
        // If any service uses ollama, fetch available models
        const usesOllama = Object.values(res.data).some(s => s.driver === 'ollama')
        if (usesOllama) refreshOllamaModels()
      })
      .catch(() => { /* config stays null */ })
      .finally(() => { if (!cancelled) setConfigLoading(false) })
    return () => { cancelled = true }
  }, [])

  const setServiceState = useCallback((key: ServiceKey, patch: Partial<ServiceState>) => {
    setStates(prev => ({ ...prev, [key]: { ...prev[key], ...patch } }))
  }, [])

  const runTest = useCallback(async (key: ServiceKey) => {
    setServiceState(key, { status: 'running', result: null })
    try {
      const fn = key === 'embedding'
        ? adminService.ai.testEmbedding
        : key === 'chat'
        ? adminService.ai.testChat
        : adminService.ai.testVision

      const res = await fn()
      setServiceState(key, {
        status: res.data.ok ? 'ok' : 'error',
        result: res.data,
      })
    } catch (e: unknown) {
      const message = e instanceof Error ? e.message : 'Request failed'
      setServiceState(key, { status: 'error', result: { ok: false, detail: message, duration_ms: 0 } })
    }
  }, [setServiceState])

  const runAll = useCallback(async () => {
    await Promise.all(SERVICES.map(s => runTest(s.key)))
  }, [runTest])

  const anyRunning = Object.values(states).some(s => s.status === 'running')

  return (
    <Box>
      {/* Header */}
      <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 3 }}>
        <Box>
          <Typography variant="h6" fontWeight={600}>AI Services</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.25 }}>
            Test connectivity to the configured AI providers
          </Typography>
        </Box>
        <Button
          variant="contained"
          size="small"
          startIcon={anyRunning ? <CircularProgress size={14} sx={{ color: 'inherit' }} /> : <PlayArrow />}
          onClick={runAll}
          disabled={anyRunning}
          disableElevation
        >
          Run All Tests
        </Button>
      </Stack>

      {/* Models & consistency guide */}
      <ModelsGuide />

      {/* Service cards */}
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' }, gap: 2 }}>
        {SERVICES.map(({ key, label, Icon, description }) => {
          const info   = config?.[key]
          const state  = states[key]

          return (
            <Box
              key={key}
              sx={{
                border: '1px solid',
                borderColor: state.status === 'ok'    ? 'success.main'
                           : state.status === 'error' ? 'error.main'
                           : 'divider',
                borderRadius: 2,
                p: 2.5,
                bgcolor: 'background.paper',
                display: 'flex',
                flexDirection: 'column',
                gap: 1.5,
                transition: 'border-color 0.2s',
              }}
            >
              {/* Card header */}
              <Stack direction="row" justifyContent="space-between" alignItems="center">
                <Stack direction="row" spacing={1} alignItems="center">
                  <Icon sx={{ fontSize: 18, color: 'text.secondary' }} />
                  <Typography fontWeight={600} fontSize="0.95rem">{label}</Typography>
                </Stack>
                <StatusChip status={state.status} />
              </Stack>

              {/* Description */}
              <Typography variant="body2" color="text.secondary" fontSize="0.8rem">
                {description}
              </Typography>

              {/* Driver / model info */}
              {configLoading ? (
                <CircularProgress size={16} />
              ) : info ? (
                <>
                  <Stack direction="row" spacing={0.75} alignItems="center" flexWrap="wrap">
                    <DriverBadge driver={info.driver} />
                    <Typography
                      variant="body2"
                      color="text.secondary"
                      fontSize="0.78rem"
                      sx={{
                        fontFamily: 'monospace',
                        color: ollamaModels !== null && info.driver === 'ollama' && !ollamaHasModel(ollamaModels, info.model)
                          ? 'error.main'
                          : 'text.secondary',
                      }}
                    >
                      {info.model}
                    </Typography>
                    {ollamaModels !== null && info.driver === 'ollama' && !ollamaHasModel(ollamaModels, info.model) && (
                      <Tooltip title={`Model not available. Run: ollama pull ${normalizeModel(info.model)}`} arrow>
                        <InfoOutlined sx={{ fontSize: 15, color: 'error.main', cursor: 'help' }} />
                      </Tooltip>
                    )}
                  </Stack>

                  {/* Pulled Ollama models — shown when driver is ollama */}
                  {info.driver === 'ollama' && ollamaModels !== null && (
                    <Box>
                      <Stack direction="row" alignItems="center" spacing={0.5} sx={{ mb: 0.5 }}>
                        <Typography variant="body2" fontSize="0.72rem" color="text.secondary">
                          Pulled models:
                        </Typography>
                        <Tooltip title="Refresh model list" arrow>
                          <span>
                            <Button
                              size="small"
                              onClick={refreshOllamaModels}
                              disabled={ollamaModelsLoading}
                              sx={{ minWidth: 0, p: 0.25 }}
                            >
                              {ollamaModelsLoading
                                ? <CircularProgress size={11} />
                                : <Refresh sx={{ fontSize: 13 }} />}
                            </Button>
                          </span>
                        </Tooltip>
                      </Stack>
                      {ollamaModels.length === 0 ? (
                        <Typography variant="body2" fontSize="0.75rem" color="error.main">
                          None — run <code>ollama pull &lt;model&gt;</code>
                        </Typography>
                      ) : (
                        <Stack direction="row" flexWrap="wrap" gap={0.5}>
                          {ollamaModels.map(m => (
                            <Chip
                              key={m}
                              label={m}
                              size="small"
                              sx={{
                                fontSize: '0.68rem',
                                fontFamily: 'monospace',
                                bgcolor: normalizeModel(m) === normalizeModel(info.model) ? 'success.light' : 'grey.100',
                                color:   normalizeModel(m) === normalizeModel(info.model) ? 'success.main'  : 'text.secondary',
                                height: 20,
                              }}
                            />
                          ))}
                        </Stack>
                      )}
                    </Box>
                  )}
                </>
              ) : (
                <Typography variant="body2" color="text.secondary" fontSize="0.8rem">—</Typography>
              )}

              {/* Active configuration (read-only) — separate from the test/result boxes */}
              {info && <ConfigParams params={info.params} />}

              {/* Result area */}
              {state.result && (
                <Box
                  sx={{
                    bgcolor: state.result.ok ? 'success.light' : 'error.light',
                    borderRadius: 1,
                    px: 1.5,
                    py: 1,
                  }}
                >
                  <Typography
                    variant="body2"
                    fontSize="0.82rem"
                    color={state.result.ok ? 'success.main' : 'error.main'}
                    sx={{ wordBreak: 'break-word' }}
                  >
                    {state.result.detail}
                  </Typography>
                  {state.result.duration_ms > 0 && (
                    <Typography variant="body2" fontSize="0.75rem" color="text.secondary" sx={{ mt: 0.25 }}>
                      {state.result.duration_ms} ms
                    </Typography>
                  )}
                </Box>
              )}

              {/* Test button */}
              <Button
                variant="outlined"
                size="small"
                onClick={() => runTest(key)}
                disabled={state.status === 'running'}
                startIcon={state.status === 'running'
                  ? <CircularProgress size={12} sx={{ color: 'inherit' }} />
                  : <PlayArrow />}
                sx={{ mt: 'auto', alignSelf: 'flex-start' }}
              >
                {state.status === 'running' ? 'Testing…' : 'Test'}
              </Button>
            </Box>
          )
        })}
      </Box>
    </Box>
  )
}

export default AiServicesTab
