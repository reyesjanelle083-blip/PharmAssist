import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../context/useAuth.js'
import LoadingState from './ui/LoadingState.jsx'

/** Needs a logged-in user; otherwise goes to log in and comes back afterwards. */
export default function ProtectedRoute() {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) return <LoadingState>Checking your session...</LoadingState>

  if (!user) {
    return <Navigate to="/login" replace state={{ notice: 'login_required', from: `${location.pathname}${location.search}` }} />
  }

  return <Outlet />
}
