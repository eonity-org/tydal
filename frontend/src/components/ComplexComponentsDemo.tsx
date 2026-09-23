import { useState } from 'react'
import {
  Container,
  Typography,
  Stack,
  Box,
  Grid,
  Paper,
  Divider,
} from '@mui/material'
import Checkbox from './ui/Checkbox'
import Radio from './ui/Radio'
import Badge from './ui/Badge'
import Card from './ui/Card'
import FacetCard from './FacetCard'
import Button from './ui/Button'
import IconButton from './ui/IconButton'
import FilterTag from './ui/FilterTag'
import MediaViewer from './ui/MediaViewer'

function ComplexComponentsDemo() {
  // Facet state
  const [resourceTypes, setResourceTypes] = useState([
    { label: 'Images', count: 150, selected: true },
    { label: 'Videos', count: 50, selected: false },
    { label: 'Audio', count: 25, selected: false },
    { label: 'Documents', count: 75, selected: true },
    { label: 'Ebooks', count: 30, selected: false },
    { label: 'Fonts', count: 12, selected: false },
    { label: 'Websites', count: 8, selected: false },
  ])

  const [categories, setCategories] = useState([
    { label: 'Education', count: 85, selected: false },
    { label: 'Science', count: 62, selected: false },
    { label: 'Technology', count: 95, selected: true },
    { label: 'Arts', count: 43, selected: false },
    { label: 'Business', count: 38, selected: false },
    { label: 'Health', count: 27, selected: false },
    { label: 'Sports', count: 15, selected: false },
  ])

  const [tags, setTags] = useState([
    { label: 'mathematics', count: 45, selected: false },
    { label: 'physics', count: 32, selected: false },
    { label: 'chemistry', count: 28, selected: false },
    { label: 'biology', count: 25, selected: false },
    { label: 'history', count: 38, selected: false },
    { label: 'geography', count: 22, selected: false },
    { label: 'literature', count: 35, selected: false },
    { label: 'programming', count: 52, selected: false },
    { label: 'design', count: 41, selected: false },
    { label: 'music', count: 18, selected: false },
    { label: 'art', count: 29, selected: false },
    { label: 'philosophy', count: 15, selected: false },
  ])

  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid')

  // Toggle facet value
  const toggleValue = (
    values: typeof resourceTypes,
    setValues: (values: typeof resourceTypes) => void,
    label: string
  ) => {
    setValues(
      values.map((v) =>
        v.label === label ? { ...v, selected: !v.selected } : v
      )
    )
  }

  // Delete facet value
  const deleteValue = (
    values: typeof resourceTypes,
    setValues: (values: typeof resourceTypes) => void,
    label: string
  ) => {
    setValues(
      values.map((v) =>
        v.label === label ? { ...v, selected: false } : v
      )
    )
  }

  // Clear all selections
  const clearAllSelections = () => {
    setResourceTypes(resourceTypes.map((v) => ({ ...v, selected: false })))
    setCategories(categories.map((v) => ({ ...v, selected: false })))
    setTags(tags.map((v) => ({ ...v, selected: false })))
  }

  // Count total selections
  const totalSelections =
    resourceTypes.filter((v) => v.selected).length +
    categories.filter((v) => v.selected).length +
    tags.filter((v) => v.selected).length

  return (
    <Container maxWidth="xl" sx={{ py: 4 }}>
      <Typography variant="h4" component="h1" gutterBottom>
        TYDAL Frontend 2 - Complex Components
      </Typography>

      <Typography variant="body1" sx={{ mb: 4, color: 'text.secondary' }}>
        Interactive components for sidebar facets, filters, and resource management
      </Typography>

      {/* Basic Components Section */}
      <Box sx={{ mb: 6 }}>
        <Typography variant="h5" gutterBottom>
          1. Basic Form Components
        </Typography>
        <Paper sx={{ p: 3 }}>
          <Grid container spacing={3}>
            {/* Checkboxes */}
            <Grid item xs={12} md={4}>
              <Typography variant="subtitle2" gutterBottom>
                Checkboxes
              </Typography>
              <Stack spacing={1}>
                <Checkbox label="Images" checked />
                <Checkbox label="Videos" />
                <Checkbox label="Audio" />
              </Stack>
            </Grid>

            {/* Radio Buttons */}
            <Grid item xs={12} md={4}>
              <Typography variant="subtitle2" gutterBottom>
                Radio Buttons
              </Typography>
              <Stack spacing={1}>
                <Radio label="Grid View" checked value="grid" />
                <Radio label="List View" checked={false} value="list" />
              </Stack>
            </Grid>

            {/* Badges */}
            <Grid item xs={12} md={4}>
              <Typography variant="subtitle2" gutterBottom>
                Badges (Counters)
              </Typography>
              <Stack spacing={2} direction="row" alignItems="center">
                <Badge badgeContent={150} color="primary">
                  <Box sx={{ width: 40, height: 40, bgcolor: 'grey.200', borderRadius: 1 }} />
                </Badge>
                <Badge badgeContent={5} color="error">
                  <Box sx={{ width: 40, height: 40, bgcolor: 'grey.200', borderRadius: 1 }} />
                </Badge>
                <Badge badgeContent={99} color="success" max={99}>
                  <Box sx={{ width: 40, height: 40, bgcolor: 'grey.200', borderRadius: 1 }} />
                </Badge>
              </Stack>
            </Grid>
          </Grid>
        </Paper>
      </Box>

      {/* Sidebar Simulation Section */}
      <Box sx={{ mb: 6 }}>
        <Typography variant="h5" gutterBottom>
          2. Sidebar Facets Simulation
        </Typography>
        <Typography variant="body2" sx={{ mb: 2, color: 'text.secondary' }}>
          Interactive facet cards with search, expand/collapse, and show more functionality
        </Typography>

        <Grid container spacing={3}>
          {/* Sidebar */}
          <Grid item xs={12} md={3}>
            <Paper sx={{ p: 2, height: 'fit-content', maxWidth: 280 }}>
              {/* Clear All Filters Button */}
              {totalSelections > 0 && (
                <Box sx={{ mb: 2 }}>
                  <Button
                    variant="text"
                    size="small"
                    onClick={clearAllSelections}
                    sx={{ color: 'primary.main' }}
                  >
                    Clear all filters ({totalSelections})
                  </Button>
                </Box>
              )}

              <Stack spacing={1}>
                {/* Resource Type Facet */}
                <FacetCard
                  title="Resource Type"
                  values={resourceTypes}
                  showSearch
                  searchPlaceholder="Search types..."
                  showMoreLimit={5}
                  onToggleValue={(label) => toggleValue(resourceTypes, setResourceTypes, label)}
                  defaultExpanded
                />

                {/* Category Facet */}
                <FacetCard
                  title="Category"
                  values={categories}
                  showSearch
                  searchPlaceholder="Search categories..."
                  showMoreLimit={5}
                  onToggleValue={(label) => toggleValue(categories, setCategories, label)}
                  defaultExpanded
                />

                {/* Tags Facet */}
                <FacetCard
                  title="Tags"
                  values={tags}
                  showSearch
                  searchPlaceholder="Search tags..."
                  showMoreLimit={8}
                  onToggleValue={(label) => toggleValue(tags, setTags, label)}
                  defaultExpanded={false}
                />
              </Stack>
            </Paper>
          </Grid>

          {/* Main Content Area */}
          <Grid item xs={12} md={9}>
            <Paper sx={{ p: 3 }}>
              <Typography variant="h6" gutterBottom>
                Filtered Resources
              </Typography>

              <Box sx={{ mb: 3 }}>
                <Typography variant="body2" color="text.secondary">
                  {totalSelections === 0
                    ? 'Showing all resources (no filters applied)'
                    : `Filtered by ${totalSelections} criteria`}
                </Typography>
              </Box>

              {/* Active Filters Display */}
              {totalSelections > 0 && (
                <Box sx={{ mb: 3 }}>
                  <Typography variant="subtitle2" gutterBottom>
                    Active Filters:
                  </Typography>
                  <Stack direction="row" spacing={1} flexWrap="wrap" alignItems="center">
                    {resourceTypes
                      .filter((v) => v.selected)
                      .map((v) => (
                        <FilterTag
                          key={v.label}
                          label={v.label}
                          onRemove={() => deleteValue(resourceTypes, setResourceTypes, v.label)}
                        />
                      ))}
                    {categories
                      .filter((v) => v.selected)
                      .map((v) => (
                        <FilterTag
                          key={v.label}
                          label={v.label}
                          onRemove={() => deleteValue(categories, setCategories, v.label)}
                        />
                      ))}
                    {tags
                      .filter((v) => v.selected)
                      .map((v) => (
                        <FilterTag
                          key={v.label}
                          label={v.label}
                          onRemove={() => deleteValue(tags, setTags, v.label)}
                        />
                      ))}
                  </Stack>
                </Box>
              )}

              {/* View Mode Toggle */}
              <Box sx={{ mb: 3 }}>
                <Typography variant="subtitle2" gutterBottom>
                  View Mode:
                </Typography>
                <Stack direction="row" spacing={1}>
                  <Button
                    variant={viewMode === 'grid' ? 'contained' : 'outlined'}
                    size="small"
                    onClick={() => setViewMode('grid')}
                  >
                    Grid
                  </Button>
                  <Button
                    variant={viewMode === 'list' ? 'contained' : 'outlined'}
                    size="small"
                    onClick={() => setViewMode('list')}
                  >
                    List
                  </Button>
                </Stack>
              </Box>

              {/* Resource Grid/List Placeholder */}
              <Box sx={{ p: 4, textAlign: 'center', bgcolor: 'grey.50', borderRadius: 2 }}>
                <Typography variant="body1" color="text.secondary">
                  {viewMode === 'grid' ? 'Grid view' : 'List view'} - Resources will be displayed here
                </Typography>
                <Typography variant="body2" sx={{ mt: 1 }} color="text.disabled">
                  Resource cards component coming next...
                </Typography>
              </Box>
            </Paper>
          </Grid>
        </Grid>
      </Box>

      {/* Card Component Examples */}
      <Box sx={{ mb: 6 }}>
        <Typography variant="h5" gutterBottom>
          3. Card Component Variants
        </Typography>
        <Grid container spacing={2}>
          {/* Resource Card */}
          <Grid item xs={12} md={4}>
            <Card sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
              <Card.Header
                title="Resource Card"
                subheader="Digital asset preview"
                action={
                  <Badge badgeContent={3} color="error">
                    <Box sx={{ width: 20 }} />
                  </Badge>
                }
              />
              <Card.Content sx={{ flex: 1 }}>
                <Box
                  sx={{
                    width: '100%',
                    height: 120,
                    bgcolor: 'grey.100',
                    borderRadius: 1,
                    mb: 2,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: 'text.secondary',
                  }}
                >
                  Image Preview
                </Box>
                <Typography variant="body2" gutterBottom>
                  <strong>resource_image.jpg</strong>
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  Modified 2 hours ago • 2.4 MB
                </Typography>
              </Card.Content>
              <Card.Actions>
                <IconButton icon="visibility" size="small" aria-label="View" tooltip="View" />
                <IconButton icon="edit" size="small" aria-label="Edit" tooltip="Edit" />
                <IconButton icon="download" size="small" aria-label="Download" tooltip="Download" />
              </Card.Actions>
            </Card>
          </Grid>

          {/* Settings Card */}
          <Grid item xs={12} md={4}>
            <Card>
              <Card.Header
                title="Settings Card"
                action={
                  <IconButton icon="settings" size="small" aria-label="Settings" />
                }
              />
              <Card.Content>
                <Stack spacing={2}>
                  <Box>
                    <Typography variant="caption" color="text.secondary">
                      Display Options
                    </Typography>
                    <Stack direction="row" spacing={2} sx={{ mt: 1 }}>
                      <Radio label="Grid" checked size="small" />
                      <Radio label="List" size="small" />
                    </Stack>
                  </Box>
                  <Box>
                    <Typography variant="caption" color="text.secondary">
                      Items per page
                    </Typography>
                    <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
                      <Button size="small" variant="contained">12</Button>
                      <Button size="small" variant="outlined">24</Button>
                      <Button size="small" variant="outlined">48</Button>
                    </Stack>
                  </Box>
                </Stack>
              </Card.Content>
              <Card.Actions>
                <Button size="small">Reset</Button>
                <Button size="small" variant="contained">Apply</Button>
              </Card.Actions>
            </Card>
          </Grid>

          {/* Stats Card */}
          <Grid item xs={12} md={4}>
            <Card sx={{ bgcolor: 'primary.50', border: '1px solid', borderColor: 'primary.200' }}>
              <Card.Content>
                <Stack spacing={1}>
                  <Typography variant="overline" color="primary.dark">
                    Total Resources
                  </Typography>
                  <Typography variant="h3" color="primary.dark">
                    2,847
                  </Typography>
                  <Typography variant="body2" color="text.secondary">
                    +12% from last month
                  </Typography>
                  <Box sx={{ mt: 2 }}>
                    <Stack direction="row" spacing={1} flexWrap="wrap">
                      <FilterTag label="Images" onRemove={() => {}} />
                      <FilterTag label="Videos" onRemove={() => {}} />
                    </Stack>
                  </Box>
                </Stack>
              </Card.Content>
            </Card>
          </Grid>

          {/* Collection Card */}
          <Grid item xs={12} md={6}>
            <Card>
              <Card.Header
                title="Collection Card"
                subheader="Organize resources into collections"
                action={
                  <IconButton icon="add" size="small" aria-label="Add collection" tooltip="New collection" />
                }
              />
              <Card.Content>
                <Stack spacing={1.5}>
                  {[
                    { name: 'Marketing Assets', count: 234, selected: true },
                    { name: 'Product Images', count: 156, selected: false },
                    { name: 'Team Photos', count: 89, selected: false },
                  ].map((collection, index) => (
                    <Stack
                      key={index}
                      direction="row"
                      alignItems="center"
                      justifyContent="space-between"
                      sx={{
                        px: 1,
                        py: 0.5,
                        borderRadius: 1,
                        bgcolor: collection.selected ? 'primary.50' : 'transparent',
                        cursor: 'pointer',
                      }}
                    >
                      <Stack direction="row" alignItems="center" spacing={1}>
                        <IconButton
                          icon="folder"
                          size="small"
                          sx={{ color: collection.selected ? 'primary.main' : 'text.secondary' }}
                        />
                        <Typography variant="body2">{collection.name}</Typography>
                      </Stack>
                      <Badge badgeContent={collection.count} color="default">
                        <Box sx={{ width: 16 }} />
                      </Badge>
                    </Stack>
                  ))}
                </Stack>
              </Card.Content>
            </Card>
          </Grid>

          {/* User Card */}
          <Grid item xs={12} md={6}>
            <Card>
              <Card.Content>
                <Stack direction="row" spacing={2} alignItems="center">
                  <Box
                    sx={{
                      width: 60,
                      height: 60,
                      borderRadius: '50%',
                      bgcolor: 'primary.main',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      color: 'white',
                      fontSize: '1.5rem',
                      fontWeight: 600,
                    }}
                  >
                    JD
                  </Box>
                  <Stack spacing={0.5} sx={{ flex: 1 }}>
                    <Typography variant="h6">John Doe</Typography>
                    <Typography variant="body2" color="text.secondary">
                      john.doe@example.com
                    </Typography>
                    <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
                      <FilterTag label="Admin" onRemove={() => {}} />
                      <FilterTag label="Editor" onRemove={() => {}} />
                    </Stack>
                  </Stack>
                </Stack>
              </Card.Content>
              <Card.Actions>
                <Button size="small">Profile</Button>
                <Button size="small" variant="outlined">Logout</Button>
              </Card.Actions>
            </Card>
          </Grid>
        </Grid>
      </Box>

      {/* Usage Examples Section */}
      <Box>
        <Typography variant="h5" gutterBottom>
          4. Usage Examples
        </Typography>
        <Paper sx={{ p: 3 }}>
          <Stack spacing={3}>
            {/* Checkbox with Label */}
            <Box>
              <Typography variant="subtitle2" gutterBottom>
                Checkbox with Label:
              </Typography>
              <Stack direction="row" spacing={2}>
                <Checkbox label="Accept terms and conditions" />
                <Checkbox label="Subscribe to newsletter" checked />
              </Stack>
            </Box>

            <Divider />

            {/* Badge with Icon */}
            <Box>
              <Typography variant="subtitle2" gutterBottom>
                Badge with Icon Button:
              </Typography>
              <Stack direction="row" spacing={2}>
                <Badge badgeContent={5} color="error">
                  <IconButton icon="add" aria-label="Notifications" />
                </Badge>
                <Badge badgeContent={12} color="primary">
                  <IconButton icon="description" aria-label="Messages" />
                </Badge>
                <Badge badgeContent={99} color="success" max={99}>
                  <IconButton icon="folder" aria-label="Files" />
                </Badge>
              </Stack>
            </Box>

            <Divider />

            {/* Radio Group */}
            <Box>
              <Typography variant="subtitle2" gutterBottom>
                Radio Group (View Mode):
              </Typography>
              <Stack direction="row" spacing={2}>
                <Radio
                  label="Grid View"
                  checked={viewMode === 'grid'}
                  value="grid"
                  onChange={() => setViewMode('grid')}
                />
                <Radio
                  label="List View"
                  checked={viewMode === 'list'}
                  value="list"
                  onChange={() => setViewMode('list')}
                />
              </Stack>
            </Box>
          </Stack>
        </Paper>
      </Box>

      {/* Media Viewer Section */}
      <Box sx={{ mb: 6 }}>
        <Typography variant="h5" gutterBottom>
          5. Media Viewer Component
        </Typography>
        <Typography variant="body2" sx={{ mb: 2, color: 'text.secondary' }}>
          Interactive media viewer with navigation controls for multiple files
        </Typography>

        <Paper sx={{ p: 3 }}>
          <Grid container spacing={3}>
            {/* Media Viewer with Multiple Files */}
            <Grid item xs={12}>
              <Box>
                <Typography variant="subtitle2" gutterBottom sx={{ mb: 2 }}>
                  Media Viewer with Multiple Files
                </Typography>
                <MediaViewer
                  files={[
                    {
                      id: 'file1',
                      original_name: 'MAZE_dash2.png',
                      mime_type: 'image/png',
                      dam_url: '@@@dam:@image@a11e9aba-39c6-4329-920a-4f95b38c3fc1@d95d4043-f82b-44c1-9db2-9376bf577711@@@',
                    },
                    {
                      id: 'file2',
                      original_name: 'MAZE_dash2_alternative.png',
                      mime_type: 'image/png',
                      dam_url: '@@@dam:@image@a11e9aba-39c6-4329-920a-4f95b38c3fc1@alternative-file@@@',
                    },
                  ]}
                  height={400}
                  defaultIndex={0}
                  showControls
                />
              </Box>
            </Grid>

            {/* Single File Viewer */}
            <Grid item xs={12} md={6}>
              <Box>
                <Typography variant="subtitle2" gutterBottom sx={{ mb: 2 }}>
                  Single File Viewer
                </Typography>
                <MediaViewer
                  files={[
                    {
                      id: 'single',
                      original_name: 'sample_image.jpg',
                      mime_type: 'image/jpeg',
                      dam_url: '@@@dam:@image@uuid@@@',
                    },
                  ]}
                  height={300}
                  showControls={false}
                />
              </Box>
            </Grid>

            {/* Compact Viewer */}
            <Grid item xs={12} md={6}>
              <Box>
                <Typography variant="subtitle2" gutterBottom sx={{ mb:2 }}>
                  Compact Viewer (200px height)
                </Typography>
                <MediaViewer
                  files={[
                    {
                      id: 'compact',
                      original_name: 'document_preview.png',
                      mime_type: 'image/png',
                      dam_url: '@@@dam:@image@uuid@@@',
                    },
                  ]}
                  height={200}
                  showControls
                />
              </Box>
            </Grid>
          </Grid>
        </Paper>
      </Box>
    </Container>
  )
}

export default ComplexComponentsDemo
