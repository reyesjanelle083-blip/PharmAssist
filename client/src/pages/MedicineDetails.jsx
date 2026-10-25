import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { getMedicine, getMedicineCounseling } from '../api/index.js'
import Button from '../components/ui/Button.jsx'
import EmptyState from '../components/ui/EmptyState.jsx'
import ErrorState from '../components/ui/ErrorState.jsx'
import LoadingState from '../components/ui/LoadingState.jsx'
import PageHeader from '../components/ui/PageHeader.jsx'
import { useSearch } from '../context/useSearch.js'
import { usePageTitle } from '../hooks/usePageTitle.js'
import NotFound from './NotFound.jsx'

/** "Amoxicillin (Amoxil)", or just the generic name when there is no brand. Matches the name the availability search shows. */
function displayName(medicine) {
  return medicine.brand_name ? `${medicine.generic_name} (${medicine.brand_name})` : medicine.generic_name
}

export default function MedicineDetails() {
  const { id } = useParams()
  return <Details key={id} id={id} />
}

function Details({ id }) {
  const { searchForMedicine } = useSearch()
  const [state, setState] = useState({ phase: 'loading' })
  const [counseling, setCounseling] = useState({ phase: 'loading' })
  const heading = useRef(null)
  usePageTitle(state.phase === 'done' ? displayName(state.medicine) : 'Medicine details')

  // key={id} on the outer component remounts Details for a new id, so the initial `loading` state above is always right
  // for a fresh medicine; a retry after an error does not flash back to loading, the same as the reservation detail page.
  const loadMedicine = useCallback(
    (signal) =>
      getMedicine(id, { signal })
        .then(({ data }) => setState({ phase: 'done', medicine: data }))
        .catch((error) => {
          if (error?.name !== 'CanceledError') setState({ phase: 'error', error })
        }),
    [id],
  )

  // Loaded separately from the medicine itself: counseling text being unavailable, or empty, never blocks the rest of the page.
  const loadCounseling = useCallback(
    (signal) =>
      getMedicineCounseling(id, { signal })
        .then(({ data }) => setCounseling({ phase: 'done', counseling: data }))
        .catch((error) => {
          if (error?.name !== 'CanceledError') setCounseling({ phase: 'error', error })
        }),
    [id],
  )

  useEffect(() => {
    const controller = new AbortController()
    loadMedicine(controller.signal)
    return () => controller.abort()
  }, [loadMedicine])

  useEffect(() => {
    const controller = new AbortController()
    loadCounseling(controller.signal)
    return () => controller.abort()
  }, [loadCounseling])

  if (state.phase === 'loading') return <LoadingState>Loading the medicine...</LoadingState>
  if (state.phase === 'error') {
    if (state.error.status === 404) return <NotFound />
    return (
      <div className="branch-container">
        <ErrorState error={state.error} onRetry={() => loadMedicine()} />
      </div>
    )
  }

  const medicine = state.medicine

  return (
    <div className="branch-container medicine-details">
      <PageHeader
        headingRef={heading}
        title={displayName(medicine)}
        subtitle={`${medicine.category} · ${medicine.strength} · ${medicine.dosage_form}`}
        actions={
          <Button variant="primary" onClick={() => searchForMedicine(medicine.id, displayName(medicine))}>
            <i className="bi bi-geo-alt" aria-hidden="true"></i> Check availability near you
          </Button>
        }
      />
      <p className="field-hint" style={{ marginTop: -12, marginBottom: 16 }}>
        Price and stock vary by branch; &quot;Check availability near you&quot; shows both.
      </p>

      <div className="medicine-details-grid">
        <section className="card medicine-details-card" aria-labelledby="medicine-info-heading">
          <div className="card-image">
            {medicine.image_url ? <img src={medicine.image_url} alt="" /> : <i className="bi bi-capsule" aria-hidden="true"></i>}
          </div>
          <div className="card-body">
            <h2 id="medicine-info-heading" className="visually-hidden">
              Medicine information
            </h2>
            <div className="prescription-status">{medicine.prescription_required ? 'Prescription required' : 'No prescription needed'}</div>
            <dl className="medicine-facts">
              <div>
                <dt>Generic name</dt>
                <dd>{medicine.generic_name}</dd>
              </div>
              {medicine.brand_name ? (
                <div>
                  <dt>Brand name</dt>
                  <dd>{medicine.brand_name}</dd>
                </div>
              ) : null}
              <div>
                <dt>Category</dt>
                <dd>{medicine.category}</dd>
              </div>
              <div>
                <dt>Strength</dt>
                <dd>{medicine.strength}</dd>
              </div>
              <div>
                <dt>Form</dt>
                <dd>{medicine.dosage_form}</dd>
              </div>
            </dl>
          </div>
        </section>

        <section className="pa-card" aria-labelledby="counseling-heading">
          <div className="pa-card__header">
            <h2 id="counseling-heading">Counseling and usage information</h2>
            <p>General, plain-language reminders. Not medical advice; ask the pharmacist for guidance.</p>
          </div>
          <div aria-live="polite">
            {counseling.phase === 'loading' ? <LoadingState>Loading counseling information...</LoadingState> : null}

            {counseling.phase === 'error' ? <ErrorState error={counseling.error} onRetry={() => loadCounseling()} /> : null}

            {counseling.phase === 'done' && !counseling.counseling.summary ? (
              <EmptyState icon="bi bi-info-circle" title="No counseling information yet">
                Ask the pharmacist at the branch for guidance on how to use this medicine.
              </EmptyState>
            ) : null}

            {counseling.phase === 'done' && counseling.counseling.summary ? <p className="pa-card__text">{counseling.counseling.summary}</p> : null}
          </div>
        </section>
      </div>
    </div>
  )
}
