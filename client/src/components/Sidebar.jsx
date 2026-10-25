import { Link, NavLink } from 'react-router-dom'
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

/** The little name tag that shows when the bar is closed (mouse only, so it is hidden from keyboards and screen readers). */
function Tag({ label, to = '#' }) {
  return (
    <ul className="sub-menu blank" aria-hidden="true">
      <li>
        <a className="link_name" href={to} tabIndex={-1}>
          {label}
        </a>
      </li>
    </ul>
  )
}

/** One entry of the old sidebar: icon, link name, and the name tag. */
function Item({ to, icon, label, end, plain }) {
  const content = (
    <>
      <i className={icon} aria-hidden="true"></i>
      <span className="link_name">{label}</span>
    </>
  )

  return (
    <li>
      {plain ? (
        <Link to={to}>{content}</Link>
      ) : (
        <NavLink to={to} end={end} className={({ isActive }) => (isActive ? 'is-current' : undefined)}>
          {content}
        </NavLink>
      )}
      <Tag label={label} to={typeof to === 'string' ? to : '#'} />
    </li>
  )
}

/** The left bar of the old pages (same markup and class names as sidebar.php). */
export default function Sidebar({ closed }) {
  const { user, logout } = useAuth()
  const name = user ? `${user.first_name} ${user.last_name}` : 'Guest'
  const job = user ? ROLE_LABELS[user.role] ?? 'User' : 'Not logged in'

  return (
    <div className={closed ? 'sidebar close' : 'sidebar'} id="site-sidebar">
      <div className="logo-details">
        <img src={mark} alt="" />
        <span className="logo_name">Menu</span>
      </div>

      <nav aria-label="Main">
        <ul className="nav-links">
          <Item to="/" end icon="bx bx-home" label="Home" />
          <Item to={{ pathname: '/', hash: '#branches' }} plain icon="bi bi-geo-alt" label="Branches" />
          <Item to="/guide" icon="bx bx-bulb" label="Guide" />

          {/*
            Cart, Notification and Messages go here (the old sidebar had them) once those pages exist.
            They are not linked yet: a link to a page that does not exist is worse than no link.
          */}

          {user ? (
            <li>
              <button type="button" className="nav-button" onClick={logout}>
                <i className="bx bx-log-out" aria-hidden="true"></i>
                <span className="link_name">Log out</span>
              </button>
              <Tag label="Log out" />
            </li>
          ) : (
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
                <div className="profile_name" title={name}>
                  {user ? <span className="visually-hidden">Logged in as </span> : null}
                  {name}
                </div>
                <div className="job" title={job}>
                  {job}
                </div>
              </div>
            </div>
          </li>
        </ul>
      </nav>
    </div>
  )
}
