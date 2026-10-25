import { Link } from 'react-router-dom'

const YEAR = new Date().getFullYear()

/** The old pale-blue footer (footer.php): four columns and a copyright line. */
export default function Footer() {
  return (
    <section className="home-sections">
      <footer className="site-footer">
        <div className="container">
          <div className="row footer-columns">
            <div>
              <h6>About PharmAssist</h6>
              <p className="text-justify">
                PharmAssist is your trusted online medicine reservation system. We provide a seamless platform that allows customers to
                track medicine availability in real time across multiple pharmacy branches.
              </p>
            </div>

            <div>
              <h6>Explore</h6>
              <ul className="footer-links">
                <li>
                  <Link to="/">Home</Link>
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
              <h6>Please note</h6>
              <ul className="footer-links">
                <li>PharmAssist is a school project, not a real pharmacy service.</li>
                <li>The information on this site is not medical advice.</li>
              </ul>
            </div>

            <div>
              <h6>Contact Us</h6>
              <ul className="footer-links">
                <li>Email: a.pharmasee@gmail.com</li>
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
                  Copyright &copy; {YEAR} All Rights Reserved by <Link to="/" className="logo">PharmAssist</Link>.
                </p>
              </div>
            </div>
          </div>
        </div>
      </footer>
    </section>
  )
}
