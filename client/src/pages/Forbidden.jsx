import { Link } from 'react-router-dom'
import { usePageTitle } from '../hooks/usePageTitle.js'

export default function Forbidden() {
  usePageTitle('No access')

  return (
    <div className="form-page">
      <h1>You do not have access to this page</h1>
      <p>Your account does not have permission to open it (error 403). If you think this is a mistake, please contact the pharmacy.</p>
      <p>
        <Link className="button" to="/">
          Back to the home page
        </Link>
      </p>
    </div>
  )
}
