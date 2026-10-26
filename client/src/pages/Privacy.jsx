import ContentPage from '../components/ContentPage.jsx'
import { site } from '../config/site.js'
import { usePageTitle } from '../hooks/usePageTitle.js'

export default function Privacy() {
  usePageTitle('Privacy Notice')

  return (
    <ContentPage
      title="Privacy Notice"
      subtitle="What PharmAssist collects and why, in plain language"
    >
      <section
        className="content"
        aria-labelledby="privacy-note"
      >
        <h2 id="privacy-note">About this notice</h2>

        <p>
          PharmAssist is a school project. This notice was written by
          students to explain what the system does with information
          provided by its users. It is <strong>not legal advice</strong>{' '}
          and is not a formal legal document.
        </p>
      </section>

      <section
        className="content"
        aria-labelledby="privacy-collect"
      >
        <h2 id="privacy-collect">What we collect</h2>

        <ul>
          <li>
            <strong>Account details:</strong> your first name, last name,
            email address and contact number. Your password is stored only
            in hashed form and is not stored as readable plain text.
          </li>

          <li>
            <strong>A prescription photo,</strong> when required for a
            reservation. PharmAssist may process the image using its OCR
            service to extract text that helps pharmacy staff review the
            prescription.
          </li>

          <li>
            <strong>Reservation information:</strong> the medicines you
            reserved, selected branch, quantities, reservation status and
            related transaction information.
          </li>
        </ul>
      </section>

      <section
        className="content"
        aria-labelledby="privacy-location"
      >
        <h2 id="privacy-location">Your location</h2>

        <p>
          If you select <strong>Use my location</strong>, your browser
          shares your current coordinates for that search so PharmAssist
          can rank pharmacy branches by proximity.
        </p>

        <p>
          PharmAssist does not store the shared customer location as part
          of the customer's account or reservation.
        </p>

        <p>
          The location may be used by the project's distance and routing
          integration to estimate travel distance or travel time. If
          routing information is unavailable, the system can use a
          fallback distance estimate.
        </p>
      </section>

      <section
        className="content"
        aria-labelledby="privacy-map"
      >
        <h2 id="privacy-map">The map</h2>

        <p>
          PharmAssist uses <strong>OpenStreetMap</strong> map tiles for
          branch visualization. When the map is displayed, the browser may
          request map tiles from the OpenStreetMap tile service.
        </p>

        <p>
          Pharmacy branch locations shown in this school project are for
          demonstration purposes.
        </p>
      </section>

      <section
        className="content"
        aria-labelledby="privacy-storage"
      >
        <h2 id="privacy-storage">What stays in your browser</h2>

        <ul>
          <li>
            Your authenticated session information is kept in the browser
            session and ends when you log out or the session expires.
          </li>

          <li>
            Accessibility preferences such as text size, high contrast
            and readable font settings may be stored so they can be
            remembered.
          </li>
        </ul>

        <p>
          PharmAssist does not use advertising or tracking cookies.
        </p>
      </section>

      <section
        className="content"
        aria-labelledby="privacy-ask"
      >
        <h2 id="privacy-ask">Questions</h2>

        <p>
          {site.email ? (
            <>
              You can write to the project team at{' '}
              <a href={`mailto:${site.email}`}>
                {site.email}
              </a>
              .
            </>
          ) : (
            'Contact details for the project team will be added soon.'
          )}
        </p>
      </section>
    </ContentPage>
  )
}