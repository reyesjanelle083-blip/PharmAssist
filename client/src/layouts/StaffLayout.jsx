import StaffSidebar from '../components/StaffSidebar.jsx'
import AppShell from './AppShell.jsx'

import '../styles/staff-shell.css'

export default function StaffLayout() {
  return (
    <AppShell
      staff
      renderSidebar={(closed) => (
        <StaffSidebar
          closed={closed}
        />
      )}
    />
  )
}