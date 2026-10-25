import AvailabilitySearch from '../components/AvailabilitySearch.jsx'
import mark from '../assets/pharmassist-mark.png'
import { usePageTitle } from '../hooks/usePageTitle.js'

export default function Home() {
  usePageTitle('Find your medicine')

  return (
    <>
      <section className="hero" aria-labelledby="hero-heading">
        <img className="hero__mark" src={mark} alt="" aria-hidden="true" />
        <div className="hero__text">
          <h1 id="hero-heading">Find your medicine at a PharmAssist branch</h1>
          <p>
            Search for a medicine, see which branches have it in stock and how far they are, then reserve it online. You pick it up
            and pay at the branch.
          </p>
        </div>
      </section>

      <AvailabilitySearch />
    </>
  )
}
