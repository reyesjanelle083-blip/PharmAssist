import { Navigate, Route, Routes } from 'react-router-dom'
import AuthLayout from '../components/AuthLayout.jsx'
import CustomerLayout from '../layouts/CustomerLayout.jsx'
import StaffGate from '../layouts/StaffGate.jsx'
import { STAFF_ROLES } from '../utils/staff.js'
import StaffInventory from '../pages/staff/StaffInventory.jsx'
import StaffPrescriptions from '../pages/staff/StaffPrescriptions.jsx'
import StaffReservationDetail from '../pages/staff/StaffReservationDetail.jsx'
import StaffReservations from '../pages/staff/StaffReservations.jsx'
import StaffStockAlerts from '../pages/staff/StaffStockAlerts.jsx'
import ProtectedRoute from '../components/ProtectedRoute.jsx'
import RoleRoute from '../components/RoleRoute.jsx'
import Forbidden from '../pages/Forbidden.jsx'
import Guide from '../pages/Guide.jsx'
import Home from '../pages/Home.jsx'
import Login from '../pages/Login.jsx'
import MedicineDetails from '../pages/MedicineDetails.jsx'
import Medicines from '../pages/Medicines.jsx'
import NotFound from '../pages/NotFound.jsx'
import Privacy from '../pages/Privacy.jsx'
import Register from '../pages/Register.jsx'
import Cart from '../pages/Cart.jsx'
import ReservationDetail from '../pages/ReservationDetail.jsx'
import Reservations from '../pages/Reservations.jsx'
import ReserveRedirect from '../pages/ReserveRedirect.jsx'
import Terms from '../pages/Terms.jsx'
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
      <Route element={<CustomerLayout />}>
        <Route path="/" element={<Home />} />
        <Route path="/medicines" element={<Medicines />} />
        <Route path="/medicines/:id" element={<MedicineDetails />} />
        <Route path="/guide" element={<Guide />} />
        <Route path="/privacy" element={<Privacy />} />
        <Route path="/terms" element={<Terms />} />
        <Route path="/forbidden" element={<Forbidden />} />

        {/* needs a login, and then the customer role */}
        <Route element={<ProtectedRoute />}>
          <Route element={<RoleRoute roles={['customer']} />}>
            <Route path="/reserve" element={<ReserveRedirect />} />
            <Route path="/cart" element={<Cart />} />
            <Route path="/reservations" element={<Reservations />} />
            <Route path="/reservations/:id" element={<ReservationDetail />} />
          </Route>
        </Route>

        <Route path="*" element={<NotFound />} />
      </Route>

      {/* the staff portal: needs a login and a staff role (or the CEO); the backend still decides what each role may do */}
      <Route element={<ProtectedRoute />}>
        <Route path="/staff" element={<StaffGate />}>
          <Route element={<RoleRoute roles={STAFF_ROLES} />}>
            <Route index element={<Navigate to="/staff/reservations" replace />} />
            <Route path="reservations" element={<StaffReservations />} />
            <Route path="reservations/:id" element={<StaffReservationDetail />} />
            <Route path="prescriptions" element={<StaffPrescriptions />} />
            <Route path="inventory" element={<StaffInventory />} />
            <Route path="stock-alerts" element={<StaffStockAlerts />} />
            <Route path="*" element={<NotFound />} />
          </Route>
        </Route>
      </Route>
    </Routes>
  )
}
