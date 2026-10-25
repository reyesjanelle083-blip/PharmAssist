import {
  useEffect,
  useState,
} from 'react'
import { getMedicines } from '../api/index.js'
import { useSearch } from '../context/useSearch.js'
import {
  displayName,
} from '../utils/catalog.js'
import Button from './ui/Button.jsx'

const DISPLAY_COUNT = 6

export default function MedicineOverview() {
  const { searchForMedicine } = useSearch()

  const [state, setState] = useState({
    phase: 'loading',
    items: [],
  })

  useEffect(() => {
    const controller = new AbortController()

    getMedicines(
      {
        include: 'availability',
        page: 1,
        per_page: DISPLAY_COUNT,
      },
      {
        signal: controller.signal,
      },
    )
      .then(({ data }) => {
        setState({
          phase: 'done',
          items: data.items ?? [],
        })
      })
      .catch((error) => {
        if (error?.name !== 'CanceledError') {
          setState({
            phase: 'error',
            items: [],
          })
        }
      })

    return () => controller.abort()
  }, [])

  return (
    <section
      className="home-section-block home-medicines"
      aria-labelledby="home-medicines-heading"
    >
      <div className="home-section-heading home-section-heading--split">
        <div>
          <span>Medicine Catalog</span>

          <h2 id="home-medicines-heading">
            Browse available medicines
          </h2>

          <p>
            Explore some of the medicines currently
            listed in PharmAssist, then check which
            branches have them available.
          </p>
        </div>

        <Button
          to="/medicines"
          variant="outline"
          className="home-section-view-all"
        >
          View all medicines
          <i
            className="bi bi-arrow-right"
            aria-hidden="true"
          ></i>
        </Button>
      </div>

      {state.phase === 'loading' ? (
        <div
          className="home-overview-loading"
          role="status"
        >
          <i
            className="bi bi-arrow-repeat"
            aria-hidden="true"
          ></i>
          Loading medicines...
        </div>
      ) : null}

      {state.phase === 'error' ? (
        <div className="home-overview-message">
          <i
            className="bi bi-exclamation-circle"
            aria-hidden="true"
          ></i>
          Medicines could not be loaded right now.
          You can still use the search above or open
          the full catalog.
        </div>
      ) : null}

      {state.phase === 'done' &&
      state.items.length > 0 ? (
        <div
          className="home-medicine-grid"
          role="list"
        >
          {state.items.map((medicine) => {
            const availability =
              medicine.availability

            const branchCount =
              availability?.branch_count ?? 0

            const totalAvailable =
              availability?.total_available ?? 0

            return (
              <article
                className="home-medicine-card"
                role="listitem"
                key={medicine.id}
              >
                <div className="home-medicine-card__visual">
                  {medicine.image_url ? (
                    <img
                      src={medicine.image_url}
                      alt=""
                    />
                  ) : (
                    <i
                      className="bi bi-capsule-pill"
                      aria-hidden="true"
                    ></i>
                  )}
                </div>

                <div className="home-medicine-card__body">
                  <div className="home-medicine-card__badges">
                    <span
                      className={
                        medicine.prescription_required
                          ? 'home-rx-pill home-rx-pill--required'
                          : 'home-rx-pill home-rx-pill--otc'
                      }
                    >
                      {medicine.prescription_required
                        ? 'Rx required'
                        : 'No prescription'}
                    </span>
                  </div>

                  <h3>
                    {displayName(medicine)}
                  </h3>

                  <p className="home-medicine-card__meta">
                    {[
                      medicine.strength,
                      medicine.dosage_form,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>

                  <p className="home-medicine-card__category">
                    {medicine.category}
                  </p>

                  <div className="home-medicine-card__stock">
                    <i
                      className={
                        branchCount > 0
                          ? 'bi bi-check-circle-fill'
                          : 'bi bi-x-circle-fill'
                      }
                      aria-hidden="true"
                    ></i>

                    <span>
                      {branchCount > 0
                        ? `Available at ${branchCount} ${
                            branchCount === 1
                              ? 'branch'
                              : 'branches'
                          } · ${totalAvailable} in stock`
                        : 'Currently out of stock'}
                    </span>
                  </div>

                  <div className="home-medicine-card__actions">
                    <button
                      type="button"
                      onClick={() =>
                        searchForMedicine(
                          medicine.id,
                          displayName(medicine),
                        )
                      }
                    >
                      <i
                        className="bi bi-geo-alt"
                        aria-hidden="true"
                      ></i>
                      Check availability
                    </button>

                    <Button
                      to={`/medicines/${medicine.id}`}
                      variant="outline"
                    >
                      Details
                    </Button>
                  </div>
                </div>
              </article>
            )
          })}
        </div>
      ) : null}
    </section>
  )
}