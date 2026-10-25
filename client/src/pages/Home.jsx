import { lazy, Suspense, useEffect, useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { AboutDialog, ContactDialog } from '../components/SiteDialogs.jsx'
import Button from '../components/ui/Button.jsx'
import Carousel from '../components/Carousel.jsx'
import SearchResults from '../components/SearchResults.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

// The map library is large: it loads when the home page opens, not with the first page of the app.
const BranchesSection = lazy(() => import('../components/BranchesSection.jsx'))

/**
 * The old home page (homepage.php): the big "Reserve it now. Pick it up later." section with the About Us and Contact Us buttons,
 * the search results, the branches and a carousel. The old hero picture (img/front1.png) is not in the old repository, so the
 * hero is text only. The search bar is in the top bar, as on the old pages.
 */
export default function Home() {
  usePageTitle('Find your medicine')
  const location = useLocation()
  const [dialog, setDialog] = useState(null) // 'about' | 'contact' | null
  const aboutButton = useRef(null)
  const contactButton = useRef(null)

  // /#branches (from the sidebar or the footer): scroll to the branches and put focus there
  useEffect(() => {
    if (location.hash !== '#branches') return
    const section = document.getElementById('branches')
    if (!section) return
    requestAnimationFrame(() => {
      section.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
      section.focus({ preventScroll: true })
    })
  }, [location.hash, location.key])

  return (
    <div className="legacy-home">
      <div className="container-fluid p-0 w-100">
        <div
          className="parallax d-flex flex-lg-row flex-column justify-content-center align-items-center px-5 py-4 gap-5"
          style={{ backgroundColor: '#E8ECF1' }}
        >
          <div className="pe-lg-1 text-center text-lg-start" style={{ maxWidth: '46rem' }}>
            <h1 className="m-0 text-uppercase hero-title fw-bold" style={{ color: 'var(--pa-primary)' }}>
              Reserve it now. Pick it up later.
            </h1>
            <p className="hero-subtitle fs-5">
              Browse available medicines from any branch, reserve them online, and simply show your ticket when you arrive &mdash; no more
              waiting in line.
            </p>
            <div className="button d-flex flex-column flex-sm-row justify-content-center justify-content-lg-start align-items-center gap-2 mt-3">
              <Button variant="secondary" ref={aboutButton} className="w-100 w-sm-auto" onClick={() => setDialog('about')} aria-haspopup="dialog">
                About Us
              </Button>
              <Button variant="primary" ref={contactButton} className="w-100 w-sm-auto" onClick={() => setDialog('contact')} aria-haspopup="dialog">
                Contact Us
              </Button>
            </div>
          </div>
        </div>
      </div>

      <SearchResults />
      <Suspense
        fallback={
          <section id="branches" className="branches-section legacy-branches" aria-label="Our Branches">
            <p className="filter-status" role="status" style={{ display: 'inline-block' }}>
              Loading branches...
            </p>
          </section>
        }
      >
        <BranchesSection />
      </Suspense>
      <Carousel />

      <AboutDialog open={dialog === 'about'} onClose={() => setDialog(null)} returnFocusRef={aboutButton} />
      <ContactDialog open={dialog === 'contact'} onClose={() => setDialog(null)} returnFocusRef={contactButton} />
    </div>
  )
}
