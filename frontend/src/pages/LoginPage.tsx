import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Box,
  Stack,
  Typography,
  Paper,
  Alert,
  CircularProgress,
} from '@mui/material'
import Button from '../components/ui/Button'
import TextField from '../components/ui/TextField'
import authService from '../api/authService'

/**
 * TYDAL Login Page Component
 *
 * Provides authentication form for users to login to TYDAL.
 *
 * @example
 * ```tsx
 * <LoginPage />
 * ```
 */
function LoginPage() {
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [info, setInfo] = useState<string | null>(null)

  // Surface a friendly notice when the user landed here from an expired session.
  useEffect(() => {
    try {
      if (sessionStorage.getItem('tydal:auth_reason') === 'expired') {
        setInfo('Your session expired. Please sign in again.')
        sessionStorage.removeItem('tydal:auth_reason')
      }
    } catch { /* sessionStorage unavailable */ }
  }, [])

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()

    if (!email || !password) {
      setError('Please enter both email and password')
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await authService.login(email, password)

      // Debug logging
      console.log('Login response:', response)

      // Check if login failed - token is nested in data object
      // Tydal backend uses 'token' field instead of 'access_token'
      if (!response.data?.token) {
        // Extract error message from response
        let errorMessage = 'Login failed. Please try again.'

        if (response.error) {
          if (typeof response.error === 'string') {
            errorMessage = response.error
          } else if (typeof response.error === 'object') {
            // Error is an object with field-specific errors
            const errorMessages = Object.values(response.error).flat()
            errorMessage = errorMessages.join(', ')
          }
        } else if (response.message) {
          // TYDAL's own reason — "Invalid credentials", or how long to wait
          // after too many attempts.
          errorMessage = response.message
        }

        setError(errorMessage)
        setLoading(false)
        return
      }

      // Login successful — return to where the user was, if we stashed it.
      let returnTo = '/'
      try {
        const stashed = sessionStorage.getItem('tydal:return_to')
        sessionStorage.removeItem('tydal:return_to')
        if (stashed && stashed !== '/login') returnTo = stashed
      } catch { /* sessionStorage unavailable */ }
      navigate(returnTo)
    } catch (err) {
      setError('An unexpected error occurred. Please try again.')
      setLoading(false)
    }
  }

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        bgcolor: 'grey.50',
      }}
    >
      <Paper
        elevation={0}
        sx={{
          width: '100%',
          maxWidth: 400,
          p: 4,
          mx: 2,
          border: '1px solid',
          borderColor: 'divider',
        }}
      >
        <Stack spacing={3}>
          {/* Logo/Title */}
          <Box sx={{ textAlign: 'center' }}>
            <Typography
              sx={{
                fontSize: '1.5rem',
                color: 'primary.main',
                fontWeight: 700,
                mb: 1,
              }}
            >
              TYDAL
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Schema-Driven Semantic Vaults for AI Agents
            </Typography>
          </Box>

          {/* Session-expiry / informational notice */}
          {info && (
            <Alert severity="info" onClose={() => setInfo(null)}>
              {info}
            </Alert>
          )}

          {/* Error message */}
          {error && (
            <Alert severity="error" onClose={() => setError(null)}>
              {error}
            </Alert>
          )}

          {/* Login form */}
          <form onSubmit={handleSubmit}>
            <Stack spacing={2}>
              <TextField
                label="Email"
                type="email"
                fullWidth
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                disabled={loading}
                autoFocus
                required
              />

              <TextField
                label="Password"
                type="password"
                fullWidth
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                disabled={loading}
                required
              />

              <Button
                type="submit"
                variant="contained"
                fullWidth
                disabled={loading}
                sx={{ py: 1.5 }}
              >
                {loading ? <CircularProgress size={24} color="inherit" /> : 'Login'}
              </Button>
            </Stack>
          </form>

          {/* Footer info */}
          <Typography variant="caption" color="text.secondary" sx={{ textAlign: 'center' }}>
            Enter your credentials to access the system
          </Typography>
        </Stack>
      </Paper>
    </Box>
  )
}

export default LoginPage
