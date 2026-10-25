import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import iconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png'
import iconUrl from 'leaflet/dist/images/marker-icon.png'
import shadowUrl from 'leaflet/dist/images/marker-shadow.png'
import { useEffect, useRef, useState } from 'react'
import { MapContainer, Marker, Popup, TileLayer, useMap } from 'react-leaflet'
import { getBranches } from '../api/index.js'
import ErrorMessage from './ErrorMessage.jsx'

// Leaflet looks for its marker pictures next to its CSS, which a bundler moves. Point it at the imported files instead.
L.Icon.Default.mergeOptions({ iconRetinaUrl, iconUrl, shadowUrl })

const DEFAULT_CENTER = [14.6, 121.0]

/** Fits the map to all the branches once the map exists. */
function FitBranches({ branches }) {
  const map = useMap()
  useEffect(() => {
    map.invalidateSize()
    map.fitBounds(L.latLngBounds(branches.map((b) => [b.latitude, b.longitude])), { padding: [50, 50], maxZoom: 15 })
  }, [map, branches])
  return null
}

function osmLink(branch) {
  return `https://www.openstreetmap.org/?mlat=${branch.latitude}&mlon=${branch.longitude}#map=17/${branch.latitude}/${branch.longitude}`
}

/**
 * "Our Branches": one map with a marker per branch and one card per branch (the old branch cards: name, address, actions).
 * The card list is the text version of the map. Selecting a card ("Show on map") moves the map and focuses that marker, and
 * markers can be reached with the keyboard (Tab, then Enter to open the popup).
 * The map pictures come from OpenStreetMap: that is the one request this page makes to another server.
 */
export default function BranchesSection() {
  const [state, setState] = useState({ phase: 'loading', branches: [] })
  const [selected, setSelected] = useState(null)
  const map = useRef(null)
  const markers = useRef({})

  useEffect(() => {
    const controller = new AbortController()
    getBranches({ signal: controller.signal })
      .then(({ data }) => {
        const branches = (data.items ?? []).filter((b) => Number.isFinite(b.latitude) && Number.isFinite(b.longitude))
        setState({ phase: 'done', branches })
      })
      .catch((error) => {
        if (error?.name !== 'CanceledError') setState({ phase: 'error', error, branches: [] })
      })
    return () => controller.abort()
  }, [])

  function show(branch) {
    setSelected(branch.id)
    map.current?.flyTo([branch.latitude, branch.longitude], Math.max(map.current.getZoom(), 15), { duration: 0.6 })
    const marker = markers.current[branch.id]
    marker?.openPopup()
    // the marker's picture is a focusable element (Leaflet keyboard support)
    marker?.getElement()?.focus({ preventScroll: true })
  }

  const { phase, branches, error } = state

  return (
    <section id="branches" className="branches-section legacy-branches" aria-labelledby="branches-heading" tabIndex={-1}>
      <div className="container">
        <div className="section-header">
          <h2 id="branches-heading">Our Branches</h2>
          <p>Visit any of our conveniently located branches. Reserve online and pick up at your preferred location.</p>
        </div>

        <div aria-live="polite">
          {phase === 'loading' ? <p className="filter-status" style={{ display: 'inline-block' }}>Loading branches...</p> : null}
          {phase === 'error' ? <ErrorMessage error={error} /> : <ErrorMessage />}
          {phase === 'done' && branches.length === 0 ? <p className="text-center">No branches available yet.</p> : null}
        </div>

        {phase === 'done' && branches.length > 0 ? (
          <>
            <div className="branch-map" role="region" aria-label="Map of the branches. The same information is in the list below.">
              <MapContainer
                ref={map}
                center={DEFAULT_CENTER}
                zoom={11}
                scrollWheelZoom={false}
                style={{ height: '100%', width: '100%' }}
              >
                <FitBranches branches={branches} />
                <TileLayer attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' url="https://tile.openstreetmap.org/{z}/{x}/{y}.png" maxZoom={19} />
                {branches.map((branch) => (
                  <Marker
                    key={branch.id}
                    position={[branch.latitude, branch.longitude]}
                    title={branch.name}
                    alt={`${branch.name} branch`}
                    ref={(marker) => {
                      if (marker) markers.current[branch.id] = marker
                    }}
                    eventHandlers={{ click: () => setSelected(branch.id), popupopen: () => setSelected(branch.id) }}
                  >
                    <Popup>
                      <strong>{branch.name}</strong>
                      <br />
                      {branch.address}
                    </Popup>
                  </Marker>
                ))}
              </MapContainer>
            </div>
            <p className="map-note">Branch locations are approximate.</p>

            <ul className="row g-3 branch-list">
              {branches.map((branch) => (
                <li key={branch.id} className="col-12 col-lg-6">
                  <article className={selected === branch.id ? 'branch-card is-selected' : 'branch-card'} aria-label={branch.name}>
                    <div className="branch-content">
                      <span className="branch-badge">Branch</span>
                      <h3 className="branch-name">{branch.name}</h3>
                      <div className="branch-info">
                        <div className="info-item">
                          <i className="bi bi-geo-alt-fill" aria-hidden="true"></i>
                          <span>{branch.address}</span>
                        </div>
                        {branch.opening_hours ? (
                          <div className="info-item">
                            <i className="bi bi-clock-fill" aria-hidden="true"></i>
                            <span>{branch.opening_hours}</span>
                          </div>
                        ) : null}
                        {branch.contact_no ? (
                          <div className="info-item">
                            <i className="bi bi-telephone-fill" aria-hidden="true"></i>
                            <span>{branch.contact_no}</span>
                          </div>
                        ) : null}
                      </div>
                      <div className="branch-actions">
                        <button type="button" className="btn btn-branch btn-view" onClick={() => show(branch)} aria-pressed={selected === branch.id}>
                          Show on map<span className="visually-hidden">: {branch.name}</span>
                        </button>
                        <a className="btn btn-branch btn-directions" href={osmLink(branch)} target="_blank" rel="noopener noreferrer">
                          Open in OpenStreetMap<span className="visually-hidden">: {branch.name} (opens in a new tab)</span>
                        </a>
                      </div>
                    </div>
                  </article>
                </li>
              ))}
            </ul>
          </>
        ) : null}
      </div>
    </section>
  )
}
