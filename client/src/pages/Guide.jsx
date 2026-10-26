import ContentPage from '../components/ContentPage.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

export default function Guide() {
  usePageTitle('Guide')

  return (
    <ContentPage
      title="Welcome to PharmAssist"
      subtitle="Your trusted online medicine reservation system"
    >
      <section
        className="content"
        id="about"
        aria-labelledby="guide-about"
      >
        <h2 id="guide-about">About Us</h2>

        <p>
          At <strong>PharmAssist</strong>, we believe that access to
          essential medicine should be simple, reliable, and stress-free.
          With the growing demand in physical pharmacies and the
          challenges of limited stock availability, our platform was
          built to make healthcare more accessible for everyone.
        </p>

        <p>
          We provide an online reservation system that allows customers to:
        </p>

        <ul>
          <li>
            <strong>Check availability</strong> across several pharmacy
            branches.
          </li>

          <li>
            <strong>Reserve medicines</strong> before visiting the
            pharmacy.
          </li>

          <li>
            <strong>Find nearby branches</strong> where the medicine is
            currently available.
          </li>

          <li>
            <strong>Get a claim code</strong> to present when collecting
            an approved reservation.
          </li>
        </ul>

        <p>
          PharmAssist is a school project. It is not a real pharmacy,
          and the information shown in the system is not a substitute
          for professional medical advice.
        </p>
      </section>

      <section
        className="content"
        id="instructions"
        aria-labelledby="guide-how"
      >
        <h2 id="guide-how">How to Reserve Medicine</h2>

        <p>
          Please read the steps below to understand how the reservation
          system works.
        </p>

        <div>
          <h3>Step 1: Search for a medicine</h3>

          <p>
            Type the generic or brand name of a medicine in the search
            bar. Search results can show:
          </p>

          <ul>
            <li>Medicine name, strength and dosage form</li>
            <li>Price</li>
            <li>Available stock</li>
            <li>Prescription requirement</li>
            <li>Branches where the medicine is available</li>
          </ul>

          <p>
            Select <strong>Use my location</strong> if you want PharmAssist
            to rank available branches by proximity. Your location is used
            only for the search and is not stored by PharmAssist.
          </p>
        </div>

        <div>
          <h3>Step 2: Create or log in to your account</h3>

          <p>
            You need a customer account before creating a reservation.
            Register using your name, email address, contact number and
            password, then verify your email using the 6-digit code sent
            to you.
          </p>

          <p>
            Once your email is verified, you can log in immediately.
          </p>
        </div>

        <div>
          <h3>Step 3: Add medicines to your cart</h3>

          <p>
            Choose an available pharmacy branch, select the quantity you
            need and add the medicine to your cart.
          </p>

          <p>
            If one or more medicines require a prescription, upload a
            clear photo of the prescription during checkout.
          </p>
        </div>

        <div>
          <h3>Step 4: Submit your reservation</h3>

          <p>
            Review your medicines, branch, quantities and any prescription
            photo, then confirm the reservation.
          </p>

          <p>
            A newly submitted reservation is placed in{' '}
            <strong>Pending</strong> status while the pharmacy reviews it.
          </p>
        </div>

        <div>
          <h3>Step 5: Wait for pharmacy review</h3>

          <ul>
            <li>
              <strong>Approved</strong> — the pharmacy accepted your
              reservation.
            </li>

            <li>
              <strong>Rejected</strong> — the pharmacy could not accept
              the reservation and the reason will be shown.
            </li>
          </ul>

          <p>
            For reservations containing prescription medicines, the
            uploaded prescription and automatically extracted OCR text
            are available to pharmacy staff for review. The original
            prescription must still be checked when the medicine is
            claimed.
          </p>
        </div>

        <div>
          <h3>Step 6: Claim your reservation</h3>

          <ul>
            <li>
              Present your <strong>claim code</strong> at the selected
              pharmacy branch.
            </li>

            <li>
              Bring the original prescription when required.
            </li>

            <li>
              Payment is completed at the pharmacy branch.
            </li>
          </ul>
        </div>

        <div className="rules">
          <h3>Rules</h3>

          <ul>
            <li>
              Each reservation is <strong>branch-specific</strong>.
            </li>

            <li>
              Reservation details should be accurate and complete.
            </li>

            <li>
              A reservation is held for <strong>48 hours</strong>. After
              expiration, the reserved stock becomes available again.
            </li>

            <li>
              The pharmacy may reject a reservation when there is a stock
              or prescription-related issue.
            </li>

            <li>
              Customers may cancel an active reservation before pickup.
            </li>
          </ul>
        </div>
      </section>

      <section
        className="content"
        id="note"
        aria-labelledby="guide-note"
      >
        <h2 id="guide-note">Please note</h2>

        <p>
          PharmAssist provides medicine availability and reservation
          assistance. Information displayed in the system is{' '}
          <strong>not medical advice</strong>. Ask a pharmacist or doctor
          for professional guidance before taking any medicine.
        </p>
      </section>
    </ContentPage>
  )
}