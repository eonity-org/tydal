// Material-UI Icons
import DeleteIcon from '@mui/icons-material/Delete'
import CloseIcon from '@mui/icons-material/Close'
import VisibilityIcon from '@mui/icons-material/Visibility'
import AddIcon from '@mui/icons-material/Add'
import EditIcon from '@mui/icons-material/Edit'
import ExpandMoreIcon from '@mui/icons-material/ExpandMore'
import ChevronRightIcon from '@mui/icons-material/ChevronRight'
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft'
import SearchIcon from '@mui/icons-material/Search'
import FilterListIcon from '@mui/icons-material/FilterList'
import MenuIcon from '@mui/icons-material/Menu'
import AccountCircleIcon from '@mui/icons-material/AccountCircle'
import SettingsIcon from '@mui/icons-material/Settings'
import LogoutIcon from '@mui/icons-material/Logout'
import DownloadIcon from '@mui/icons-material/Download'
import UploadIcon from '@mui/icons-material/Upload'
import FolderIcon from '@mui/icons-material/Folder'
import DescriptionIcon from '@mui/icons-material/Description'
import ImageIcon from '@mui/icons-material/Image'
import VideoFileIcon from '@mui/icons-material/VideoFile'
import AudioFileIcon from '@mui/icons-material/AudioFile'
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf'
import LinkIcon from '@mui/icons-material/Link'

/**
 * Icon map for TYDAL icon buttons
 *
 * Maps icon name strings to Material-UI icon components.
 * Add new icons here as needed.
 */
export const icons = {
  delete: DeleteIcon,
  close: CloseIcon,
  visibility: VisibilityIcon,
  add: AddIcon,
  edit: EditIcon,
  'expand-more': ExpandMoreIcon,
  'chevron-right': ChevronRightIcon,
  'chevron-left': ChevronLeftIcon,
  search: SearchIcon,
  filter: FilterListIcon,
  menu: MenuIcon,
  account: AccountCircleIcon,
  settings: SettingsIcon,
  logout: LogoutIcon,
  download: DownloadIcon,
  upload: UploadIcon,
  folder: FolderIcon,
  description: DescriptionIcon,
  image: ImageIcon,
  video: VideoFileIcon,
  audio: AudioFileIcon,
  pdf: PictureAsPdfIcon,
  link: LinkIcon,
} as const

export type IconName = keyof typeof icons
