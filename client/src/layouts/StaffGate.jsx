import { useAuth } from '../context/useAuth.js'
import { isStaffRole } from '../utils/staff.js'
import CustomerLayout from './CustomerLayout.jsx'
import StaffLayout from './StaffLayout.jsx'

/**
 * The shell for /staff: the staff layout for staff, the ordinary site shell for anyone else, so a customer who opens a staff
 * address gets the 403 page inside a normal page (with its sidebar and main area), not a bare message.
 */
export default function StaffGate() {
  const { role } = useAuth()
  return isStaffRole(role) ? <StaffLayout /> : <CustomerLayout />
}
