import { site, socialLinks } from '../config/site.js'
import Modal from './ui/Modal.jsx'

export function AboutDialog({ open, onClose, returnFocusRef }) {
  return (
    <Modal open={open} onClose={onClose} returnFocusRef={returnFocusRef} title="About Us">
      <p>
        <strong>PharmAssist</strong> is an online medicine reservation system. You can search for a medicine, see which pharmacy branch has
        it in stock and how far away that branch is, and reserve it. You pick it up and pay at the branch.
      </p>
      <h3>Why we made it</h3>
      <p>
        Our project supports the United Nations Sustainable Development Goal 3, Good Health and Well-being, and in particular Target 3.8:
        access to safe, effective, quality and affordable essential medicines for all. Finding out where a medicine is in stock is a small
        step toward that, because it saves people wasted trips and long queues.
      </p>
      <h3>Please remember</h3>
      <ul>
        <li>
          PharmAssist is a <strong>school project</strong> for the subject Systems Integration and Architecture.
        </li>
        <li>
          It is <strong>not a real pharmacy</strong>. No real medicines are sold, and no payment is taken.
        </li>
        <li>
          The information on this site is <strong>not medical advice</strong>. Ask a pharmacist or a doctor before you take any medicine.
        </li>
      </ul>
    </Modal>
  )
}

export function ContactDialog({ open, onClose, returnFocusRef }) {
  const social = socialLinks()

  return (
    <Modal open={open} onClose={onClose} returnFocusRef={returnFocusRef} title="Contact Us">
      <p>This is a school project. You can reach the project team here:</p>
      <ul className="legacy-dialog__contact">
        {site.email ? (
          <li>
            <i className="bi bi-envelope-fill" aria-hidden="true"></i> Email: <a href={`mailto:${site.email}`}>{site.email}</a>
          </li>
        ) : null}
        {site.phone ? (
          <li>
            <i className="bi bi-telephone-fill" aria-hidden="true"></i> Phone: {site.phone}
          </li>
        ) : null}
        {social.map((link) => (
          <li key={link.key}>
            <i className={link.icon} aria-hidden="true"></i>{' '}
            <a href={link.href} target="_blank" rel="noopener noreferrer">
              {link.label}
              <span className="visually-hidden"> (opens in a new tab)</span>
            </a>
          </li>
        ))}
        {!site.email && !site.phone && social.length === 0 ? <li>Contact details have not been added yet.</li> : null}
      </ul>
      <p>
        Please do not send medical questions or personal health information. For medical questions, ask a pharmacist or a doctor.
      </p>
    </Modal>
  )
}
