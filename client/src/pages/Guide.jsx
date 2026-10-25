import ContentPage from '../components/ContentPage.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

/**
 * The old guide page (guide.php), rebuilt: the same sections and step-by-step layout, in plain language. The old text-to-speech
 * buttons are gone, and the steps describe how this version works (a claim code, a 48 hour reservation, no email field).
 */
export default function Guide() {
  usePageTitle('Guide')

  return (
    <ContentPage title="Welcome to PharmAssist" subtitle="Your trusted online medicine reservation system">
      <section className="content" id="about" aria-labelledby="guide-about">
        <h2 id="guide-about">About Us</h2>
        <p>
          At <strong>PharmAssist</strong>, we believe that access to essential medicine should be simple, reliable, and stress-free. With the
          growing demand in physical pharmacies and the challenges of limited stock availability, our platform was built to make
          healthcare more accessible for everyone.
        </p>
        <p>We provide an online reservation system that allows customers to:</p>
        <ul>
          <li>
            <strong>Check availability</strong> across several pharmacy branches.
          </li>
          <li>
            <strong>Reserve medicines</strong>, securing your spot without the hassle of queues.
          </li>
          <li>
            <strong>Get a claim code</strong> that you show at the branch.
          </li>
        </ul>
        <p>
          PharmAssist is a school project. It is not a real pharmacy, and what you read here is general information, not medical advice.
        </p>
      </section>

      <section className="content" id="instructions" aria-labelledby="guide-how">
        <h2 id="guide-how">How to Reserve Medicine</h2>
        <p>Please read the steps below to understand how the reservation system works.</p>

        <div>
          <h3>Step 1: Search for a medicine</h3>
          <p>
            Type the name of a medicine (the generic name or the brand) in the <strong>search bar</strong> at the top of the page and press
            Enter. For each branch that has it in stock, you see:
          </p>
          <ul>
            <li>Medicine name, strength and form</li>
            <li>Price</li>
            <li>How many are in stock</li>
            <li>Whether a prescription is needed</li>
            <li>
              The <strong>Reserve</strong> button
            </li>
          </ul>
          <p>
            Press <strong>Use my location</strong> first if you want the branches ranked by how long it takes to get there. Your location is
            used for that one search only and is not stored.
          </p>
        </div>

        <div>
          <h3>Step 2: Reserve a medicine</h3>
          <p>
            Click the <strong>Reserve</strong> button of the branch you want. You need an account for this: register, verify your email with
            the 6-digit code, and wait for the pharmacy administrator to approve your ID photo.
          </p>
          <ul>
            <li>Choose the quantity.</li>
            <li>If the medicine needs a prescription, add a photo of the prescription.</li>
          </ul>
          <p>
            <i className="bi bi-exclamation-triangle" aria-hidden="true"></i> Make sure your details are correct to avoid rejection. (The
            reservation page is coming soon in this version.)
          </p>
        </div>

        <div>
          <h3>Step 3: Submit your reservation</h3>
          <p>Click <strong>Confirm</strong>. You will see that your reservation is pending for approval.</p>
        </div>

        <div>
          <h3>Step 4: Wait for approval</h3>
          <ul>
            <li>
              <strong>Approved</strong> &rarr; you have a unique <strong>claim code</strong>.
            </li>
            <li>
              <strong>Rejected</strong> &rarr; you will see the reason.
            </li>
          </ul>
        </div>

        <div>
          <h3>Step 5: Claim your reservation</h3>
          <ul>
            <li>
              Show your <strong>claim code</strong> at the pharmacy and pay there.
            </li>
            <li>
              The pharmacist always checks the <strong>original prescription</strong>.
            </li>
          </ul>
        </div>

        <div className="rules">
          <h3>Rules</h3>
          <ul>
            <li>
              Each reservation is <strong>branch-specific</strong>.
            </li>
            <li>Details must be accurate and complete.</li>
            <li>
              A reservation is kept for <strong>48 hours</strong>. After that the stock goes back on the shelf.
            </li>
            <li>The pharmacy may reject a reservation, for example when the stock or the prescription is a problem.</li>
            <li>Fake reservations or repeated no-claims can lead to restrictions.</li>
            <li>You can cancel a reservation yourself before you collect it.</li>
          </ul>
        </div>
      </section>

      <section className="content" id="note" aria-labelledby="guide-note">
        <h2 id="guide-note">Please note</h2>
        <p>
          This is general information about how the site works. It is <strong>not medical advice</strong>. Ask a pharmacist or a doctor
          before you take any medicine.
        </p>
      </section>
    </ContentPage>
  )
}
