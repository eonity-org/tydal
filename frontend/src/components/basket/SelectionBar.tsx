import { Box, Button, Chip, Stack, Tooltip, Typography } from '@mui/material'
import { useNavigate } from 'react-router-dom'
import { ShoppingBasket } from '@mui/icons-material'
import { useBasket } from '../../contexts/BasketContext'
import BulkActions from './BulkActions'

/**
 * The bar above the results, shown whenever the basket has anything in it.
 *
 * It exists to replace an arrangement where the selected count hid inside the
 * results-count label and the Delete button sat in the same row as "New
 * Resource" and "New Resources Wizard" — a destructive control parked between
 * two create controls.
 *
 * The "N on this page" figure is the honesty requirement of a basket that
 * survives paging and scope changes: nobody should archive twenty-three things
 * believing they picked the twelve they can see.
 */
export function SelectionBar({
  visibleIds,
  onChanged,
}: {
  /** Ids currently rendered, so the bar can offer "select this page". */
  visibleIds: string[]
  onChanged: () => void
}) {
  const navigate = useNavigate()
  const { ids, count, has, add, clear, remove, isFull, capacity } = useBasket()

  if (count === 0) return null

  const onThisPage = visibleIds.filter((id) => has(id)).length
  const allOfPageIn = visibleIds.length > 0 && onThisPage === visibleIds.length

  return (
    <Box
      sx={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        flexWrap: 'wrap',
        gap: 1,
        px: 2,
        py: 1,
        mb: 1.5,
        borderRadius: 1.5,
        border: '1px solid',
        borderColor: 'primary.light',
        bgcolor: 'primary.subtle',
      }}
    >
      <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap">
        <Chip
          icon={<ShoppingBasket sx={{ fontSize: '1rem' }} />}
          label={`${count} in basket`}
          size="small"
          color="primary"
          onClick={() => navigate('/basket')}
        />
        <Typography variant="body2" color="text.secondary">
          {onThisPage} on this page
          {isFull && ` · full at ${capacity}`}
        </Typography>

        <Tooltip title={allOfPageIn ? 'Take this page back out of the basket' : 'Put every resource on this page in the basket'}>
          <span>
            <Button
              size="small"
              onClick={() => (allOfPageIn ? visibleIds.forEach(remove) : add(visibleIds))}
              disabled={visibleIds.length === 0 || (!allOfPageIn && isFull)}
            >
              {allOfPageIn ? 'Deselect page' : 'Select page'}
            </Button>
          </span>
        </Tooltip>
        <Button size="small" onClick={clear}>Clear</Button>
        <Button size="small" onClick={() => navigate('/basket')}>Review →</Button>
      </Stack>

      <BulkActions ids={ids} onChanged={onChanged} onRemoved={(gone) => gone.forEach(remove)} />
    </Box>
  )
}

export default SelectionBar
