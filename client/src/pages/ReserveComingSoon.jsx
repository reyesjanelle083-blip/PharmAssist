import MessagePage from '../components/MessagePage.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

/** Placeholder: the reservation form comes in the next step. */
export default function ReserveComingSoon() {
  usePageTitle('Reserve')

  return (
    <MessagePage title="Reserving is coming soon" linkText="Back to the search">
      <p>You will be able to reserve this medicine here very soon. Nothing has been reserved.</p>
    </MessagePage>
  )
}
