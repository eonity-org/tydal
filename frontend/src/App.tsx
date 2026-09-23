import { ThemeProvider } from '@mui/material/styles'
import CssBaseline from '@mui/material/CssBaseline'
import { GlobalStyles } from '@mui/material'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import DesigningPage from './pages/DesigningPage'
import AdminPage from './pages/AdminPage'
import TrashPage from './pages/TrashPage'
import AityReviewPage from './pages/AityReviewPage'
import BasketPage from './pages/BasketPage'
import OrganizationPage from './pages/OrganizationPage'
import authService from './api/authService'
import { ThemeContextProvider, useColorTheme } from './contexts/ThemeContext'
import { BasketProvider } from './contexts/BasketContext'
import { isPlatformAdmin } from './constants/roles'
import { useEffect, useState } from 'react'
import { Box, CircularProgress } from '@mui/material'

/**
 * Protected Route Component
 *
 * Redirects to login if user is not authenticated
 */
function ProtectedRoute({ children }: { children: React.ReactNode }) {
  return authService.isAuthenticated() ? <>{children}</> : <Navigate to="/login" replace />
}

/**
 * Platform administration route.
 *
 * /admin was previously auth-only: any signed-in user who typed the URL got
 * the full six-tab panel, which then merely failed on 403s from every call.
 * The button was hidden from them, which is discoverability, not a guard.
 */
function PlatformRoute({ children }: { children: React.ReactNode }) {
  const [allowed, setAllowed] = useState<boolean | null>(null)

  useEffect(() => {
    let cancelled = false
    authService.getUser()
      .then((res) => { if (!cancelled) setAllowed(isPlatformAdmin(res?.data)) })
      .catch(() => { if (!cancelled) setAllowed(false) })
    return () => { cancelled = true }
  }, [])

  if (allowed === null) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100vh' }}>
        <CircularProgress size={32} />
      </Box>
    )
  }

  return allowed ? <>{children}</> : <Navigate to="/" replace />
}

function AppWithTheme() {
  const { theme } = useColorTheme()
  const htmlFontSize = theme.typography.htmlFontSize ?? 16
  return (
    <ThemeProvider theme={theme}>
      <GlobalStyles styles={{ html: { fontSize: htmlFontSize } }} />
      <CssBaseline />
      <BrowserRouter>
        <Routes>
          {/* Public routes */}
          <Route path="/login" element={<LoginPage />} />

          {/* Protected routes */}
          <Route
            path="/"
            element={
              <ProtectedRoute>
                <HomePage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/designing"
            element={
              <ProtectedRoute>
                <DesigningPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin"
            element={
              <ProtectedRoute>
                <PlatformRoute>
                  <AdminPage />
                </PlatformRoute>
              </ProtectedRoute>
            }
          />
          <Route
            path="/organization"
            element={
              <ProtectedRoute>
                <OrganizationPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/trash"
            element={
              <ProtectedRoute>
                <TrashPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/basket"
            element={
              <ProtectedRoute>
                <BasketPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="/aity-review"
            element={
              <ProtectedRoute>
                <AityReviewPage />
              </ProtectedRoute>
            }
          />

          {/* Redirect unknown routes */}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </ThemeProvider>
  )
}

function App() {
  return (
    <ThemeContextProvider>
      <BasketProvider>
        <AppWithTheme />
      </BasketProvider>
    </ThemeContextProvider>
  )
}

export default App
