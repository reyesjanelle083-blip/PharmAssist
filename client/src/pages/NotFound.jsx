import { Link } from 'react-router-dom'

export default function NotFound() {
  return (
    <>
      <h1>Page not found</h1>
      <p>The page you are looking for does not exist.</p>
      <p>
        <Link to="/">Back to the home page</Link>
      </p>
    </>
  )
}
