import { useEffect, useRef, useState } from 'react'
import { Link, Outlet, useLocation } from 'react-router-dom'
import AccessibilityToolbar from './AccessibilityToolbar.jsx'
import Footer from './Footer.jsx'
import Sidebar from './Sidebar.jsx'

/**
 * The shell of the old pages: sidebar, top bar (home-content), page area (page-wrapper) and footer, with the same
 * class names, so sidebar.css, homepage.css and footer.css apply as designed.
 */
export default function Layout() {
  const [closed, setClosed] = useState(true) // the old sidebar starts as the narrow icon bar
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
    <div className="legacy-shell">
      <a className="skip-link" href="#main-content">
        Skip to main content
      </a>

      <Sidebar closed={closed} />

      <section className="home-section">
        <header className="home-content d-flex align-items-center justify-content-between px-4">
          <div className="d-flex align-items-center gap-2">
            <button
              type="button"
              className="menu-toggle"
              onClick={() => setClosed((value) => !value)}
              aria-label={closed ? 'Open the menu' : 'Close the menu'}
              aria-expanded={!closed}
              aria-controls="site-sidebar"
            >
              <i className="bx bx-menu" style={{ fontSize: '1.8rem' }} aria-hidden="true"></i>
            </button>
            <span>
              <Link to="/" style={{ textDecoration: 'none', color: 'white', fontSize: '1.5rem' }} className="text fw-semibold">
                PharmAssist
              </Link>
            </span>
          </div>
        </header>

        <main id="main-content" ref={main} tabIndex={-1} className="page-wrapper">
          <Outlet />
        </main>
      </section>

      <Footer />
      <AccessibilityToolbar />
    </div>
  )
}
