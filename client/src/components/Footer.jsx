import { Link } from 'react-router-dom'
import { site, socialLinks } from '../config/site.js'

const YEAR = new Date().getFullYear()

/** The old pale-blue footer (footer.php): four columns and a copyright line. Contact details come from config/site.js. */
export default function Footer() {
  const social = socialLinks()

  return (
    <div className="home-sections">
      <footer className="site-footer">
        <div className="container">
          <div className="row footer-columns">
            <div>
              <h2 className="footer-heading">About PharmAssist</h2>
              <p className="text-justify">
                PharmAssist is your trusted online medicine reservation system. We provide a seamless platform that allows customers to
                track medicine availability in real time across multiple pharmacy branches.
              </p>
            </div>

            <div>
              <h2 className="footer-heading">Explore</h2>
              <ul className="footer-links">
                <li>
                  <Link to="/">Home</Link>
                </li>
                <li>
                  <Link to={{ pathname: '/', hash: '#branches' }}>Branches</Link>
                </li>
                <li>
                  <Link to="/guide">Guide</Link>
                </li>
                <li>
                  <Link to="/login">Log in</Link>
                </li>
                <li>
                  <Link to="/register">Register</Link>
                </li>
              </ul>
            </div>

            <div>
              <h2 className="footer-heading">Legal</h2>
              <ul className="footer-links">
                <li>
                  <Link to="/terms">Terms &amp; Conditions</Link>
                </li>
                <li>
                  <Link to="/privacy">Privacy Notice</Link>
                </li>
                <li>A school project, not a real pharmacy. Not medical advice.</li>
              </ul>
            </div>

            <div>
              <h2 className="footer-heading">Contact Us</h2>
              <ul className="footer-links">
                {site.email ? (
                  <li>
                    Email: <a href={`mailto:${site.email}`}>{site.email}</a>
                  </li>
                ) : null}
                {site.phone ? <li>Phone: {site.phone}</li> : null}
                {!site.email && !site.phone && social.length === 0 ? <li>Contact details will be added soon.</li> : null}
              </ul>
            </div>
          </div>
          <hr />
        </div>
        <div className="container">
          <div className="row">
            <div className="col-md-12">
              <div className="footer-bottom">
                <p className="copyright-text">
                  Copyright &copy; {YEAR} All Rights Reserved by{' '}
                  <Link to="/" className="logo">
                    PharmAssist
                  </Link>
                  .
                </p>

                {social.length > 0 ? (
                  <ul className="social-icons">
                    {social.map((link) => (
                      <li key={link.key}>
                        <a className={link.key} href={link.href} target="_blank" rel="noopener noreferrer" aria-label={`${link.label} (opens in a new tab)`}>
                          <i className={link.icon} aria-hidden="true"></i>
                        </a>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            </div>
          </div>
        </div>
      </footer>
    </div>
  )
}
