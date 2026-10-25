import { Outlet } from 'react-router-dom'
import { useAuth } from '../context/useAuth.js'
import Forbidden from '../pages/Forbidden.jsx'

/** Used inside ProtectedRoute: only the given roles may see the page, everyone else gets a clear 403 page. */
export default function RoleRoute({ roles }) {
  const { role } = useAuth()

  return roles.includes(role) ? <Outlet /> : <Forbidden />
}
