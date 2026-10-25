import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/useAuth.js'
import { useCart } from '../context/useCart.js'
import { formatPeso, formatTravel } from '../utils/format.js'

const STAFF_ROLES = ['pharmacy_technician', 'pharmacy_assistant', 'branch_manager', 'ceo']
const LOW_STOCK_AT = 5

/**
 * One medicine at one branch, built like the old medicine card (medicines.php): image area, title, prescription line,
 * category line, status chip, price and the Reserve button. The old image came from the uploads folder, which is not reused:
 * the availability answer has no picture, so the image area shows a capsule icon.
 */
export default function ResultCard({ item }) {
  const { role } = useAuth()
  const cart = useCart()
  const navigate = useNavigate()

  const travel = formatTravel(item.travel_min, item.distance_km)
  const estimated = item.distance_source === 'haversine' || item.distance_source === 'stub'
  const lowStock = item.available_quantity <= LOW_STOCK_AT

  const reservePath = `/reserve?branch=${item.branch_id}&medicine=${item.medicine_id}`

  function reserve() {
    if (role === 'customer') cart.add(item, { go: true }) // adds it and opens the cart (asks first when the cart is for another branch)
    else navigate('/login', { state: { notice: 'login_required', from: reservePath } }) // a guest logs in first
  }

  return (
    <div className="card" role="listitem">
      <div className="card-image">
        <i className="bi bi-capsule" aria-hidden="true"></i>
      </div>
      <div className="card-body">
        <h4 className="card-title">{item.medicine_name}</h4>
        <div className="prescription-status">{item.prescription_required ? 'Prescription required' : 'Non-prescribed Medication'}</div>
        <div className="card-category">
          {item.strength} &middot; {item.dosage_form}
        </div>
        <div className="card-category">
          <i className="bi bi-geo-alt" aria-hidden="true"></i>
          <strong>{item.branch_name}</strong> &ndash; {item.address}
        </div>
        {travel ? (
          <div className="card-category">
            <i className="bi bi-clock" aria-hidden="true"></i>
            About {travel}
            {estimated ? <span className="estimated"> (estimated)</span> : null}
          </div>
        ) : null}
        <div className={lowStock ? 'card-status status-low-stock' : 'card-status status-available'}>
          {lowStock ? 'Low Stock' : 'Available'} &middot; {item.available_quantity} in stock
        </div>
        <div className="card-price">{formatPeso(item.price)}</div>

        {STAFF_ROLES.includes(role) ? null : (
          <button type="button" className="reserve-button" onClick={reserve}>
            Reserve <span className="visually-hidden">{item.medicine_name} at {item.branch_name}</span>
          </button>
        )}
      </div>
    </div>
  )
}
