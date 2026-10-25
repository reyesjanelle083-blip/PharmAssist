import { MAX_QUANTITY } from '../context/cartContextObject.js'
import { formatCents, formatPeso } from '../utils/format.js'

/**
 * One line in the cart: thumb, name, prescription badge, quantity stepper, price and a remove button, built like the old
 * cart page (cart.php). `problem` is the {medicineId, requested, available} row from a STOCK_INSUFFICIENT answer, if any.
 */
export default function CartItem({ item, busy, problem, onQuantityChange, onRemove }) {
  return (
    <li className="cart-item">
      <div className="item-thumb" aria-hidden="true">
        <i className="bi bi-capsule"></i>
      </div>
      <div className="item-body">
        <h2 className="item-name">{item.name}</h2>
        <div className="item-badges">
          {item.requiresPrescription ? (
            <span className="badge-pill badge-rx-yes">Prescription required</span>
          ) : (
            <span className="badge-pill badge-rx-no">No Rx Needed</span>
          )}
          {item.detail ? <span className="meta-unit-price">{item.detail}</span> : null}
        </div>
        <div className="item-meta">
          <div className="qty-stepper" role="group" aria-label={`Quantity of ${item.name}`}>
            <button type="button" className="qty-btn" onClick={() => onQuantityChange(item.quantity - 1)} disabled={busy || item.quantity <= 1} aria-label={`Decrease quantity of ${item.name}`}>
              &minus;
            </button>
            <output className="qty-display" aria-live="polite" aria-label={`${item.name} quantity`}>
              {item.quantity}
            </output>
            <button
              type="button"
              className="qty-btn"
              onClick={() => onQuantityChange(item.quantity + 1)}
              disabled={busy || item.quantity >= Math.min(MAX_QUANTITY, item.available)}
              aria-label={`Increase quantity of ${item.name}`}
            >
              +
            </button>
          </div>
          <span className="meta-unit-price">
            @ <strong>{formatPeso(item.price)}</strong>/pc &middot; {item.available} available
          </span>
          <span className="item-line-total">{formatCents(Math.round(Number(item.price) * 100) * item.quantity)}</span>
          <button type="button" className="remove-btn" onClick={onRemove} disabled={busy} aria-label={`Remove ${item.name}`}>
            <i className="bi bi-trash3" aria-hidden="true"></i>
          </button>
        </div>
        {problem ? (
          <p className="vat-note short-note" role="alert">
            Only {problem.available} {problem.available === 1 ? 'is' : 'are'} available now (you asked for {problem.requested}).
          </p>
        ) : null}
      </div>
    </li>
  )
}
