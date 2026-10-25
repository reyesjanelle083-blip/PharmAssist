import StaffSidebar from '../components/StaffSidebar.jsx'
import AppShell from './AppShell.jsx'

/** The staff portal: the shared shell with the staff sidebar. Routed under /staff (login and a staff role required). */
export default function StaffLayout() {
  return <AppShell renderSidebar={(closed) => <StaffSidebar closed={closed} />} />
}
