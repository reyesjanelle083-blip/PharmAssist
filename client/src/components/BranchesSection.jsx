import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

import iconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png'
import iconUrl from 'leaflet/dist/images/marker-icon.png'
import shadowUrl from 'leaflet/dist/images/marker-shadow.png'

import {
  useEffect,
  useRef,
  useState,
} from 'react'

import {
  Link,
} from 'react-router-dom'

import {
  MapContainer,
  Marker,
  Popup,
  TileLayer,
  useMap,
} from 'react-leaflet'

import {
  getBranches,
} from '../api/index.js'

import ErrorMessage from './ErrorMessage.jsx'

L.Icon.Default.mergeOptions({
  iconRetinaUrl,
  iconUrl,
  shadowUrl,
})

const DEFAULT_CENTER = [
  14.6,
  121.0,
]

function FitBranches({
  branches,
}) {
  const map = useMap()

  useEffect(() => {
    if (branches.length === 0) return

    map.invalidateSize()

    map.fitBounds(
      L.latLngBounds(
        branches.map((branch) => [
          branch.latitude,
          branch.longitude,
        ]),
      ),
      {
        padding: [50, 50],
        maxZoom: 15,
      },
    )
  }, [
    map,
    branches,
  ])

  return null
}

function osmLink(branch) {
  return (
    'https://www.openstreetmap.org/' +
    `?mlat=${branch.latitude}` +
    `&mlon=${branch.longitude}` +
    `#map=17/${branch.latitude}/${branch.longitude}`
  )
}

export default function BranchesSection() {
  const [state, setState] = useState({
    phase: 'loading',
    branches: [],
  })

  const [selected, setSelected] =
    useState(null)

  const map = useRef(null)

  const mapPanel = useRef(null)

  const markers = useRef({})

  useEffect(() => {
    const controller = new AbortController()

    getBranches({
      signal: controller.signal,
    })
      .then(({ data }) => {
        const branches = (
          data.items ?? []
        ).filter(
          (branch) =>
            Number.isFinite(
              branch.latitude,
            ) &&
            Number.isFinite(
              branch.longitude,
            ),
        )

        setState({
          phase: 'done',
          branches,
        })
      })
      .catch((error) => {
        if (
          error?.name !==
          'CanceledError'
        ) {
          setState({
            phase: 'error',
            error,
            branches: [],
          })
        }
      })

    return () => controller.abort()
  }, [])

  function show(branch) {
    setSelected(branch.id)

    mapPanel.current?.scrollIntoView({
      behavior: window.matchMedia(
        '(prefers-reduced-motion: reduce)',
      ).matches
        ? 'auto'
        : 'smooth',
      block: 'center',
    })

    window.setTimeout(() => {
      map.current?.invalidateSize()

      map.current?.flyTo(
        [
          branch.latitude,
          branch.longitude,
        ],
        Math.max(
          map.current?.getZoom?.() ?? 12,
          15,
        ),
        {
          duration: 0.6,
        },
      )

      const marker =
        markers.current[branch.id]

      marker?.openPopup()

      marker
        ?.getElement()
        ?.focus({
          preventScroll: true,
        })
    }, 250)
  }

  const {
    phase,
    branches,
    error,
  } = state

  return (
    <section
      id="branches"
      className="home-section-block home-branches"
      aria-labelledby="branches-heading"
      tabIndex={-1}
    >
      <div className="home-section-heading">
        <span>Locations</span>

        <h2 id="branches-heading">
          Our Branches
        </h2>

        <p>
          Check branch information, locate it on
          the map, or open the exact location in
          OpenStreetMap.
        </p>
      </div>

      <div aria-live="polite">
        {phase === 'loading' ? (
          <div className="home-overview-loading">
            <i
              className="bi bi-arrow-repeat"
              aria-hidden="true"
            ></i>
            Loading branches...
          </div>
        ) : null}

        {phase === 'error' ? (
          <ErrorMessage error={error} />
        ) : (
          <ErrorMessage />
        )}

        {phase === 'done' &&
        branches.length === 0 ? (
          <div className="home-overview-message">
            No branches are available yet.
          </div>
        ) : null}
      </div>

      {phase === 'done' &&
      branches.length > 0 ? (
        <>
          <div
            ref={mapPanel}
            className="home-branch-map-wrap"
          >
            <div className="home-branch-map-head">
              <div>
                <span>Interactive map</span>

                <strong>
                  Select a branch below
                </strong>
              </div>

              <div className="home-map-hint">
                <i
                  className="bi bi-geo-alt-fill"
                  aria-hidden="true"
                ></i>
                {selected
                  ? 'Selected branch shown on map'
                  : `${branches.length} ${
                      branches.length === 1
                        ? 'branch'
                        : 'branches'
                    }`}
              </div>
            </div>

            <div
              className="home-branch-map"
              role="region"
              aria-label="Interactive map of PharmAssist branches"
            >
              <MapContainer
                ref={map}
                center={DEFAULT_CENTER}
                zoom={11}
                scrollWheelZoom={false}
                style={{
                  height: '100%',
                  width: '100%',
                }}
              >
                <FitBranches
                  branches={branches}
                />

                <TileLayer
                  attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                  url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
                  maxZoom={19}
                />

                {branches.map(
                  (branch) => (
                    <Marker
                      key={branch.id}
                      position={[
                        branch.latitude,
                        branch.longitude,
                      ]}
                      title={branch.name}
                      alt={`${branch.name} branch`}
                      ref={(marker) => {
                        if (marker) {
                          markers.current[
                            branch.id
                          ] = marker
                        }
                      }}
                      eventHandlers={{
                        click: () =>
                          setSelected(
                            branch.id,
                          ),

                        popupopen: () =>
                          setSelected(
                            branch.id,
                          ),
                      }}
                    >
                      <Popup>
                        <div className="home-map-popup">
                          <strong>
                            {branch.name}
                          </strong>

                          <span>
                            {branch.address}
                          </span>
                        </div>
                      </Popup>
                    </Marker>
                  ),
                )}
              </MapContainer>
            </div>

            <p className="home-map-note">
              Map locations are based on the branch
              coordinates stored in PharmAssist.
            </p>
          </div>

          <div
            className="home-branch-grid"
            role="list"
          >
            {branches.map((branch) => (
              <article
                key={branch.id}
                role="listitem"
                className={
                  selected === branch.id
                    ? 'home-branch-card home-branch-card--selected'
                    : 'home-branch-card'
                }
              >
                <div className="home-branch-card__top">
                  <span className="home-branch-pill">
                    <i
                      className="bi bi-shop"
                      aria-hidden="true"
                    ></i>
                    Branch
                  </span>

                  {selected ===
                  branch.id ? (
                    <span className="home-branch-selected">
                      On map
                    </span>
                  ) : null}
                </div>

                <h3>
                  {branch.name}
                </h3>

                <div className="home-branch-details">
                  <div>
                    <i
                      className="bi bi-geo-alt-fill"
                      aria-hidden="true"
                    ></i>

                    <span>
                      {branch.address}
                    </span>
                  </div>

                  {branch.opening_hours ? (
                    <div>
                      <i
                        className="bi bi-clock-fill"
                        aria-hidden="true"
                      ></i>

                      <span>
                        {branch.opening_hours}
                      </span>
                    </div>
                  ) : null}

                  {branch.contact_no ? (
                    <div>
                      <i
                        className="bi bi-telephone-fill"
                        aria-hidden="true"
                      ></i>

                      <span>
                        {branch.contact_no}
                      </span>
                    </div>
                  ) : null}
                </div>

                <div className="home-branch-actions">
                  <button
                    type="button"
                    className="home-branch-action home-branch-action--primary"
                    onClick={() =>
                      show(branch)
                    }
                    aria-pressed={
                      selected ===
                      branch.id
                    }
                  >
                    <i
                      className="bi bi-map"
                      aria-hidden="true"
                    ></i>
                    Show on map
                  </button>

                  <a
                    className="home-branch-action"
                    href={osmLink(branch)}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <i
                      className="bi bi-box-arrow-up-right"
                      aria-hidden="true"
                    ></i>
                    Open in OpenStreetMap
                  </a>

                  <Link
                    className="home-branch-action"
                    to="/medicines"
                  >
                    <i
                      className="bi bi-capsule-pill"
                      aria-hidden="true"
                    ></i>
                    Browse medicines
                  </Link>
                </div>
              </article>
            ))}
          </div>
        </>
      ) : null}
    </section>
  )
}