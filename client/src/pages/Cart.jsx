import {
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'

import {
  createReservation,
  friendlyMessage,
  ocrPrescription,
  shortages,
} from '../api/index.js'

import CartItem from '../components/CartItem.jsx'
import ClaimTicket from '../components/ClaimTicket.jsx'
import ConfirmDialog from '../components/ConfirmDialog.jsx'
import ErrorMessage from '../components/ErrorMessage.jsx'
import PrescriptionUploader from '../components/PrescriptionUploader.jsx'
import Button from '../components/ui/Button.jsx'
import EmptyState from '../components/ui/EmptyState.jsx'
import PageHeader from '../components/ui/PageHeader.jsx'
import { useCart } from '../context/useCart.js'
import { usePageTitle } from '../hooks/usePageTitle.js'
import { formatCents } from '../utils/format.js'

import '../styles/cart-final.css'

const MAX_PHOTO_BYTES = 4 * 1024 * 1024

const PHOTO_TYPES = [
  'image/jpeg',
  'image/png',
]

const NOTES_MAX = 500

function photoProblem(file) {
  if (!PHOTO_TYPES.includes(file.type)) {
    return 'The photo must be a JPG or PNG picture.'
  }

  if (file.size > MAX_PHOTO_BYTES) {
    return 'The photo must be 4 MB or smaller.'
  }

  return ''
}

function confidencePercent(confidence) {
  if (
    confidence === null ||
    confidence === undefined ||
    confidence === ''
  ) {
    return null
  }

  const value = Number(confidence)

  if (!Number.isFinite(value)) {
    return null
  }

  return Math.max(
    0,
    Math.min(
      100,
      Math.round(
        value <= 1
          ? value * 100
          : value,
      ),
    ),
  )
}

function ProgressSteps({ step }) {
  const current =
    step === 'cart'
      ? 1
      : step === 'checkout'
        ? 2
        : 3

  const steps = [
    {
      number: 1,
      label: 'Cart',
      icon: 'bi bi-cart3',
    },
    {
      number: 2,
      label: 'Details',
      icon: 'bi bi-file-earmark-text',
    },
    {
      number: 3,
      label: 'Review',
      icon: 'bi bi-check2-circle',
    },
  ]

  return (
    <div
      className="checkout-progress"
      aria-label={`Reservation step ${current} of 3`}
    >
      {steps.map((item, index) => (
        <div
          key={item.number}
          className={
            item.number === current
              ? 'checkout-progress__step checkout-progress__step--current'
              : item.number < current
                ? 'checkout-progress__step checkout-progress__step--done'
                : 'checkout-progress__step'
          }
        >
          <span className="checkout-progress__circle">
            {item.number < current ? (
              <i
                className="bi bi-check-lg"
                aria-hidden="true"
              ></i>
            ) : (
              <i
                className={item.icon}
                aria-hidden="true"
              ></i>
            )}
          </span>

          <span className="checkout-progress__label">
            {item.label}
          </span>

          {index < steps.length - 1 ? (
            <span
              className="checkout-progress__line"
              aria-hidden="true"
            ></span>
          ) : null}
        </div>
      ))}
    </div>
  )
}

function ReservationRecap({
  cart,
  showItems = true,
}) {
  return (
    <aside className="checkout-recap">
      <div className="checkout-recap__header">
        <div>
          <span>Reservation summary</span>
          <h2>Your pickup</h2>
        </div>

        <i
          className="bi bi-bag-check"
          aria-hidden="true"
        ></i>
      </div>

      <div className="checkout-recap__body">
        <div className="checkout-recap__branch">
          <span className="checkout-recap__branch-icon">
            <i
              className="bi bi-shop"
              aria-hidden="true"
            ></i>
          </span>

          <div>
            <span>Pickup branch</span>
            <strong>{cart.branchName}</strong>
          </div>
        </div>

        {showItems ? (
          <div className="checkout-recap__items">
            <span className="checkout-recap__label">
              Medicines
            </span>

            <ul>
              {cart.items.map((item) => (
                <li key={item.medicineId}>
                  <div>
                    <strong>{item.name}</strong>

                    {item.detail ? (
                      <span>{item.detail}</span>
                    ) : null}
                  </div>

                  <span>
                    × {item.quantity}{' '}
                    {item.quantity === 1
                      ? 'pc'
                      : 'pcs'}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}

        <div className="checkout-recap__total">
          <span>Estimated total</span>

          <strong>
            {formatCents(cart.totalCents)}
          </strong>
        </div>

        <div className="checkout-recap__payment">
          <i
            className="bi bi-wallet2"
            aria-hidden="true"
          ></i>

          <div>
            <strong>Pay at the branch</strong>
            <p>
              PharmAssist does not collect online
              payment.
            </p>
          </div>
        </div>
      </div>
    </aside>
  )
}

function ReviewPrescription({
  preview,
  ocr,
}) {
  const confidence =
    confidencePercent(
      ocr?.confidence,
    )

  const hasText =
    ocr?.phase === 'done' &&
    Boolean(ocr?.text?.trim())

  return (
    <section className="checkout-review-rx">
      <div className="checkout-section-heading">
        <span className="checkout-section-heading__icon">
          <i
            className="bi bi-file-medical"
            aria-hidden="true"
          ></i>
        </span>

        <div>
          <span>Prescription</span>
          <h2>Verify the uploaded prescription</h2>
          <p>
            The pharmacist will check the original
            image. OCR text is only an automated
            reading aid.
          </p>
        </div>
      </div>

      <div className="checkout-review-rx__grid">
        <div className="checkout-review-rx__panel">
          <div className="checkout-review-rx__panel-head">
            <strong>Original photo</strong>

            <span className="checkout-status-pill checkout-status-pill--success">
              <i
                className="bi bi-check-circle-fill"
                aria-hidden="true"
              ></i>
              Uploaded
            </span>
          </div>

          <div className="checkout-review-rx__image">
            {preview ? (
              <img
                src={preview}
                alt="Prescription photo that will be submitted"
              />
            ) : null}
          </div>
        </div>

        <div className="checkout-review-rx__panel">
          <div className="checkout-review-rx__panel-head">
            <strong>OCR reading</strong>

            {hasText ? (
              <span className="checkout-status-pill checkout-status-pill--success">
                <i
                  className="bi bi-check-circle-fill"
                  aria-hidden="true"
                ></i>
                Text detected
              </span>
            ) : (
              <span className="checkout-status-pill checkout-status-pill--warning">
                <i
                  className="bi bi-exclamation-circle-fill"
                  aria-hidden="true"
                ></i>
                Manual check
              </span>
            )}
          </div>

          {confidence !== null && hasText ? (
            <div className="checkout-review-confidence">
              <div>
                <span>OCR confidence</span>
                <strong>{confidence}%</strong>
              </div>

              <div
                className="checkout-review-confidence__bar"
                aria-hidden="true"
              >
                <span
                  style={{
                    width: `${confidence}%`,
                  }}
                ></span>
              </div>
            </div>
          ) : null}

          {hasText ? (
            <>
              <span className="checkout-review-rx__label">
                Text read from the photo
              </span>

              <pre className="checkout-review-rx__text">
                {ocr.text}
              </pre>

              <div className="checkout-review-info">
                <i
                  className="bi bi-info-circle-fill"
                  aria-hidden="true"
                ></i>

                <p>
                  OCR text can contain spelling or
                  reading errors. Branch staff will
                  still verify the prescription photo.
                </p>
              </div>
            </>
          ) : (
            <div className="checkout-review-rx__empty">
              <i
                className="bi bi-eye-slash"
                aria-hidden="true"
              ></i>

              <strong>
                No automatic reading is available
              </strong>

              <p>
                The reservation can still continue.
                Branch staff will check the original
                prescription.
              </p>
            </div>
          )}
        </div>
      </div>
    </section>
  )
}

export default function Cart() {
  usePageTitle('Cart')

  const cart = useCart()

  const [step, setStep] =
    useState('cart')

  const [notes, setNotes] =
    useState('')

  const [notesError, setNotesError] =
    useState('')

  const [file, setFile] =
    useState(null)

  const [fileError, setFileError] =
    useState('')

  const [busy, setBusy] =
    useState(false)

  const [error, setError] =
    useState(null)

  const [short, setShort] =
    useState([])

  const [created, setCreated] =
    useState(null)

  const [confirmClear, setConfirmClear] =
    useState(false)

  const clearButton = useRef(null)
  const errorBox = useRef(null)
  const reviewHeading = useRef(null)
  const checkoutHeading = useRef(null)

  const submitting = useRef(false)

  const keyRef = useRef(null)
  const signatureRef = useRef(null)

  const signature =
    JSON.stringify({
      branch: cart.branchId,

      items: cart.items.map(
        (item) => [
          item.medicineId,
          item.quantity,
        ],
      ),

      notes: notes.trim(),

      file: file
        ? [
            file.name,
            file.size,
            file.lastModified,
          ]
        : null,
    })

  function currentKey() {
    if (
      keyRef.current === null ||
      signatureRef.current !== signature
    ) {
      keyRef.current =
        crypto.randomUUID()

      signatureRef.current =
        signature
    }

    return keyRef.current
  }

  const preview =
    useMemo(
      () =>
        file
          ? URL.createObjectURL(file)
          : null,
      [file],
    )

  useEffect(
    () => () => {
      if (preview) {
        URL.revokeObjectURL(preview)
      }
    },
    [preview],
  )

  const [ocr, setOcr] =
    useState({
      phase: 'idle',
    })

  const ocrAbort =
    useRef(null)

  useEffect(
    () => () =>
      ocrAbort.current?.abort(),
    [],
  )

  function startOcrPreview(chosen) {
    if (!chosen) return

    ocrAbort.current?.abort()

    const controller =
      new AbortController()

    ocrAbort.current =
      controller

    setOcr({
      phase: 'loading',
    })

    ocrPrescription(
      chosen,
      {
        signal: controller.signal,
      },
    )
      .then(({ data }) => {
        setOcr({
          phase: 'done',
          text: data.ocr_text ?? '',
          status:
            data.ocr_status ??
            'unavailable',
          confidence:
            data.ocr_confidence ??
            null,
        })
      })
      .catch((caught) => {
        if (
          caught?.name !==
          'CanceledError'
        ) {
          setOcr({
            phase: 'error',
          })
        }
      })
  }

  if (
    step === 'done' &&
    created
  ) {
    return (
      <ClaimTicket
        reservation={
          created.reservation
        }
        branchName={
          created.branchName
        }
      />
    )
  }

  const needsPhoto =
    cart.needsPrescription

  const shortById =
    new Map(
      short.map((item) => [
        item.medicineId,
        item,
      ]),
    )

  function openCheckout() {
    keyRef.current =
      crypto.randomUUID()

    signatureRef.current =
      signature

    setError(null)

    setStep('checkout')

    requestAnimationFrame(
      () =>
        checkoutHeading.current?.focus(),
    )
  }

  function choosePhoto(event) {
    const chosen =
      event.target.files?.[0] ??
      null

    event.target.value = ''

    if (!chosen) return

    const problem =
      photoProblem(chosen)

    setFileError(problem)

    if (problem) {
      setFile(null)

      setOcr({
        phase: 'idle',
      })

      return
    }

    setFile(chosen)

    startOcrPreview(chosen)
  }

  function removePhoto() {
    ocrAbort.current?.abort()

    setFile(null)

    setFileError('')

    setOcr({
      phase: 'idle',
    })
  }

  function proceedToReview(event) {
    event.preventDefault()

    if (busy) return

    if (
      notes.length >
      NOTES_MAX
    ) {
      setNotesError(
        `Please shorten your notes to ${NOTES_MAX} characters or fewer.`,
      )

      return
    }

    setNotesError('')

    if (
      needsPhoto &&
      !file
    ) {
      setFileError(
        'Please add a photo of the prescription.',
      )

      return
    }

    setError(null)

    setStep('review')

    requestAnimationFrame(
      () =>
        reviewHeading.current?.focus(),
    )
  }

  async function confirmReservation() {
    if (
      busy ||
      submitting.current
    ) {
      return
    }

    submitting.current = true

    setBusy(true)

    setError(null)
    setShort([])

    try {
      const { data } =
        await createReservation(
          {
            branchId:
              cart.branchId,

            items:
              cart.items.map(
                (item) => ({
                  medicineId:
                    item.medicineId,

                  quantity:
                    item.quantity,
                }),
              ),

            notes,

            prescription:
              file,
          },
          currentKey(),
        )

      setCreated({
        reservation: data,
        branchName:
          cart.branchName,
      })

      cart.clear()

      setStep('done')
    } catch (caught) {
      setError(caught)

      if (
        caught.code ===
        'STOCK_INSUFFICIENT'
      ) {
        setShort(
          shortages(caught),
        )
      }

      if (
        caught.code ===
          'PRESCRIPTION_REQUIRED' &&
        Array.isArray(
          caught.details
            ?.medicine_ids,
        )
      ) {
        cart.markPrescription(
          caught.details.medicine_ids.map(
            Number,
          ),
        )

        if (!file) {
          setStep('checkout')
        }
      }

      requestAnimationFrame(
        () =>
          errorBox.current?.focus(),
      )
    } finally {
      setBusy(false)
      submitting.current = false
    }
  }

  if (cart.count === 0) {
    return (
      <div className="legacy-cart final-cart-page">
        <div className="cart-wrapper">
          <PageHeader
            title="Reservation Cart"
            subtitle="Your cart is empty"
          />

          <EmptyState
            icon="bi bi-cart"
            title="Your cart is empty"
            actions={
              <Button
                to="/medicines"
                variant="primary"
              >
                <i
                  className="bi bi-capsule-pill"
                  aria-hidden="true"
                ></i>

                Browse medicines
              </Button>
            }
          >
            Choose a medicine and branch, then
            press Reserve to add it here.
          </EmptyState>
        </div>
      </div>
    )
  }

  return (
    <div className="legacy-cart final-cart-page">
      <div className="cart-wrapper">
        <PageHeader
          title="Reservation Cart"
          subtitle={
            <span>
              {cart.count}{' '}
              {cart.count === 1
                ? 'medicine'
                : 'medicines'}
              {' · '}
              Pickup at{' '}
              <strong>
                {cart.branchName}
              </strong>
            </span>
          }
          actions={
            step === 'cart' ? (
              <>
                <Button to="/medicines">
                  <i
                    className="bi bi-plus-circle"
                    aria-hidden="true"
                  ></i>
                  Add More
                </Button>

                <Button
                  variant="danger"
                  ref={clearButton}
                  onClick={() =>
                    setConfirmClear(true)
                  }
                  disabled={busy}
                >
                  <i
                    className="bi bi-trash3"
                    aria-hidden="true"
                  ></i>
                  Clear Cart
                </Button>
              </>
            ) : null
          }
        />

        <ProgressSteps step={step} />

        {cart.notice ? (
          <p
            className="app-notice app-notice--info"
            role="status"
          >
            {cart.notice}
          </p>
        ) : null}

        <div
          ref={errorBox}
          tabIndex={-1}
          className="checkout-page-error"
        >
          {error ? (
            <ErrorMessage
              error={error}
              message={
                error.code ===
                'STOCK_INSUFFICIENT'
                  ? 'Some quantities are no longer available. Review the stock and try again.'
                  : friendlyMessage(error)
              }
            >
              {short.length > 0 ? (
                <>
                  <ul className="short-list">
                    {short.map((item) => (
                      <li key={item.medicineId}>
                        {cart.items.find(
                          (current) =>
                            current.medicineId ===
                            item.medicineId,
                        )?.name ??
                          `Medicine ${item.medicineId}`}
                        : requested {item.requested},{' '}
                        {item.available} available.
                      </li>
                    ))}
                  </ul>

                  <button
                    type="button"
                    className="btn-outline btn-outline-blue"
                    onClick={() => {
                      cart.applyAvailability(short)
                      setShort([])
                      setError(null)
                      setStep('cart')
                    }}
                  >
                    Use available amounts
                  </button>
                </>
              ) : null}
            </ErrorMessage>
          ) : (
            <ErrorMessage />
          )}
        </div>

        {step === 'cart' ? (
          <>
            <div className="final-cart-branch">
              <span className="final-cart-branch__icon">
                <i
                  className="bi bi-shop"
                  aria-hidden="true"
                ></i>
              </span>

              <div>
                <span>Pickup branch</span>
                <strong>{cart.branchName}</strong>
              </div>

              <p>
                All medicines in this reservation
                will be collected from the same
                pharmacy branch.
              </p>
            </div>

            <div className="final-cart-layout">
              <section
                className="final-cart-items"
                aria-labelledby="cart-items-heading"
              >
                <div className="final-cart-section-heading">
                  <div>
                    <span>Your medicines</span>
                    <h2 id="cart-items-heading">
                      Review quantities
                    </h2>
                  </div>

                  <strong>
                    {cart.count}{' '}
                    {cart.count === 1
                      ? 'item'
                      : 'items'}
                  </strong>
                </div>

                <ul>
                  {cart.items.map((item) => (
                    <CartItem
                      key={item.medicineId}
                      item={item}
                      busy={busy}
                      problem={shortById.get(
                        item.medicineId,
                      )}
                      onQuantityChange={(quantity) =>
                        cart.setQuantity(
                          item.medicineId,
                          quantity,
                        )
                      }
                      onRemove={() =>
                        cart.removeLine(
                          item.medicineId,
                        )
                      }
                    />
                  ))}
                </ul>
              </section>

              <div className="final-cart-side">
                <ReservationRecap cart={cart} />

                <button
                  type="button"
                  className="final-cart-primary"
                  onClick={openCheckout}
                >
                  Continue to checkout

                  <i
                    className="bi bi-arrow-right"
                    aria-hidden="true"
                  ></i>
                </button>
              </div>
            </div>
          </>
        ) : null}

        {step === 'checkout' ? (
          <div className="checkout-workspace">
            <main className="checkout-main-card">
              <div className="checkout-page-heading">
                <button
                  type="button"
                  className="checkout-back-link"
                  onClick={() =>
                    setStep('cart')
                  }
                >
                  <i
                    className="bi bi-arrow-left"
                    aria-hidden="true"
                  ></i>

                  Back to cart
                </button>

                <span>Step 2 of 3</span>

                <h1
                  ref={checkoutHeading}
                  tabIndex={-1}
                >
                  Reservation details
                </h1>

                <p>
                  Add any instructions for the
                  branch and upload a prescription
                  if one is required.
                </p>
              </div>

              <form
                onSubmit={proceedToReview}
                noValidate
              >
                <section className="checkout-form-section">
                  <div className="checkout-section-heading">
                    <span className="checkout-section-heading__icon">
                      <i
                        className="bi bi-chat-left-text"
                        aria-hidden="true"
                      ></i>
                    </span>

                    <div>
                      <span>Optional</span>
                      <h2>Additional notes</h2>
                      <p>
                        Add instructions that may
                        help the pharmacy branch
                        prepare your reservation.
                      </p>
                    </div>
                  </div>

                  <div className="checkout-notes-field">
                    <textarea
                      id="cart-notes"
                      rows={4}
                      maxLength={NOTES_MAX}
                      value={notes}
                      onChange={(event) => {
                        setNotes(
                          event.target.value,
                        )

                        if (notesError) {
                          setNotesError('')
                        }
                      }}
                      placeholder="Example: Please prepare this for pickup after 5 PM."
                      aria-describedby={
                        notesError
                          ? 'cart-notes-count cart-notes-error'
                          : 'cart-notes-count'
                      }
                      aria-invalid={
                        notesError
                          ? 'true'
                          : undefined
                      }
                    />

                    <div className="checkout-notes-meta">
                      <span>
                        Visible to branch staff
                      </span>

                      <strong id="cart-notes-count">
                        {notes.length}/{NOTES_MAX}
                      </strong>
                    </div>

                    {notesError ? (
                      <p
                        id="cart-notes-error"
                        className="field-error"
                        role="alert"
                      >
                        {notesError}
                      </p>
                    ) : null}
                  </div>
                </section>

                {needsPhoto ? (
                  <PrescriptionUploader
                    preview={preview}
                    fileError={fileError}
                    busy={busy}
                    ocr={ocr}
                    onChoose={choosePhoto}
                    onRemove={removePhoto}
                    onRetry={() =>
                      startOcrPreview(file)
                    }
                  />
                ) : (
                  <section className="checkout-no-rx">
                    <i
                      className="bi bi-check-circle-fill"
                      aria-hidden="true"
                    ></i>

                    <div>
                      <strong>
                        No prescription upload
                        required
                      </strong>

                      <p>
                        None of the medicines in
                        this reservation currently
                        require a prescription.
                      </p>
                    </div>
                  </section>
                )}

                <div className="checkout-main-actions">
                  <button
                    type="button"
                    className="checkout-secondary-button"
                    onClick={() =>
                      setStep('cart')
                    }
                  >
                    <i
                      className="bi bi-arrow-left"
                      aria-hidden="true"
                    ></i>
                    Back
                  </button>

                  <button
                    type="submit"
                    className="checkout-primary-button"
                  >
                    Review reservation
                    <i
                      className="bi bi-arrow-right"
                      aria-hidden="true"
                    ></i>
                  </button>
                </div>
              </form>
            </main>

            <ReservationRecap cart={cart} />
          </div>
        ) : null}

        {step === 'review' ? (
          <div className="checkout-workspace">
            <main className="checkout-main-card">
              <div className="checkout-page-heading">
                <button
                  type="button"
                  className="checkout-back-link"
                  onClick={() =>
                    setStep('checkout')
                  }
                  disabled={busy}
                >
                  <i
                    className="bi bi-arrow-left"
                    aria-hidden="true"
                  ></i>

                  Edit reservation
                </button>

                <span>Step 3 of 3</span>

                <h1
                  ref={reviewHeading}
                  tabIndex={-1}
                >
                  Review your reservation
                </h1>

                <p>
                  Confirm the branch, quantities,
                  notes, and prescription before
                  sending.
                </p>
              </div>

              <section className="checkout-review-items">
                <div className="checkout-section-heading">
                  <span className="checkout-section-heading__icon">
                    <i
                      className="bi bi-capsule-pill"
                      aria-hidden="true"
                    ></i>
                  </span>

                  <div>
                    <span>Medicines</span>
                    <h2>Items to reserve</h2>
                  </div>
                </div>

                <div className="checkout-review-items__list">
                  {cart.items.map((item) => (
                    <div
                      key={item.medicineId}
                      className="checkout-review-item"
                    >
                      <span className="checkout-review-item__icon">
                        <i
                          className="bi bi-capsule"
                          aria-hidden="true"
                        ></i>
                      </span>

                      <div>
                        <strong>{item.name}</strong>

                        {item.detail ? (
                          <span>{item.detail}</span>
                        ) : null}
                      </div>

                      <span className="checkout-review-item__qty">
                        {item.quantity}{' '}
                        {item.quantity === 1
                          ? 'piece'
                          : 'pieces'}
                      </span>
                    </div>
                  ))}
                </div>
              </section>

              {notes.trim() ? (
                <section className="checkout-review-notes">
                  <div className="checkout-section-heading">
                    <span className="checkout-section-heading__icon">
                      <i
                        className="bi bi-chat-left-text"
                        aria-hidden="true"
                      ></i>
                    </span>

                    <div>
                      <span>Instructions</span>
                      <h2>Your notes</h2>
                    </div>
                  </div>

                  <p>{notes}</p>
                </section>
              ) : null}

              {needsPhoto ? (
                <ReviewPrescription
                  preview={preview}
                  ocr={ocr}
                />
              ) : null}

              <div className="checkout-review-reminder">
                <i
                  className="bi bi-info-circle-fill"
                  aria-hidden="true"
                ></i>

                <p>
                  Your reservation will be sent to{' '}
                  <strong>{cart.branchName}</strong>.
                  The branch will review it before
                  the medicine is released.
                </p>
              </div>

              <div className="checkout-main-actions">
                <button
                  type="button"
                  className="checkout-secondary-button"
                  onClick={() =>
                    setStep('checkout')
                  }
                  disabled={busy}
                >
                  <i
                    className="bi bi-pencil"
                    aria-hidden="true"
                  ></i>
                  Edit details
                </button>

                <button
                  type="button"
                  className="checkout-primary-button"
                  onClick={confirmReservation}
                  disabled={busy}
                >
                  <i
                    className="bi bi-bag-check"
                    aria-hidden="true"
                  ></i>

                  {busy
                    ? 'Sending...'
                    : 'Confirm & Reserve'}
                </button>
              </div>
            </main>

            <ReservationRecap cart={cart} />
          </div>
        ) : null}
      </div>

      <ConfirmDialog
        open={confirmClear}
        title="Clear your cart?"
        confirmLabel="Clear cart"
        cancelLabel="Keep my cart"
        danger
        returnFocusRef={clearButton}
        onCancel={() =>
          setConfirmClear(false)
        }
        onConfirm={() => {
          setConfirmClear(false)
          cart.clear()
        }}
      >
        <p>
          All medicines will be removed from your
          cart. Nothing has been reserved yet.
        </p>
      </ConfirmDialog>
    </div>
  )
}