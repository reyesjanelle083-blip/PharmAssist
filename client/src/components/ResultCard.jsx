import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/useAuth.js'
import { formatPeso, formatTravel } from '../utils/format.js'

const STAFF_ROLES = ['pharmacy_technician', 'pharmacy_assistant', 'branch_manager', 'ceo']
const LOW_STOCK_AT = 5

/** One medicine at one branch, as returned by GET /availability (already ranked). */
export default function ResultCard({ item, headingLevel = 3 }) {
  const { role } = useAuth()
  const navigate = useNavigate()
  const Heading = `h${headingLevel}`

  const travel = formatTravel(item.travel_min, item.distance_km)
  const estimated = item.distance_source === 'haversine' || item.distance_source === 'stub'
  const lowStock = item.available_quantity <= LOW_STOCK_AT

  const reservePath = `/reserve?branch=${item.branch_id}&medicine=${item.medicine_id}`

  function reserve() {
    if (role === 'customer') navigate(reservePath)
    else navigate('/login', { state: { notice: 'login_required', from: reservePath } }) // a guest logs in first
  }

  return (
    <article className="card">
      <div className="card__body">
        <Heading className="card__title">{item.medicine_name}</Heading>
        <p className="card__meta">
          {item.strength} &middot; {item.dosage_form}
        </p>
        {item.prescription_required ? <p className="badge badge--rx">Prescription required</p> : null}

        <p className="card__branch">
          <strong>{item.branch_name}</strong>
          <br />
          {item.address}
        </p>

        <dl className="card__facts">
          <div>
            <dt>Price</dt>
            <dd className="card__price">{formatPeso(item.price)}</dd>
          </div>
          <div>
            <dt>In stock</dt>
            <dd>
              {item.available_quantity} available {lowStock ? <span className="badge badge--low">Low stock</span> : null}
            </dd>
          </div>
          {travel ? (
            <div>
              <dt>Travel</dt>
              <dd>
                About {travel}
                {estimated ? <span className="card__estimated"> (estimated)</span> : null}
              </dd>
            </div>
          ) : null}
        </dl>
      </div>

      {STAFF_ROLES.includes(role) ? null : (
        <div className="card__actions">
          <button type="button" className="button" onClick={reserve}>
            Reserve <span className="visually-hidden">{item.medicine_name} at {item.branch_name}</span>
          </button>
        </div>
      )}
    </article>
  )
}
