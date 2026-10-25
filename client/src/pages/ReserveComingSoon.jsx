import { Link } from 'react-router-dom'
import { usePageTitle } from '../hooks/usePageTitle.js'

/** Placeholder: the reservation form comes in the next step. */
export default function ReserveComingSoon() {
  usePageTitle('Reserve')

  return (
    <div className="form-page">
      <h1>Reserving is coming soon</h1>
      <p>You will be able to reserve this medicine here very soon. Nothing has been reserved.</p>
      <p>
        <Link className="button" to="/">
          Back to the search
        </Link>
      </p>
    </div>
  )
}
