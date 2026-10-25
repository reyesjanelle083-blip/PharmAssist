import ContentPage from '../components/ContentPage.jsx'
import { site } from '../config/site.js'
import { usePageTitle } from '../hooks/usePageTitle.js'

export default function Privacy() {
  usePageTitle('Privacy Notice')

  return (
    <ContentPage title="Privacy Notice" subtitle="What PharmAssist collects and why, in plain language">
      <section className="content" aria-labelledby="privacy-note">
        <h2 id="privacy-note">About this notice</h2>
        <p>
          PharmAssist is a school project. This notice was written by students to explain what the site does with your information. It is
          <strong> not legal advice</strong> and it is not a formal legal document.
        </p>
      </section>

      <section className="content" aria-labelledby="privacy-collect">
        <h2 id="privacy-collect">What we collect</h2>
        <ul>
          <li>
            <strong>Account details:</strong> your name, email address, contact number and a password (kept only as a hash, never as plain
            text).
          </li>
          <li>
            <strong>Your ID photo:</strong> used only to check who you are. It is deleted after the administrator has made a decision.
          </li>
          <li>
            <strong>A prescription photo,</strong> if you add one to a reservation: the text on it is read on our own server. It is not
            sent to any other company or service.
          </li>
          <li>
            <strong>Your reservations:</strong> what you reserved, at which branch, and the status.
          </li>
        </ul>
      </section>

      <section className="content" aria-labelledby="privacy-location">
        <h2 id="privacy-location">Your location</h2>
        <p>
          If you press <strong>Use my location</strong>, your browser shares your position for that one search, so we can rank the
          branches by distance. We do not store it, we do not write it in any log, and it is not kept in the address of the page.
        </p>
        <p>
          To estimate the distance, the position is sent to the distance service of this project. If that service is not available, the
          estimate is calculated by the project's own server.
        </p>
      </section>

      <section className="content" aria-labelledby="privacy-map">
        <h2 id="privacy-map">The map</h2>
        <p>
          The map of the branches uses pictures (tiles) from <strong>OpenStreetMap</strong>. When the map loads, <strong>your browser sends
          its request to the OpenStreetMap tile server</strong>, which can see your internet address and the part of the map you look at.
          This is the only request the site makes to another company's server. Branch locations are approximate.
        </p>
      </section>

      <section className="content" aria-labelledby="privacy-storage">
        <h2 id="privacy-storage">What stays in your browser</h2>
        <ul>
          <li>Your log-in session, in the browser tab only. It ends when you close the tab or log out.</li>
          <li>Your text size, high contrast and readable font choices, so they are remembered next time.</li>
        </ul>
        <p>We do not use advertising or tracking cookies.</p>
      </section>

      <section className="content" aria-labelledby="privacy-ask">
        <h2 id="privacy-ask">Questions</h2>
        <p>
          {site.email ? (
            <>
              You can write to the project team at <a href={`mailto:${site.email}`}>{site.email}</a>.
            </>
          ) : (
            'Contact details for the project team will be added soon.'
          )}
        </p>
      </section>
    </ContentPage>
  )
}
