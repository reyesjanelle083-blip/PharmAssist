import { Link, Outlet } from 'react-router-dom'

export default function Layout() {
  return (
    <>
      <a className="skip-link" href="#main-content">
        Skip to main content
      </a>
      <header className="site-header">
        <Link to="/" className="brand">
          PharmAssist
        </Link>
      </header>
      <main id="main-content" tabIndex={-1}>
        <Outlet />
      </main>
    </>
  )
}
