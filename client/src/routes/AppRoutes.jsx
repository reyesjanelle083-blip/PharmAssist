import { Route, Routes } from 'react-router-dom'
import AuthLayout from '../components/AuthLayout.jsx'
import Layout from '../components/Layout.jsx'
import ProtectedRoute from '../components/ProtectedRoute.jsx'
import RoleRoute from '../components/RoleRoute.jsx'
import Forbidden from '../pages/Forbidden.jsx'
import Home from '../pages/Home.jsx'
import Login from '../pages/Login.jsx'
import NotFound from '../pages/NotFound.jsx'
import Register from '../pages/Register.jsx'
import ReserveComingSoon from '../pages/ReserveComingSoon.jsx'
import VerifyEmail from '../pages/VerifyEmail.jsx'

export default function AppRoutes() {
  return (
    <Routes>
      {/* the old login / register look: no sidebar, form column and picture panel */}
      <Route element={<AuthLayout />}>
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/verify-email" element={<VerifyEmail />} />
      </Route>

      {/* the old shell: sidebar, top bar, page, footer */}
      <Route element={<Layout />}>
        <Route path="/" element={<Home />} />
        <Route path="/forbidden" element={<Forbidden />} />

        {/* needs a login, and then the customer role */}
        <Route element={<ProtectedRoute />}>
          <Route element={<RoleRoute roles={['customer']} />}>
            <Route path="/reserve" element={<ReserveComingSoon />} />
          </Route>
        </Route>

        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}
