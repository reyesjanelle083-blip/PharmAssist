import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { formatCents, formatDateTime, formatPeso, toCents } from '../utils/format.js'

/**
 * The success screen: the claim code in large type, what was reserved, when it expires, and what to do next. The ticket part
 * (class claim-ticket) is the only thing printed (see the print rules in app-extra.css). Focus moves to the heading.
 */
export default function ClaimTicket({ reservation, branchName }) {
  const heading = useRef(null)
  const [copyStatus, setCopyStatus] = useState('')

  useEffect(() => {
    heading.current?.focus()
  }, [])

  const totalCents = reservation.items.reduce((sum, item) => sum + toCents(item.unit_price) * item.quantity, 0)

  async function copy() {
    try {
      await navigator.clipboard.writeText(reservation.claim_code)
      setCopyStatus('Code copied.')
    } catch {
      setCopyStatus('Could not copy automatically. Please select the code and copy it.')
    }
  }

  return (
    <div className="legacy-cart legacy-notif">
    <div className="cart-wrapper ticket-page">
      <div className="page-header">
        <div className="page-header-left">
          <h1 ref={heading} tabIndex={-1}>
            Reservation sent
          </h1>
          <p>Your reservation is pending approval by the branch.</p>
        </div>
        <div className="header-actions no-print">
          <button type="button" className="btn-outline btn-outline-blue" onClick={() => window.print()}>
            <i className="bi bi-printer" aria-hidden="true"></i> Print ticket
          </button>
        </div>
      </div>

      <section className="summary-card claim-ticket" aria-label="Your claim ticket">
        <div className="summary-head">
          <h2>PharmAssist reservation</h2>
          <p>Show this code at the branch</p>
        </div>
        <div className="summary-body">
          <p className="claim-label">Claim code</p>
          <p className="claim-code" aria-label={`Claim code ${reservation.claim_code.split('').join(' ')}`}>
            {reservation.claim_code}
          </p>
          <div className="no-print claim-copy">
            <button type="button" className="btn-outline btn-outline-blue" onClick={copy}>
              <i className="bi bi-clipboard" aria-hidden="true"></i> Copy code
            </button>
            <span role="status" className="claim-copy-status">
              {copyStatus}
            </span>
          </div>

          <dl className="claim-facts">
            <div>
              <dt>Status</dt>
              <dd>
                <span className="status-pending">Pending</span>
              </dd>
            </div>
            <div>
              <dt>Branch</dt>
              <dd>{branchName}</dd>
            </div>
            <div>
              <dt>Held until</dt>
              <dd>{formatDateTime(reservation.expires_at)} (held for 48 hours)</dd>
            </div>
          </dl>

          <div className="form-section-title">Items</div>
          <ul className="claim-items">
            {reservation.items.map((item) => (
              <li key={item.medicine_id} className="bk-row">
                <span>
                  {item.medicine_name} &times; {item.quantity}
                </span>
                <span>{formatPeso(Number(item.unit_price) * item.quantity)}</span>
              </li>
            ))}
          </ul>
          <div className="breakdown">
            <div className="bk-row total-row">
              <span>Total</span>
              <span className="total-amt">{formatCents(totalCents)}</span>
            </div>
          </div>
          <p className="claim-instruction">
            <strong>Show this code at the branch.</strong> You pay at the branch. The pharmacist always checks the original prescription.
          </p>
        </div>
      </section>

      <p className="no-print ticket-links">
        <Link className="btn-outline btn-outline-blue" to="/reservations">
          My Reservations
        </Link>{' '}
        <Link className="btn-outline btn-outline-blue" to="/">
          Back to Home
        </Link>
      </p>
    </div>
    </div>
  )
}
