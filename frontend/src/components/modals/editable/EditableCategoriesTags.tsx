import {
  Box,
  Stack,
  Typography,
  Chip,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  Checkbox,
  ListItemText,
} from '@mui/material'
import { type ResourceData } from '../../../api/resourceService'

export interface EditableCategoriesTagsProps {
  resource: ResourceData
  onChange: (resource: ResourceData) => void
  availableCategories?: Array<{ id: string; name: string; type: string }>
}

function EditableCategoriesTags(props: EditableCategoriesTagsProps) {
  const { resource, onChange, availableCategories = [] } = props

  const getCurrentCategories = (): string[] => {
    if (Array.isArray(resource.categories)) {
      return resource.categories.map((c: any) => c.name || c.id)
    }
    return []
  }

  const handleCategoriesChange = (event: any) => {
    const value = event.target.value
    const categoryObjects = value.map((name: string) => ({
      id: name.toLowerCase().replace(/\s+/g, '-'),
      name,
      type: 'topic',
    }))
    onChange({ ...resource, categories: categoryObjects })
  }

  const currentCategories = getCurrentCategories()

  return (
    <Box>
      <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1 }}>
        Categories
      </Typography>

      {availableCategories.length > 0 ? (
        <FormControl fullWidth>
          <InputLabel>Select Categories</InputLabel>
          <Select
            multiple
            value={currentCategories}
            onChange={handleCategoriesChange}
            renderValue={(selected) => (
              <Stack direction="row" spacing={1} flexWrap="wrap">
                {(selected as string[]).map((value) => (
                  <Chip key={value} label={value} size="small" />
                ))}
              </Stack>
            )}
            label="Select Categories"
          >
            {availableCategories.map((category) => (
              <MenuItem key={category.id} value={category.name}>
                <Checkbox checked={currentCategories.indexOf(category.name) > -1} />
                <ListItemText primary={category.name} />
              </MenuItem>
            ))}
          </Select>
        </FormControl>
      ) : (
        <Box sx={{ p: 2, bgcolor: 'grey.100', borderRadius: 1 }}>
          <Typography variant="body2" color="text.secondary">
            No categories available for this collection.
          </Typography>
        </Box>
      )}

      {currentCategories.length > 0 && (
        <Stack direction="row" spacing={1} flexWrap="wrap" sx={{ mt: 1.5 }}>
          {currentCategories.map((category) => (
            <Chip
              key={category}
              label={category}
              size="small"
              sx={{
                bgcolor: 'primary.subtle',
                borderRadius: 3,
                border: '1px solid',
                borderColor: 'primary.light',
                fontWeight: 500,
                color: 'primary.main',
              }}
            />
          ))}
        </Stack>
      )}
    </Box>
  )
}

export default EditableCategoriesTags
