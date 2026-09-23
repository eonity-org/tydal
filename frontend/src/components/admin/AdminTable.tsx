import {
  Box, Stack, Table, TableBody, TableCell, TableContainer,
  TableHead, TableRow, Typography, IconButton, TextField,
  Button, CircularProgress, InputAdornment, Tooltip,
} from '@mui/material'
import { Add, Edit, Delete, Search, ContentCopy } from '@mui/icons-material'
import { ChevronLeft, ChevronRight } from '@mui/icons-material'

export interface AdminColumn<T> {
  id: string
  label: string
  width?: number | string
  align?: 'left' | 'center' | 'right'
  render: (row: T) => React.ReactNode
}

interface AdminTableProps<T extends { id: string | number }> {
  columns: AdminColumn<T>[]
  rows: T[]
  loading: boolean
  total: number
  page: number
  perPage: number
  onPageChange: (page: number) => void
  onEdit?: (row: T) => void
  onDelete?: (row: T) => void
  onDuplicate?: (row: T) => void
  /** Extra per-row action controls, rendered before Edit/Delete. */
  rowActions?: (row: T) => React.ReactNode
  onAdd?: () => void
  addLabel?: string
  search?: string
  onSearchChange?: (q: string) => void
  searchPlaceholder?: string
}

function AdminTable<T extends { id: string | number }>(props: AdminTableProps<T>) {
  const {
    columns, rows, loading, total, page, perPage, onPageChange,
    onEdit, onDelete, onDuplicate, rowActions, onAdd, addLabel = 'New',
    search = '', onSearchChange, searchPlaceholder = 'Search…',
  } = props

  const totalPages = Math.ceil(total / perPage)
  const hasActions = !!onEdit || !!onDelete || !!onDuplicate || !!rowActions

  return (
    <Box>
      {/* Toolbar */}
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2 }}>
        {onSearchChange ? (
          <TextField
            size="small"
            placeholder={searchPlaceholder}
            value={search}
            onChange={(e) => onSearchChange(e.target.value)}
            sx={{ width: 280, '& .MuiOutlinedInput-root': { bgcolor: 'grey.50' } }}
            slotProps={{
              input: {
                startAdornment: (
                  <InputAdornment position="start">
                    <Search sx={{ fontSize: '1.25rem', color: 'text.secondary' }} />
                  </InputAdornment>
                ),
              },
            }}
          />
        ) : <Box />}
        {onAdd && (
          <Button variant="contained" size="small" startIcon={<Add />} onClick={onAdd}>
            {addLabel}
          </Button>
        )}
      </Stack>

      {/* Table */}
      <TableContainer sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1.5, bgcolor: 'background.paper' }}>
        <Table size="small">
          <TableHead>
            <TableRow sx={{ bgcolor: 'grey.100' }}>
              {columns.map((col) => (
                <TableCell
                  key={col.id}
                  align={col.align ?? 'left'}
                  sx={{ fontWeight: 600, fontSize: '0.75rem', color: 'text.secondary',
                        textTransform: 'uppercase', letterSpacing: '0.05em',
                        borderBottom: '1px solid', borderBottomColor: 'divider', py: 1.25, width: col.width }}
                >
                  {col.label}
                </TableCell>
              ))}
              {hasActions && (
                <TableCell align="center" sx={{ fontWeight: 600, fontSize: '0.75rem', color: 'text.secondary',
                  textTransform: 'uppercase', letterSpacing: '0.05em',
                  borderBottom: '1px solid', borderBottomColor: 'divider', py: 1.25, width: 200 }}>
                  Actions
                </TableCell>
              )}
            </TableRow>
          </TableHead>
          <TableBody>
            {loading ? (
              <TableRow>
                <TableCell colSpan={columns.length + (hasActions ? 1 : 0)} align="center" sx={{ py: 6 }}>
                  <CircularProgress size={28} />
                </TableCell>
              </TableRow>
            ) : rows.length === 0 ? (
              <TableRow>
                <TableCell colSpan={columns.length + (hasActions ? 1 : 0)} align="center" sx={{ py: 6 }}>
                  <Typography variant="body2" color="text.secondary">No records found</Typography>
                </TableCell>
              </TableRow>
            ) : (
              rows.map((row) => (
                <TableRow
                  key={row.id}
                  sx={{ '&:last-child td': { border: 0 },
                        '&:hover': { bgcolor: 'grey.50' } }}
                >
                  {columns.map((col) => (
                    <TableCell key={col.id} align={col.align ?? 'left'} sx={{ py: 1, fontSize: '0.875rem', borderBottom: '1px solid', borderBottomColor: 'divider' }}>
                      {col.render(row)}
                    </TableCell>
                  ))}
                  {hasActions && (
                    <TableCell align="center" sx={{ py: 1, borderBottom: '1px solid', borderBottomColor: 'divider' }}>
                      <Stack direction="row" spacing={0.5} justifyContent="center">
                        {onDuplicate && (
                          <Tooltip title="Duplicate">
                            <IconButton size="small" onClick={() => onDuplicate(row)} sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'primary.main' } }}>
                              <ContentCopy sx={{ fontSize: '1rem' }} />
                            </IconButton>
                          </Tooltip>
                        )}
                        {onEdit && (
                          <Tooltip title="Edit">
                            <IconButton size="small" onClick={() => onEdit(row)} sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'primary.main' } }}>
                              <Edit sx={{ fontSize: '1rem' }} />
                            </IconButton>
                          </Tooltip>
                        )}
                        {rowActions?.(row)}
                        {onDelete && (
                          <Tooltip title="Delete">
                            <IconButton size="small" onClick={() => onDelete(row)} sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'error.main' } }}>
                              <Delete sx={{ fontSize: '1rem' }} />
                            </IconButton>
                          </Tooltip>
                        )}
                      </Stack>
                    </TableCell>
                  )}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </TableContainer>

      {/* Pagination + count */}
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mt: 1.5, px: 0.5 }}>
        <Typography variant="caption" color="text.secondary">
          {total} {total === 1 ? 'record' : 'records'}
        </Typography>
        {totalPages > 1 && (
          <Stack direction="row" alignItems="center" spacing={1}>
            <IconButton size="small" disabled={page === 1} onClick={() => onPageChange(page - 1)}>
              <ChevronLeft fontSize="small" />
            </IconButton>
            <Typography variant="caption" color="text.secondary">
              Page <strong>{page}</strong> of <strong>{totalPages}</strong>
            </Typography>
            <IconButton size="small" disabled={page === totalPages} onClick={() => onPageChange(page + 1)}>
              <ChevronRight fontSize="small" />
            </IconButton>
          </Stack>
        )}
      </Stack>
    </Box>
  )
}

export default AdminTable
