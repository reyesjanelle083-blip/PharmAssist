import { useEffect, useRef } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import logo from '../assets/pharmassist-logo.png'
import { useAuth } from '../context/useAuth.js'
import AccessibilityToolbar from './AccessibilityToolbar.jsx'

export default function Layout() {
  const { user, logout } = useAuth()
  const location = useLocation()
  const main = useRef(null)
  const first = useRef(true)

  // After a page change, move focus to the main area so keyboard and screen reader users start at the new content.
  useEffect(() => {
    if (first.current) {
      first.current = false
      return
    }
    main.current?.focus()
  }, [location.pathname])

  return (
    <>
      <a className="skip-link" href="#main-content">
        Skip to main content
      </a>
      <AccessibilityToolbar />

      <header className="site-header">
        <div className="site-header__inner">
          <Link to="/" className="brand">
            <img className="brand__logo" src={logo} alt="" width="48" height="48" />
            <span className="brand__name">PharmAssist</span>
          </Link>

          <nav aria-label="Main" className="main-nav">
            <ul>
              <li>
                <NavLink to="/" end>
                  Home
                </NavLink>
              </li>
              {user ? (
                <>
                  <li className="main-nav__user">
                    <span>
                      <span className="visually-hidden">Logged in as </span>
                      {user.first_name} {user.last_name}
                    </span>
                  </li>
                  <li>
                    <button type="button" className="button button--outline" onClick={logout}>
                      Log out
                    </button>
                  </li>
                </>
              ) : (
                <>
                  <li>
                    <NavLink to="/login">Log in</NavLink>
                  </li>
                  <li>
                    <NavLink to="/register">Register</NavLink>
                  </li>
                </>
              )}
            </ul>
          </nav>
        </div>
      </header>

      <main id="main-content" ref={main} tabIndex={-1}>
        <Outlet />
      </main>

      <footer className="site-footer">
        <div className="site-footer__inner">
          <p>
            <strong>PharmAssist</strong> is a school project (Systems Integration and Architecture). It is not a real pharmacy service.
          </p>
          <p>
            The information on this site is not medical advice. Ask a pharmacist or a doctor before you take any medicine.
          </p>
        </div>
      </footer>
    </>
  )
}
