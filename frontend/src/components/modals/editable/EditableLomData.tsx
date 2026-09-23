import { useState, useCallback } from 'react'
import {
  Box,
  Stack,
  Typography,
  TextField,
  Paper,
  Button,
  Alert,
  Divider,
  Accordion,
  AccordionSummary,
  AccordionDetails,
} from '@mui/material'
import { ExpandMore, FormatAlignLeft, CheckCircle, Error as ErrorIcon } from '@mui/icons-material'
import { type ResourceData } from '../../../api/resourceService'

export interface EditableLomDataProps {
  resource: ResourceData
  onChange: (resource: ResourceData) => void
}

/**
 * TYDAL EditableLomData Component
 *
 * Editable JSON editor for LOM and LOM-ES metadata.
 *
 * @example
 * ```tsx
 * <EditableLomData
 *   resource={editedResource}
 *   onChange={(updated) => setEditedResource(updated)}
 * />
 * ```
 */
function EditableLomData(props: EditableLomDataProps) {
  const { resource, onChange } = props

  const [lomJson, setLomJson] = useState<string>(
    JSON.stringify(resource.metadata?.lom || {}, null, 2)
  )
  const [lomesJson, setLomesJson] = useState<string>(
    JSON.stringify(resource.metadata?.lomes || {}, null, 2)
  )
  const [lomError, setLomError] = useState<string>('')
  const [lomesError, setLomesError] = useState<string>('')

  // Validate JSON string
  const validateJson = useCallback((jsonString: string): boolean => {
    if (!jsonString.trim()) return true // Empty is valid
    try {
      JSON.parse(jsonString)
      return true
    } catch (err) {
      return false
    }
  }, [])

  // Format JSON
  const formatJson = useCallback((jsonString: string): string => {
    if (!jsonString.trim()) return ''
    try {
      const parsed = JSON.parse(jsonString)
      return JSON.stringify(parsed, null, 2)
    } catch (err) {
      return jsonString
    }
  }, [])

  // Handle LOM JSON change
  const handleLomChange = useCallback((event: React.ChangeEvent<HTMLTextAreaElement>) => {
    const value = event.target.value
    setLomJson(value)

    if (validateJson(value)) {
      setLomError('')

      // Update resource metadata.lom
      const parsed = value.trim() ? JSON.parse(value) : {}
      const updated = {
        ...resource,
        metadata: {
          ...resource.metadata,
          lom: parsed,
        },
      }
      onChange(updated)
    } else {
      setLomError('Invalid JSON format')
    }
  }, [resource, onChange, validateJson])

  // Handle LOM-ES JSON change
  const handleLomesChange = useCallback((event: React.ChangeEvent<HTMLTextAreaElement>) => {
    const value = event.target.value
    setLomesJson(value)

    if (validateJson(value)) {
      setLomesError('')

      // Update resource metadata.lomes
      const parsed = value.trim() ? JSON.parse(value) : {}
      const updated = {
        ...resource,
        metadata: {
          ...resource.metadata,
          lomes: parsed,
        },
      }
      onChange(updated)
    } else {
      setLomesError('Invalid JSON format')
    }
  }, [resource, onChange, validateJson])

  // Format LOM JSON
  const handleFormatLom = useCallback(() => {
    const formatted = formatJson(lomJson)
    setLomJson(formatted)
    setLomError('')
  }, [lomJson, formatJson])

  // Format LOM-ES JSON
  const handleFormatLomes = useCallback(() => {
    const formatted = formatJson(lomesJson)
    setLomesJson(formatted)
    setLomesError('')
  }, [lomesJson, formatJson])

  // Check if has LOM data
  const hasLomData = resource.metadata?.lom && Object.keys(resource.metadata.lom).length > 0
  const hasLomesData = resource.metadata?.lomes && Object.keys(resource.metadata.lomes).length > 0

  return (
    <Stack spacing={3}>
      {/* Help text */}
      <Box sx={{ p: 2, bgcolor: 'info.50', borderRadius: 1, border: '1px solid', borderColor: 'info.200' }}>
        <Typography variant="caption" color="text.secondary">
          <strong>LOM Metadata:</strong> Learning Object Metadata standard for educational resources.<br />
          Edit the JSON below to modify LOM and LOM-ES data. Changes are validated automatically.
        </Typography>
      </Box>

      {/* LOM Metadata */}
      <Paper
        variant="outlined"
        sx={{
          p: 2,
          borderColor: lomError ? 'error.main' : 'divider',
        }}
      >
        <Stack spacing={2}>
          {/* Header */}
          <Stack direction="row" alignItems="center" justifyContent="space-between">
            <Stack direction="row" spacing={1} alignItems="center">
              <FormatAlignLeft color="primary" />
              <Typography variant="h6" sx={{ fontWeight: 600 }}>
                LOM Metadata
              </Typography>
              {hasLomData && !lomError && (
                <CheckCircle color="success" fontSize="small" />
              )}
              {lomError && (
                <ErrorIcon color="error" fontSize="small" />
              )}
            </Stack>
            <Button
              variant="outlined"
              size="small"
              onClick={handleFormatLom}
              disabled={!lomJson.trim() || !!lomError}
            >
              Format
            </Button>
          </Stack>

          {/* JSON Editor */}
          <TextField
            fullWidth
            multiline
            rows={10}
            value={lomJson}
            onChange={handleLomChange}
            placeholder="{}"
            error={!!lomError}
            helperText={lomError || 'Enter valid JSON for LOM metadata'}
            sx={{
              '& .MuiInputBase-root': {
                fontFamily: 'monospace',
                fontSize: '0.875rem',
                bgcolor: 'grey.50',
              },
            }}
          />

          {/* Status */}
          {lomError && (
            <Alert severity="error" sx={{ borderRadius: 2 }}>
              {lomError}
            </Alert>
          )}
          {!lomError && hasLomData && (
            <Alert severity="success" sx={{ borderRadius: 2 }}>
              LOM metadata is valid and contains {Object.keys(resource.metadata?.lom || {}).length} fields
            </Alert>
          )}
          {!lomError && !hasLomData && (
            <Typography variant="body2" color="text.secondary" sx={{ fontStyle: 'italic' }}>
              No LOM metadata defined. Add JSON above to create LOM data.
            </Typography>
          )}
        </Stack>
      </Paper>

      <Divider />

      {/* LOM-ES Metadata */}
      <Paper
        variant="outlined"
        sx={{
          p: 2,
          borderColor: lomesError ? 'error.main' : 'divider',
        }}
      >
        <Stack spacing={2}>
          {/* Header */}
          <Stack direction="row" alignItems="center" justifyContent="space-between">
            <Stack direction="row" spacing={1} alignItems="center">
              <FormatAlignLeft color="primary" />
              <Typography variant="h6" sx={{ fontWeight: 600 }}>
                LOM-ES Extended Metadata
              </Typography>
              {hasLomesData && !lomesError && (
                <CheckCircle color="success" fontSize="small" />
              )}
              {lomesError && (
                <ErrorIcon color="error" fontSize="small" />
              )}
            </Stack>
            <Button
              variant="outlined"
              size="small"
              onClick={handleFormatLomes}
              disabled={!lomesJson.trim() || !!lomesError}
            >
              Format
            </Button>
          </Stack>

          {/* JSON Editor */}
          <TextField
            fullWidth
            multiline
            rows={10}
            value={lomesJson}
            onChange={handleLomesChange}
            placeholder="{}"
            error={!!lomesError}
            helperText={lomesError || 'Enter valid JSON for LOM-ES metadata'}
            sx={{
              '& .MuiInputBase-root': {
                fontFamily: 'monospace',
                fontSize: '0.875rem',
                bgcolor: 'grey.50',
              },
            }}
          />

          {/* Status */}
          {lomesError && (
            <Alert severity="error" sx={{ borderRadius: 2 }}>
              {lomesError}
            </Alert>
          )}
          {!lomesError && hasLomesData && (
            <Alert severity="success" sx={{ borderRadius: 2 }}>
              LOM-ES metadata is valid and contains {Object.keys(resource.metadata?.lomes || {}).length} fields
            </Alert>
          )}
          {!lomesError && !hasLomesData && (
            <Typography variant="body2" color="text.secondary" sx={{ fontStyle: 'italic' }}>
              No LOM-ES metadata defined. Add JSON above to create LOM-ES data.
            </Typography>
          )}
        </Stack>
      </Paper>

      {/* Quick Reference */}
      <Accordion>
        <AccordionSummary expandIcon={<ExpandMore />}>
          <Typography variant="subtitle2" sx={{ fontWeight: 600 }}>
            LOM Quick Reference
          </Typography>
        </AccordionSummary>
        <AccordionDetails>
          <Box
            component="pre"
            sx={{
              fontSize: '0.75rem',
              fontFamily: 'monospace',
              bgcolor: 'grey.100',
              p: 2,
              borderRadius: 1,
              overflow: 'auto',
            }}
          >
{`{
  "general": {
    "title": { "string": ["Resource Title"] },
    "language": ["en"],
    "description": { "string": ["Resource description"] }
  },
  "lifeCycle": {
    "contribute": [
      {
        "role": { "source": "LOMv1.0", "value": "author" },
        "entity": ["Author Name"]
      }
    ]
  },
  "educational": {
    "learningResourceType": ["exercise", "simulation"],
    "intendedEndUserRole": ["learner", "teacher"]
  }
}`}
          </Box>
        </AccordionDetails>
      </Accordion>
    </Stack>
  )
}

export default EditableLomData
