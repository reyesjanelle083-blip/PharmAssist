import { useEffect, useRef } from 'react'
import { Link, Outlet, useLocation } from 'react-router-dom'
import logo from '../assets/pharmassist-logo.png'
import AccessibilityToolbar from './AccessibilityToolbar.jsx'

/**
 * The login and register pages of the old site: no sidebar, the logo top left, the form in a white column on the left and the
 * blue picture panel on the right (login.php / register.php, same ids and class names).
 */
export default function AuthLayout() {
  const location = useLocation()
  const main = useRef(null)
  const first = useRef(true)

  useEffect(() => {
    if (first.current) {
      first.current = false
      return
    }
    main.current?.focus()
  }, [location.pathname])

  return (
    <div className="legacy-auth">
      <a className="skip-link" href="#main-content">
        Skip to main content
      </a>

      <div id="login-page">
        <header id="logo">
          <Link to="/" aria-label="PharmAssist home">
            <img src={logo} alt="PharmAssist" />
          </Link>
        </header>

        <main id="main-content" ref={main} tabIndex={-1} className="login">
          <Outlet />
        </main>

        <aside className="background" aria-label="PharmAssist">
          <h1>
            Your Medicine,
            <br /> Minutes Away
          </h1>
        </aside>
      </div>

      <AccessibilityToolbar />
    </div>
  )
}
