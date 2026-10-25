import AvailabilitySearch from '../components/AvailabilitySearch.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

/**
 * The old home page (homepage.php): the big "Reserve it now. Pick it up later." section, then the search. The old hero picture
 * (img/front1.png) is not in the old repository, so the section is text only. The About Us and Contact Us buttons opened
 * Bootstrap modals (JavaScript), which are not used here; one button takes you to the search instead.
 */
export default function Home() {
  usePageTitle('Find your medicine')

  return (
    <div className="legacy-home">
      <div className="container-fluid p-0 w-100">
        <div
          className="parallax d-flex flex-lg-row flex-column justify-content-center align-items-center px-5 py-5 gap-5"
          style={{ backgroundColor: '#E8ECF1' }}
        >
          <div className="pe-lg-1 text-center text-lg-start" style={{ maxWidth: '46rem' }}>
            <h1 className="m-0 text-uppercase display-3 fw-bold" style={{ color: '#7393A7' }}>
              Reserve it now. Pick it up later.
            </h1>
            <p className="fs-5" style={{ color: '#6C737E' }}>
              Browse available medicines from any branch, reserve them online, and simply show your ticket when you arrive &mdash; no more
              waiting in line.
            </p>
            <div className="button d-flex flex-column flex-sm-row justify-content-center justify-content-lg-start align-items-center gap-2 mt-3">
              <a className="btn btn-lg w-100 w-sm-auto" href="#search" style={{ backgroundColor: '#7393A7', color: '#FFFFFF' }}>
                Search medicines
              </a>
            </div>
          </div>
        </div>
      </div>

      <AvailabilitySearch />
    </div>
  )
}
