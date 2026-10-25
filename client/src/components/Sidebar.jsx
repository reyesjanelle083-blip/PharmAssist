import avatar from 'bootstrap-icons/icons/person-circle.svg'
import mark from '../assets/pharmassist-mark.png'
import { useAuth } from '../context/useAuth.js'
import { useCart } from '../context/useCart.js'
import { isStaffRole, ROLE_LABELS } from '../utils/staff.js'
import { Item, Tag } from './SidebarParts.jsx'

/** The left bar of the old pages (same markup and class names as sidebar.php). */
export default function Sidebar({ closed }) {
  const { user, logout } = useAuth()
  const cart = useCart()
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
          <Item to="/medicines" end icon="bi bi-capsule" label="Medicines" />
          <Item to={{ pathname: '/', hash: '#branches' }} plain icon="bi bi-geo-alt" label="Branches" />
          <Item to="/guide" icon="bx bx-bulb" label="Guide" />

          {/* Customers only: the cart and their reservations. Staff and the CEO have the staff portal. */}
          {user?.role === 'customer' ? (
            <>
              <Item to="/cart" icon="bi bi-cart" label={cart.count > 0 ? `Cart (${cart.count})` : 'Cart'} />
              <Item to="/reservations" icon="bi bi-card-list" label="My Reservations" />
            </>
          ) : null}

          {isStaffRole(user?.role) ? <Item to="/staff" icon="bi bi-clipboard2-pulse" label="Staff portal" /> : null}

          {/*
            Notification and Messages go here (the old sidebar had them) once those pages exist.
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
