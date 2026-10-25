import Sidebar from '../components/Sidebar.jsx'
import AppShell from './AppShell.jsx'

/**
 * Foundation for the staff portal (Phase 8). For now it is the same shell with the same sidebar; Phase 8 will pass a role-based staff
 * sidebar here. It is not routed yet, so nothing changes for users.
 */
export default function StaffLayout() {
  return <AppShell renderSidebar={(closed) => <Sidebar closed={closed} />} />
}
