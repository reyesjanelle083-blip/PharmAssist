import { Link } from 'react-router-dom'

/**
 * A text page in the look of the old guide page (guide.php): a centred title, then white rounded sections.
 * Used by the guide, the privacy notice and the terms.
 */
export default function ContentPage({ title, subtitle, children, backTo = '/', backText = 'Back to Search' }) {
  return (
    <div className="legacy-guide">
      <div className="page-header">
        <h1>{title}</h1>
        {subtitle ? <p>{subtitle}</p> : null}
      </div>

      {children}

      <p className="content-back">
        <Link to={backTo} className="filter-btn" style={{ display: 'inline-block', textDecoration: 'none' }}>
          <i className="bi bi-arrow-left" aria-hidden="true"></i> {backText}
        </Link>
      </p>
    </div>
  )
}
