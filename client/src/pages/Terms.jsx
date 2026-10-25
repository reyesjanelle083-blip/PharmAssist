import ContentPage from '../components/ContentPage.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

export default function Terms() {
  usePageTitle('Terms & Conditions')

  return (
    <ContentPage title="Terms & Conditions" subtitle="Please read these before you use PharmAssist">
      <section className="content" aria-labelledby="terms-note">
        <h2 id="terms-note">About these terms</h2>
        <p>
          PharmAssist is a school project for the subject Systems Integration and Architecture. These terms were written by students in
          plain language. They are <strong>not legal advice</strong> and not a formal legal contract.
        </p>
      </section>

      <section className="content" aria-labelledby="terms-use">
        <h2 id="terms-use">Using the site</h2>
        <ul>
          <li>
            <strong>It is not a real pharmacy.</strong> No real transactions take place: nothing is sold, shipped or paid for through the
            site.
          </li>
          <li>
            <strong>The information is not medical advice.</strong> Stock, prices and descriptions are for demonstration. Ask a pharmacist
            or a doctor before you take any medicine.
          </li>
          <li>
            <strong>The pharmacist must always check the original prescription.</strong> A photo of a prescription on this site does not
            replace that check, and the text read from it may contain mistakes.
          </li>
          <li>Give correct details. Fake reservations or repeated no-claims can lead to restrictions on your account.</li>
          <li>A reservation is kept for 48 hours and can be rejected or cancelled.</li>
          <li>Branch locations on the map are approximate.</li>
        </ul>
      </section>

      <section className="content" aria-labelledby="terms-changes">
        <h2 id="terms-changes">Changes</h2>
        <p>
          The site may change or stop working at any time, because it is a school project. See also the Privacy Notice for what happens to
          your information.
        </p>
      </section>
    </ContentPage>
  )
}
