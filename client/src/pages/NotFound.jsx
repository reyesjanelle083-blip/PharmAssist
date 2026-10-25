import MessagePage from '../components/MessagePage.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

export default function NotFound() {
  usePageTitle('Page not found')

  return (
    <MessagePage title="Page not found">
      <p>The page you are looking for does not exist or has moved.</p>
    </MessagePage>
  )
}
