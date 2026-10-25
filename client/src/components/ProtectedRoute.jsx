import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../context/useAuth.js'

/** Needs a logged-in user; otherwise goes to log in and comes back afterwards. */
export default function ProtectedRoute() {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) return <p role="status">Checking your session...</p>

  if (!user) {
    return <Navigate to="/login" replace state={{ notice: 'login_required', from: `${location.pathname}${location.search}` }} />
  }

  return <Outlet />
}
