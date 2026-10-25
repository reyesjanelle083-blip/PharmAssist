import MessagePage from '../components/MessagePage.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

export default function Forbidden() {
  usePageTitle('No access')

  return (
    <MessagePage title="You do not have access to this page">
      <p>Your account does not have permission to open it (error 403). If you think this is a mistake, please contact the pharmacy.</p>
    </MessagePage>
  )
}
