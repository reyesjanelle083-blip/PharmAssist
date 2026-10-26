import {
  useEffect,
  useRef,
  useState,
} from 'react'

import {
  Link,
  Outlet,
  useLocation,
} from 'react-router-dom'

import AccessibilityToolbar from '../components/AccessibilityToolbar.jsx'
import Footer from '../components/Footer.jsx'
import TopSearch from '../components/TopSearch.jsx'
import { SearchProvider } from '../context/SearchContext.jsx'

export default function AppShell({
  renderSidebar,
  staff = false,
}) {
  const [closed, setClosed] =
    useState(true)

  const location =
    useLocation()

  const main =
    useRef(null)

  const first =
    useRef(true)

  useEffect(() => {
    if (first.current) {
      first.current = false
      return
    }

    if (!location.hash) {
      main.current?.focus()
    }
  }, [
    location.pathname,
    location.hash,
  ])

  return (
    <SearchProvider>
      <div
        className={
          staff
            ? 'legacy-shell staff-shell'
            : 'legacy-shell'
        }
      >
        <a
          className="skip-link"
          href="#main-content"
        >
          Skip to main content
        </a>

        {renderSidebar(closed)}

        <section className="home-section">
          <header
            role="banner"
            className="home-content d-flex align-items-center justify-content-between px-4"
          >
            <div className="d-flex align-items-center gap-2 topbar-brand">
              <button
                type="button"
                className="menu-toggle"
                onClick={() =>
                  setClosed(
                    (value) =>
                      !value,
                  )
                }
                aria-label={
                  closed
                    ? 'Open the menu'
                    : 'Close the menu'
                }
                aria-expanded={
                  !closed
                }
                aria-controls="site-sidebar"
              >
                <i
                  className="bx bx-menu"
                  style={{
                    fontSize:
                      '1.8rem',
                  }}
                  aria-hidden="true"
                ></i>
              </button>

              <span>
                <Link
                  to={
                    staff
                      ? '/staff'
                      : '/'
                  }
                  className="text fw-semibold"
                  style={{
                    textDecoration:
                      'none',
                    color: 'white',
                    fontSize:
                      '1.5rem',
                  }}
                >
                  PharmAssist
                </Link>
              </span>
            </div>

            {staff ? (
              <div className="staff-topbar-context">
                <span className="staff-topbar-context__icon">
                  <i
                    className="bi bi-person-workspace"
                    aria-hidden="true"
                  ></i>
                </span>

                <div>
                  <strong>
                    Staff Portal
                  </strong>

                  <span>
                    Operations workspace
                  </span>
                </div>
              </div>
            ) : (
              <TopSearch />
            )}
          </header>

          <main
            id="main-content"
            ref={main}
            tabIndex={-1}
            className="page-wrapper"
          >
            <Outlet />
          </main>
        </section>

        <Footer />

        <AccessibilityToolbar
          className={
            staff
              ? 'floating-buttons--staff'
              : ''
          }
        />
      </div>
    </SearchProvider>
  )
}