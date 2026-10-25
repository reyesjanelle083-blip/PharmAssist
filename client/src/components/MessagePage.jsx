import { Link } from 'react-router-dom'

/** A plain message page in the old filter-panel look (white rounded box, blue-grey button). */
export default function MessagePage({ title, children, linkTo = '/', linkText = 'Back to the home page' }) {
  return (
    <div className="branch-container">
      <div className="global-filter" style={{ marginTop: 0, textAlign: 'center', padding: 40 }}>
        <h1 className="global-filter-title" style={{ fontSize: '1.6rem' }}>
          {title}
        </h1>
        {children}
        <Link className="filter-btn" to={linkTo} style={{ display: 'inline-block', textDecoration: 'none', marginTop: 10 }}>
          {linkText}
        </Link>
      </div>
    </div>
  )
}
