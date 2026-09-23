import { createContext, useContext, useState, type ReactNode } from 'react'
import { createTheme, type Theme } from '@mui/material/styles'

declare module '@mui/material/styles' {
  interface PaletteColor {
    subtle?: string
  }
  interface SimplePaletteColorOptions {
    subtle?: string
  }
}

export type ColorThemeName = 'blue' | 'william' | 'plum' | 'graphite' | 'copper'
export type SurfaceScheme = 'grey' | 'white'
export type TransparencyBackdrop = 'checker' | 'light' | 'dark'

export interface Surfaces {
  headerBg: string
  headerBlur: boolean
  headerTabBg: string
  sidebar: string
  canvas: string
  facet: string
}

export const SURFACE_SCHEMES: Record<SurfaceScheme, Surfaces> = {
  grey: {
    headerBg: 'rgba(255, 255, 255, 0.8)',
    headerBlur: true,
    headerTabBg: 'transparent',
    sidebar: '#F3F3F3',
    // A 2% step off white read as "almost white" rather than as a ground the
    // cards sit on; deepened so the `grey` scheme actually separates figure
    // from ground. `white` stays literally white — that is what it promises.
    canvas: '#F3F4F5',
    facet: '#F9F9F9',
  },
  white: {
    headerBg: 'rgba(255, 255, 255, 0.8)',
    headerBlur: true,
    headerTabBg: '#F3F3F3',
    sidebar: '#FFFFFF',
    canvas: '#FFFFFF',
    facet: '#FFFFFF',
  },
}

export const SURFACE_LABELS: Record<SurfaceScheme, string> = {
  grey: 'Grey',
  white: 'White',
}

/**
 * The surface an asset is displayed against — card wells, the detail modal
 * viewer, the wizard preview.
 *
 * Deliberately NOT drawn from the theme's grey ramp. Every palette below tints
 * its greys toward its own hue (convention rule 3), which is right for chrome
 * and wrong behind content: a hue-tinted surround shifts the perceived colour
 * of the asset in front of it (simultaneous contrast), and in an asset system
 * people judge whether a shot has a cast against exactly this backdrop. Chrome
 * is tinted; content is neutral.
 */
export const MEDIA_SURFACE = '#F4F4F5'
export const MEDIA_SURFACE_BORDER = 'rgba(0, 0, 0, 0.06)'
/** The lightbox counterpart — same reasoning, dark end of the range. */
export const MEDIA_SURFACE_DARK = '#2A2A2E'

/**
 * The two-step depth model for a card in a grid: the card lifts off the canvas,
 * the media well sinks into the card. Both are neutral blacks at low alpha
 * rather than tinted shadows, for the same reason the well itself is neutral.
 * Named here so the pair stays in sync — they read as one system or neither.
 */
export const CARD_SHADOW = '0 1px 3px 0 rgba(0, 0, 0, 0.08)'
export const MEDIA_WELL_INSET_SHADOW = 'inset 0 1px 3px 0 rgba(0, 0, 0, 0.07)'

/**
 * Backdrop for the transparent regions of an asset. Which one reads best is a
 * property of the asset, not of the installation — cutouts and logos want the
 * checkerboard (unambiguously "this is transparent"), UI screenshots want
 * light, product renders want dark — so it is a user setting rather than a
 * constant. `checker` is the default because it is the only one of the three
 * that cannot be mistaken for the asset's own background.
 *
 * Two surfaces, deliberately separated:
 *   `well`  — the whole viewing area, including the letterbox bars that
 *             object-fit: contain leaves around a non-16:9 asset.
 *   `image` — painted behind the asset's own box only.
 *
 * The checkerboard belongs on `image` alone. Painted across the well it would
 * appear in the letterbox bars of every *opaque* photo too, which at 48 cards
 * per page is a screenful of noise announcing transparency that isn't there.
 * Confined to the image box it is invisible under an opaque asset and shows
 * through exactly where the asset is actually transparent — no per-asset alpha
 * flag needed. `dark` sets both, because choosing it means asking for a dark
 * viewing area, not merely a dark backing.
 */
export const TRANSPARENCY_BACKDROPS: Record<TransparencyBackdrop, {
  label: string
  well: object
  image: object
}> = {
  checker: {
    label: 'Checkerboard',
    well: { backgroundColor: MEDIA_SURFACE },
    image: {
      backgroundColor: '#FFFFFF',
      backgroundImage:
        'linear-gradient(45deg, #E0E0E4 25%, transparent 25%, transparent 75%, #E0E0E4 75%),' +
        'linear-gradient(45deg, #E0E0E4 25%, transparent 25%, transparent 75%, #E0E0E4 75%)',
      backgroundSize: '14px 14px',
      backgroundPosition: '0 0, 7px 7px',
    },
  },
  light: {
    label: 'Light',
    well: { backgroundColor: MEDIA_SURFACE },
    image: { backgroundColor: '#FFFFFF' },
  },
  dark: {
    label: 'Dark',
    well: { backgroundColor: MEDIA_SURFACE_DARK },
    image: { backgroundColor: MEDIA_SURFACE_DARK },
  },
}

export const TRANSPARENCY_LABELS: Record<TransparencyBackdrop, string> = {
  checker: 'Checkerboard',
  light: 'Light',
  dark: 'Dark',
}

const inputLabelOverride = {
  MuiInputLabel: {
    styleOverrides: {
      shrink: {
        backgroundColor: '#fff',
        paddingLeft: 4,
        paddingRight: 4,
        marginLeft: -4,
      },
    },
  },
}

const STATUS_COLORS = {
  success: { main: '#1a7f37', light: '#e6f4ea' },
  info:    { main: '#0969da', light: '#ddf4ff' },
  warning: { main: '#b45309', light: '#fff3e0' },
  error:   { main: '#c5221f', light: '#fce8e6' },
}

/**
 * Static chip colors that don't change between color themes.
 * Use these for semantically fixed badges (LOM standard, search query, TTL).
 */
export const CHIP_COLORS = {
  lom:    { main: '#6e40c9', light: '#f3f0ff' },  // purple — LOM standard
  search: { main: '#9a6700', light: '#fff8c5' },  // amber  — search query chip
  ttl:    { main: '#f57f17', light: '#fff8e1' },  // orange — Vault TTL chip
} as const

const SHARED_PALETTE = {
  divider: '#d0d7de',
}

/**
 * Palette convention — every theme below follows the same three rules, so a new
 * theme is a hue choice rather than a design exercise:
 *
 * 1. `secondary` is the SAME hue as `primary`, ~8 lightness points darker. It is
 *    a depth accent (active tabs, workspace toggles, vault-key icons), not a
 *    contrasting second brand color — a complementary hue there collides with
 *    the fixed STATUS_COLORS and reads as a competing signal.
 * 2. A primary hue should stay clear of the STATUS_COLORS / CHIP_COLORS hues:
 *    error 1°, warning 26°, search 45°, success 137°, blue/info 212°, lom 260°.
 *    Copper is a deliberate, documented exception — see its note below.
 * 3. Every theme carries the full grey[50–900] + background + text ramp tinted
 *    toward its own hue, plus `subtle` on both primary and secondary.
 */

// Plum theme — deep aubergine (H≈294°, S≈26%, L≈31%). Chosen over a brand-red
// primary so that `error` keeps sole ownership of the destructive register:
// the basket bar, selection borders and DELETE must never read as one field.
// Dark and low-chroma enough not to collide with the high-saturation LOM chip.
const PLUM_PALETTE = {
  primary: { main: '#5E3A62', light: '#7E5A82', dark: '#42283F', contrastText: '#FFFFFF', subtle: '#F4EEF5' },
  secondary: { main: '#462749', light: '#6D4672', dark: '#2D1830', contrastText: '#FFFFFF', subtle: '#F3ECF4' },
  grey: {
    50:  '#FAF8FA',
    100: '#F3EFF4',
    200: '#E7E0E9',
    300: '#CEC2D1',
    400: '#A395A7',
    500: '#7B6C7F',
    600: '#5C4F5F',
    700: '#423844',
    800: '#2B242C',
    900: '#171317',
  },
  background: { default: '#FAF8FA', paper: '#FFFFFF' },
  text: { primary: '#171317', secondary: '#5C4F5F' },
  ...STATUS_COLORS,
  ...SHARED_PALETTE,
}

// Graphite theme — near-neutral ink slate (H≈210°, S≈18%, L≈29%). Deliberately
// low-chroma so the status colors carry the semantic weight.
const GRAPHITE_PALETTE = {
  primary: { main: '#3D4A57', light: '#5C6B7A', dark: '#2A343E', contrastText: '#FFFFFF', subtle: '#EEF1F4' },
  secondary: { main: '#293642', light: '#475766', dark: '#18212A', contrastText: '#FFFFFF', subtle: '#ECF0F3' },
  grey: {
    50:  '#F8F9FA',
    100: '#F1F3F5',
    200: '#E3E7EB',
    300: '#CBD2D9',
    400: '#98A4B0',
    500: '#6B7885',
    600: '#4E5A66',
    700: '#39434D',
    800: '#262E36',
    900: '#141A1F',
  },
  background: { default: '#F8F9FA', paper: '#FFFFFF' },
  text: { primary: '#141A1F', secondary: '#4E5A66' },
  ...STATUS_COLORS,
  ...SHARED_PALETTE,
}

// William theme — derived from #35686D (H≈185°, S≈35%, L≈32%)
const WILLIAM_PALETTE = {
  primary: { main: '#35686D', light: '#4A909A', dark: '#224B4F', contrastText: '#FFFFFF', subtle: '#E4F5F6' },
  secondary: { main: '#224B4F', light: '#3D7276', dark: '#162E30', contrastText: '#FFFFFF', subtle: '#E4EFF0' },
  grey: {
    50:  '#F4FAFA',
    100: '#E8F3F3',
    200: '#D0E8E8',
    300: '#A8CFCF',
    400: '#70AAAC',
    500: '#4E8688',
    600: '#3A6567',
    700: '#2A4849',
    800: '#1C3031',
    900: '#0E1A1B',
  },
  background: { default: '#F4FAFA', paper: '#FFFFFF' },
  text: { primary: '#0E1A1B', secondary: '#3A6567' },
  ...STATUS_COLORS,
  ...SHARED_PALETTE,
}

const BLUE_PALETTE = {
  primary: { main: '#006E95', light: '#2A8FB0', dark: '#005373', contrastText: '#FFFFFF', subtle: '#E0F4F8' },
  secondary: { main: '#005373', light: '#336F86', dark: '#003B52', contrastText: '#FFFFFF', subtle: '#E3EFF4' },
  grey: {
    50:  '#F8FAFC',
    100: '#F1F5F9',
    200: '#E2E8F0',
    300: '#CBD5E1',
    400: '#94A3B8',
    500: '#64748B',
    600: '#475569',
    700: '#334155',
    800: '#1E293B',
    900: '#0F172A',
  },
  background: { default: '#F8FAFC', paper: '#FFFFFF' },
  text: { primary: '#0F172A', secondary: '#475569' },
  ...STATUS_COLORS,
  ...SHARED_PALETTE,
}

// Copper theme — warm burnt sienna (H≈14°, S≈34%, L≈36%). The one theme that
// deliberately bends convention rule 2: its hue sits ~12° from both `error` (1°)
// and `warning` (26°), so a contained primary button and a DELETE button are
// separated by saturation and lightness rather than hue. Legible for typical
// vision, but the pair collapses under protanopia/deuteranopia — prefer Blue or
// Graphite where destructive actions sit next to primary ones for CVD users.
const COPPER_PALETTE = {
  primary: { main: '#7A4A3C', light: '#9F6656', dark: '#5B3429', contrastText: '#FFFFFF', subtle: '#F8EFED' },
  secondary: { main: '#63382C', light: '#8D5849', dark: '#41231B', contrastText: '#FFFFFF', subtle: '#F6ECEA' },
  grey: {
    50:  '#FBF8F7',
    100: '#F6F0EF',
    200: '#EBE2E0',
    300: '#D5C7C3',
    400: '#B19C95',
    500: '#8C7169',
    600: '#69554F',
    700: '#4C3D39',
    800: '#312825',
    900: '#1A1514',
  },
  background: { default: '#FBF8F7', paper: '#FFFFFF' },
  text: { primary: '#1A1514', secondary: '#69554F' },
  ...STATUS_COLORS,
  ...SHARED_PALETTE,
}

const typography = {
  fontFamily: '"Manrope Variable", "Manrope", system-ui, -apple-system, sans-serif',
  fontSize: 16,
  htmlFontSize: 16,
}

// Key order here is the order the theme picker renders (Header iterates
// Object.keys(THEME_LABELS)); `themes` is kept in the same order to match.
export const themes: Record<ColorThemeName, Theme> = {
  blue:     createTheme({ palette: BLUE_PALETTE,     typography, components: inputLabelOverride }),
  william:  createTheme({ palette: WILLIAM_PALETTE,  typography, components: inputLabelOverride }),
  plum:     createTheme({ palette: PLUM_PALETTE,     typography, components: inputLabelOverride }),
  graphite: createTheme({ palette: GRAPHITE_PALETTE, typography, components: inputLabelOverride }),
  copper:   createTheme({ palette: COPPER_PALETTE,   typography, components: inputLabelOverride }),
}

export const THEME_LABELS: Record<ColorThemeName, string> = {
  blue:     'Blue',
  william:  'William',
  plum:     'Plum',
  graphite: 'Graphite',
  copper:   'Copper',
}

interface ThemeContextValue {
  colorTheme: ColorThemeName
  setColorTheme: (name: ColorThemeName) => void
  theme: Theme
  surfaceScheme: SurfaceScheme
  setSurfaceScheme: (scheme: SurfaceScheme) => void
  surfaces: Surfaces
  transparencyBackdrop: TransparencyBackdrop
  setTransparencyBackdrop: (backdrop: TransparencyBackdrop) => void
  /** Ready-to-spread sx pair: `well` for the viewing area, `image` for behind the asset. */
  mediaSurfaceSx: { well: object; image: object }
}

const ThemeContext = createContext<ThemeContextValue>({
  colorTheme: 'blue',
  setColorTheme: () => {},
  theme: themes.blue,
  surfaceScheme: 'grey',
  setSurfaceScheme: () => {},
  surfaces: SURFACE_SCHEMES.grey,
  transparencyBackdrop: 'checker',
  setTransparencyBackdrop: () => {},
  mediaSurfaceSx: TRANSPARENCY_BACKDROPS.checker,
})

export function ThemeContextProvider({ children }: { children: ReactNode }) {
  const [colorTheme, setColorThemeState] = useState<ColorThemeName>(() => {
    const saved = localStorage.getItem('tydal-color-theme') as ColorThemeName
    return saved && saved in themes ? saved : 'blue'
  })

  const [surfaceScheme, setSurfaceSchemeState] = useState<SurfaceScheme>(() => {
    const saved = localStorage.getItem('tydal-surface-scheme') as SurfaceScheme
    return saved && saved in SURFACE_SCHEMES ? saved : 'grey'
  })

  const setColorTheme = (name: ColorThemeName) => {
    localStorage.setItem('tydal-color-theme', name)
    setColorThemeState(name)
  }

  const [transparencyBackdrop, setTransparencyBackdropState] = useState<TransparencyBackdrop>(() => {
    const saved = localStorage.getItem('tydal-transparency-backdrop') as TransparencyBackdrop
    return saved && saved in TRANSPARENCY_BACKDROPS ? saved : 'checker'
  })

  const setSurfaceScheme = (scheme: SurfaceScheme) => {
    localStorage.setItem('tydal-surface-scheme', scheme)
    setSurfaceSchemeState(scheme)
  }

  const setTransparencyBackdrop = (backdrop: TransparencyBackdrop) => {
    localStorage.setItem('tydal-transparency-backdrop', backdrop)
    setTransparencyBackdropState(backdrop)
  }

  return (
    <ThemeContext.Provider value={{
      colorTheme, setColorTheme, theme: themes[colorTheme],
      surfaceScheme, setSurfaceScheme, surfaces: SURFACE_SCHEMES[surfaceScheme],
      transparencyBackdrop, setTransparencyBackdrop,
      mediaSurfaceSx: TRANSPARENCY_BACKDROPS[transparencyBackdrop],
    }}>
      {children}
    </ThemeContext.Provider>
  )
}

export const useColorTheme = () => useContext(ThemeContext)
export const useSurfaces = () => useContext(ThemeContext).surfaces
/** sx for any surface an asset is displayed against. */
export const useMediaSurface = () => useContext(ThemeContext).mediaSurfaceSx
