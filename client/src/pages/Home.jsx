import {
  lazy,
  Suspense,
  useEffect,
  useRef,
  useState,
} from 'react'
import { useLocation } from 'react-router-dom'
import {
  AboutDialog,
  ContactDialog,
} from '../components/SiteDialogs.jsx'
import Button from '../components/ui/Button.jsx'
import Carousel from '../components/Carousel.jsx'
import MedicineOverview from '../components/MedicineOverview.jsx'
import SearchResults from '../components/SearchResults.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

const BranchesSection = lazy(
  () => import('../components/BranchesSection.jsx'),
)

export default function Home() {
  usePageTitle('Find your medicine')

  const location = useLocation()

  const [dialog, setDialog] = useState(null)

  const aboutButton = useRef(null)
  const contactButton = useRef(null)

  useEffect(() => {
    if (location.hash !== '#branches') return

    let attempts = 0

    const findSection = () => {
      const section =
        document.getElementById('branches')

      if (section) {
        section.scrollIntoView({
          behavior: window.matchMedia(
            '(prefers-reduced-motion: reduce)',
          ).matches
            ? 'auto'
            : 'smooth',
          block: 'start',
        })

        section.focus({
          preventScroll: true,
        })

        return
      }

      attempts += 1

      if (attempts < 20) {
        window.setTimeout(findSection, 75)
      }
    }

    findSection()
  }, [location.hash, location.key])

  return (
    <div className="legacy-home final-home">
      {/* =====================================================
          HERO
      ====================================================== */}

      <section
        className="home-hero"
        aria-labelledby="home-hero-title"
      >
        <div className="home-hero__content">
          <div className="home-hero__eyebrow">
            <i
              className="bi bi-heart-pulse-fill"
              aria-hidden="true"
            ></i>
            Medicine availability made easier
          </div>

          <h1 id="home-hero-title">
            Reserve it now.
            <span> Pick it up later.</span>
          </h1>

          <p className="home-hero__lead">
            Search medicines across pharmacy branches,
            compare availability, reserve online, and
            present your claim code when you arrive.
          </p>

          <div className="home-hero__actions">
            <Button
              to="/medicines"
              variant="primary"
              className="home-hero__primary"
            >
              <i
                className="bi bi-capsule-pill"
                aria-hidden="true"
              ></i>
              Browse Medicines
            </Button>

            <Button
              to={{
                pathname: '/',
                hash: '#branches',
              }}
              variant="outline"
              className="home-hero__secondary"
            >
              <i
                className="bi bi-geo-alt"
                aria-hidden="true"
              ></i>
              View Branches
            </Button>
          </div>

          <div className="home-hero__small-actions">
            <button
              type="button"
              ref={aboutButton}
              onClick={() => setDialog('about')}
              aria-haspopup="dialog"
            >
              About PharmAssist
            </button>

            <span aria-hidden="true">•</span>

            <button
              type="button"
              ref={contactButton}
              onClick={() => setDialog('contact')}
              aria-haspopup="dialog"
            >
              Contact Us
            </button>
          </div>
        </div>

        <div
          className="home-hero__visual"
          aria-label="How PharmAssist works"
        >
          <div className="home-hero__visual-card">
            <div className="home-flow-step">
              <span className="home-flow-step__icon">
                <i
                  className="bi bi-search"
                  aria-hidden="true"
                ></i>
              </span>

              <div>
                <strong>Find</strong>
                <span>
                  Search the medicine you need
                </span>
              </div>
            </div>

            <div
              className="home-flow-line"
              aria-hidden="true"
            ></div>

            <div className="home-flow-step">
              <span className="home-flow-step__icon">
                <i
                  className="bi bi-shop"
                  aria-hidden="true"
                ></i>
              </span>

              <div>
                <strong>Choose</strong>
                <span>
                  See which branch has stock
                </span>
              </div>
            </div>

            <div
              className="home-flow-line"
              aria-hidden="true"
            ></div>

            <div className="home-flow-step">
              <span className="home-flow-step__icon">
                <i
                  className="bi bi-bag-check"
                  aria-hidden="true"
                ></i>
              </span>

              <div>
                <strong>Reserve</strong>
                <span>
                  Hold your medicine for pickup
                </span>
              </div>
            </div>

            <div
              className="home-flow-line"
              aria-hidden="true"
            ></div>

            <div className="home-flow-step">
              <span className="home-flow-step__icon">
                <i
                  className="bi bi-ticket-perforated"
                  aria-hidden="true"
                ></i>
              </span>

              <div>
                <strong>Pick up</strong>
                <span>
                  Show your claim code at the branch
                </span>
              </div>
            </div>
          </div>

          <div className="home-hero__floating home-hero__floating--stock">
            <i
              className="bi bi-box-seam"
              aria-hidden="true"
            ></i>

            <div>
              <strong>Live stock</strong>
              <span>Across branches</span>
            </div>
          </div>

          <div className="home-hero__floating home-hero__floating--rx">
            <i
              className="bi bi-file-medical"
              aria-hidden="true"
            ></i>

            <div>
              <strong>Rx support</strong>
              <span>Prescription review</span>
            </div>
          </div>
        </div>
      </section>

      {/* =====================================================
          MEDICINE OVERVIEW
      ====================================================== */}

      <MedicineOverview />

      {/* =====================================================
          SEARCH
      ====================================================== */}

      <SearchResults />

      {/* =====================================================
          BRANCHES
      ====================================================== */}

      <Suspense
        fallback={
          <section
            id="branches"
            className="home-section-block"
            aria-label="Our Branches"
          >
            <div className="home-section-heading">
              <span>Locations</span>
              <h2>Our Branches</h2>
            </div>

            <p
              className="filter-status"
              role="status"
            >
              Loading branches...
            </p>
          </section>
        }
      >
        <BranchesSection />
      </Suspense>

      {/* =====================================================
          CAROUSEL
      ====================================================== */}

      <Carousel />

      <AboutDialog
        open={dialog === 'about'}
        onClose={() => setDialog(null)}
        returnFocusRef={aboutButton}
      />

      <ContactDialog
        open={dialog === 'contact'}
        onClose={() => setDialog(null)}
        returnFocusRef={contactButton}
      />
    </div>
  )
}