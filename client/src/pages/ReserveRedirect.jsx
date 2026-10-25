import { useEffect, useRef, useState } from 'react'
import LoadingState from '../components/ui/LoadingState.jsx'
import { Link, Navigate, useSearchParams } from 'react-router-dom'
import { searchAvailability } from '../api/index.js'
import ErrorMessage from '../components/ErrorMessage.jsx'
import MessagePage from '../components/MessagePage.jsx'
import { useCart } from '../context/useCart.js'
import { usePageTitle } from '../hooks/usePageTitle.js'

/**
 * /reserve?branch=ID&medicine=ID (the link a guest returns to after logging in, or any saved link): looks the medicine up at that
 * branch, adds it to the cart and opens the cart.
 */
export default function ReserveRedirect() {
  usePageTitle('Reserve')
  const [params] = useSearchParams()
  const cart = useCart()
  const [state, setState] = useState({ phase: 'loading' })
  const started = useRef(false)
  const branch = Number(params.get('branch'))
  const medicine = Number(params.get('medicine'))
  const valid = Number.isInteger(branch) && branch > 0 && Number.isInteger(medicine) && medicine > 0

  useEffect(() => {
    if (!valid || started.current) return
    started.current = true // React runs effects twice in development: add the item once

    searchAvailability({ medicineId: medicine, limit: 20 })
      .then(({ data }) => {
        const row = (data.items ?? []).find((item) => item.branch_id === branch && item.medicine_id === medicine)
        if (!row) {
          setState({ phase: 'missing' })
          return
        }
        cart.add(row, { go: true })
        setState({ phase: 'added' })
      })
      .catch((error) => setState({ phase: 'error', error }))
    // runs once for this link
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (!valid) return <Navigate to="/" replace />

  if (state.phase === 'error') {
    return (
      <MessagePage title="We could not add it to your cart" linkText="Back to the search">
        <ErrorMessage error={state.error} />
      </MessagePage>
    )
  }
  if (state.phase === 'missing') {
    return (
      <MessagePage title="Not in stock at this branch" linkText="Back to the search">
        <p>That medicine is no longer in stock at the branch you chose. Please search again.</p>
      </MessagePage>
    )
  }

  return (
    <LoadingState>
      {state.phase === 'added' ? (
        <>
          Your cart is ready. <Link to="/cart">Open the cart</Link>
        </>
      ) : (
        'Adding to your cart...'
      )}
    </LoadingState>
  )
}
