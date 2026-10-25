import { NavLink } from 'react-router-dom'
import avatar from 'bootstrap-icons/icons/person-circle.svg'
import mark from '../assets/pharmassist-mark.png'
import { useAuth } from '../context/useAuth.js'

const ROLE_LABELS = {
  customer: 'Customer',
  pharmacy_technician: 'Pharmacy Technician',
  pharmacy_assistant: 'Pharmacy Assistant',
  branch_manager: 'Branch Manager',
  ceo: 'CEO',
}

/** One entry of the old sidebar: icon, link name, and the little name tag that shows when the bar is closed. */
function Item({ to, icon, label, end }) {
  return (
    <li>
      <NavLink to={to} end={end} className={({ isActive }) => (isActive ? 'is-current' : undefined)}>
        <i className={icon} aria-hidden="true"></i>
        <span className="link_name">{label}</span>
      </NavLink>
      <ul className="sub-menu blank" aria-hidden="true">
        <li>
          <a className="link_name" href={to} tabIndex={-1}>
            {label}
          </a>
        </li>
      </ul>
    </li>
  )
}

/** The left bar of the old pages (same markup and class names as sidebar.php). */
export default function Sidebar({ closed }) {
  const { user, logout } = useAuth()

  return (
    <div className={closed ? 'sidebar close' : 'sidebar'} id="site-sidebar">
      <div className="logo-details">
        <img src={mark} alt="" />
        <span className="logo_name">Menu</span>
      </div>

      <nav aria-label="Main">
        <ul className="nav-links">
          <Item to="/" end icon="bx bx-home" label="Home" />
          {user ? null : (
            <>
              <Item to="/login" icon="bi bi-box-arrow-in-right" label="Log in" />
              <Item to="/register" icon="bi bi-person-plus" label="Register" />
            </>
          )}
          <li>
            <div className="profile-details">
              <div className="profile-content">
                <img src={avatar} alt="" />
              </div>
              <div className="name-job">
                <div className="profile_name">
                  {user ? <><span className="visually-hidden">Logged in as </span>{user.first_name} {user.last_name}</> : 'Guest'}
                </div>
                <div className="job">{user ? ROLE_LABELS[user.role] ?? 'User' : 'Not logged in'}</div>
              </div>
              {user ? (
                <button type="button" className="logout" onClick={logout} aria-label="Log out" title="Log out">
                  <i className="bx bx-log-out" aria-hidden="true"></i>
                </button>
              ) : null}
            </div>
          </li>
        </ul>
      </nav>
    </div>
  )
}
