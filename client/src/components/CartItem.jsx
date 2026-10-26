import { useState } from 'react'

import {
  MAX_QUANTITY,
} from '../context/cartContextObject.js'

import {
  formatCents,
  formatPeso,
} from '../utils/format.js'

export default function CartItem({
  item,
  busy,
  problem,
  onQuantityChange,
  onRemove,
}) {
  const [reserveBy, setReserveBy] =
    useState('piece')

    const activeReserveBy =
      reserveBy === 'ten' &&
      item.quantity % 10 !== 0
        ? 'piece'
        : reserveBy

  const maxQuantity = Math.min(
    MAX_QUANTITY,
    item.available,
  )

  /*
   * If live stock changes and the cart quantity is no
   * longer an exact group of 10, return to piece mode.
   */

  const canUseTen =
    maxQuantity >= 10

  const step =
    activeReserveBy === 'ten'
      ? 10
      : 1

  function choosePiece() {
    setReserveBy('piece')
  }

  function chooseTen() {
    if (!canUseTen || busy) return

    setReserveBy('ten')

    const next = Math.min(
      maxQuantity,
      Math.max(
        10,
        Math.ceil(
          item.quantity / 10,
        ) * 10,
      ),
    )

    /*
     * When max stock is between 10 and 19, use 10.
     * We do not create a partial "set of 10".
     */
    onQuantityChange(
      next >= 20
        ? 20
        : 10,
    )
  }

  function decrease() {
    if (activeReserveBy === 'ten') {
      onQuantityChange(
        Math.max(
          10,
          item.quantity - 10,
        ),
      )

      return
    }

    onQuantityChange(
      item.quantity - 1,
    )
  }

  function increase() {
    onQuantityChange(
      item.quantity + step,
    )
  }

  const decreaseDisabled =
    busy ||
    (activeReserveBy === 'ten'
      ? item.quantity <= 10
      : item.quantity <= 1)

  const increaseDisabled =
    busy ||
    item.quantity + step >
      maxQuantity

  const lineTotal =
    Math.round(
      Number(item.price) * 100,
    ) * item.quantity

  const groupedQuantity =
    activeReserveBy === 'ten' &&
    item.quantity % 10 === 0
      ? item.quantity / 10
      : null

  return (
    <li className="final-cart-item">
      <div
        className="final-cart-item__thumb"
        aria-hidden="true"
      >
        <i className="bi bi-capsule-pill"></i>
      </div>

      <div className="final-cart-item__main">
        <div className="final-cart-item__heading">
          <div>
            <h2>
              {item.name}
            </h2>

            {item.detail ? (
              <p>
                {item.detail}
              </p>
            ) : null}
          </div>

          <button
            type="button"
            className="final-cart-item__remove"
            onClick={onRemove}
            disabled={busy}
            aria-label={`Remove ${item.name}`}
          >
            <i
              className="bi bi-trash3"
              aria-hidden="true"
            ></i>
          </button>
        </div>

        <div className="final-cart-item__badges">
          {item.requiresPrescription ? (
            <span className="final-cart-badge final-cart-badge--rx">
              <i
                className="bi bi-file-medical"
                aria-hidden="true"
              ></i>
              Prescription required
            </span>
          ) : (
            <span className="final-cart-badge final-cart-badge--otc">
              <i
                className="bi bi-check-circle"
                aria-hidden="true"
              ></i>
              No prescription needed
            </span>
          )}

          <span className="final-cart-badge final-cart-badge--stock">
            <i
              className="bi bi-box-seam"
              aria-hidden="true"
            ></i>
            {item.available} available
          </span>
        </div>

        <div className="final-cart-item__reserve-by">
          <span className="final-cart-item__label">
            Reserve by
          </span>

          <div
            className="final-cart-unit-options"
            role="group"
            aria-label={`How to reserve ${item.name}`}
          >
            <button
              type="button"
              className={
                activeReserveBy === 'piece'
                  ? 'final-cart-unit-option final-cart-unit-option--active'
                  : 'final-cart-unit-option'
              }
              aria-pressed={
                activeReserveBy === 'piece'
              }
              onClick={choosePiece}
              disabled={busy}
            >
              <i
                className="bi bi-1-circle"
                aria-hidden="true"
              ></i>

              <span>
                <strong>
                  Per piece
                </strong>

                <small>
                  1 piece at a time
                </small>
              </span>
            </button>

            <button
              type="button"
              className={
                activeReserveBy === 'ten'
                  ? 'final-cart-unit-option final-cart-unit-option--active'
                  : 'final-cart-unit-option'
              }
              aria-pressed={
                activeReserveBy === 'ten'
              }
              onClick={chooseTen}
              disabled={
                busy ||
                !canUseTen
              }
              title={
                canUseTen
                  ? 'Reserve in groups of 10 pieces'
                  : 'At least 10 pieces must be available'
              }
            >
              <i
                className="bi bi-box"
                aria-hidden="true"
              ></i>

              <span>
                <strong>
                  Per 10
                </strong>

                <small>
                  10 pieces per set
                </small>
              </span>
            </button>
          </div>

          {!canUseTen ? (
            <p className="final-cart-unit-note">
              Per 10 is unavailable because
              fewer than 10 pieces are currently
              in stock.
            </p>
          ) : null}
        </div>

        <div className="final-cart-item__controls">
          <div>
            <span className="final-cart-item__label">
              Quantity
            </span>

            <div
              className="final-cart-stepper"
              role="group"
              aria-label={`Quantity of ${item.name}`}
            >
              <button
                type="button"
                onClick={decrease}
                disabled={
                  decreaseDisabled
                }
                aria-label={`Decrease quantity of ${item.name}`}
              >
                <i
                  className="bi bi-dash"
                  aria-hidden="true"
                ></i>
              </button>

              <output
                aria-live="polite"
                aria-label={`${item.name} quantity`}
              >
                <strong>
                  {groupedQuantity !== null
                    ? groupedQuantity
                    : item.quantity}
                </strong>

                <span>
                  {groupedQuantity !== null
                    ? groupedQuantity === 1
                      ? 'set'
                      : 'sets'
                    : item.quantity === 1
                      ? 'piece'
                      : 'pieces'}
                </span>
              </output>

              <button
                type="button"
                onClick={increase}
                disabled={
                  increaseDisabled
                }
                aria-label={`Increase quantity of ${item.name}`}
              >
                <i
                  className="bi bi-plus"
                  aria-hidden="true"
                ></i>
              </button>
            </div>

            {groupedQuantity !== null ? (
              <p className="final-cart-actual-pieces">
                {item.quantity} actual pieces
                will be reserved.
              </p>
            ) : null}
          </div>

          <div className="final-cart-item__price-info">
            <span>
              Unit price
            </span>

            <strong>
              {formatPeso(item.price)}
            </strong>

            <small>
              per piece
            </small>
          </div>

          <div className="final-cart-item__line-total">
            <span>
              Subtotal
            </span>

            <strong>
              {formatCents(
                lineTotal,
              )}
            </strong>
          </div>
        </div>

        {problem ? (
          <div
            className="final-cart-stock-warning"
            role="alert"
          >
            <i
              className="bi bi-exclamation-triangle-fill"
              aria-hidden="true"
            ></i>

            <span>
              Only{' '}
              <strong>
                {problem.available}
              </strong>{' '}
              pieces are available now.
              You requested{' '}
              <strong>
                {problem.requested}
              </strong>
              .
            </span>
          </div>
        ) : null}
      </div>
    </li>
  )
}