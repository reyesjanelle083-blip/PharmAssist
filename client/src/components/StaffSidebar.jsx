import avatar from 'bootstrap-icons/icons/person-circle.svg'
import mark from '../assets/pharmassist-mark.png'
import { useAuth } from '../context/useAuth.js'
import { ROLE_LABELS } from '../utils/staff.js'
import {
  Item,
  Tag,
} from './SidebarParts.jsx'

/**
 * Staff portal navigation.
 * Every staff role uses the same pages;
 * backend RBAC remains the source of truth
 * for actions and branch access.
 */
export default function StaffSidebar({
  closed,
}) {
  const {
    user,
    logout,
  } = useAuth()

  const name = user
    ? `${user.first_name} ${user.last_name}`
    : ''

  const job =
    ROLE_LABELS[user?.role] ??
    'Staff'

  return (
    <aside
      className={
        closed
          ? 'sidebar close'
          : 'sidebar'
      }
      id="site-sidebar"
      aria-label="Staff menu"
    >
      <div className="logo-details">
        <img
          src={mark}
          alt=""
        />

        <span className="logo_name">
          Staff
        </span>
      </div>

      <nav aria-label="Staff">
        <ul className="nav-links">
          <Item
            to="/staff"
            end
            icon="bi bi-grid-1x2-fill"
            label="Dashboard"
          />

          <Item
            to="/staff/reservations"
            icon="bi bi-card-list"
            label="Reservations"
          />

          <Item
            to="/staff/prescriptions"
            icon="bi bi-file-medical"
            label="Prescription review"
          />

          <Item
            to="/staff/inventory"
            icon="bi bi-boxes"
            label="Inventory"
          />

          <Item
            to="/staff/stock-alerts"
            icon="bi bi-exclamation-triangle"
            label="Stock alerts"
          />

          <Item
            to="/"
            end
            icon="bx bx-home"
            label="Public site"
          />

          <li>
            <button
              type="button"
              className="nav-button"
              onClick={logout}
            >
              <i
                className="bx bx-log-out"
                aria-hidden="true"
              ></i>

              <span className="link_name">
                Log out
              </span>
            </button>

            <Tag label="Log out" />
          </li>

          <li>
            <div className="profile-details">
              <div className="profile-content">
                <img
                  src={avatar}
                  alt=""
                />
              </div>

              <div className="name-job">
                <div
                  className="profile_name"
                  title={name}
                >
                  <span className="visually-hidden">
                    Logged in as{' '}
                  </span>

                  {name}
                </div>

                <div
                  className="job"
                  title={job}
                >
                  {job}
                </div>
              </div>
            </div>
          </li>
        </ul>
      </nav>
    </aside>
  )
}