import Sidebar from '../components/Sidebar.jsx'
import AppShell from './AppShell.jsx'

/** The customer pages: the shared shell with the customer sidebar (Home, Branches, Guide, Cart, My Reservations, log in or out). */
export default function CustomerLayout() {
  return <AppShell renderSidebar={(closed) => <Sidebar closed={closed} />} />
}
