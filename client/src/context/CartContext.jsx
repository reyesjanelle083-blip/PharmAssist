import { useCallback, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import ConfirmDialog from '../components/ConfirmDialog.jsx'
import { readJson, remove, writeJson } from '../utils/storage.js'
import { lineFromResult } from '../utils/cart.js'
import { toCents } from '../utils/format.js'
import { CartContext, MAX_LINES, MAX_QUANTITY } from './cartContextObject.js'
import { useAuth } from './useAuth.js'

const STORAGE_KEY = 'pharmassist.cart'
const EMPTY = { branchId: null, branchName: '', items: [] }

function cleanCart(stored, userId) {
  if (!stored || stored.userId !== userId || !Array.isArray(stored.items)) return EMPTY
  const items = stored.items
    .filter((i) => Number.isInteger(i.medicineId) && typeof i.name === 'string' && Number.isInteger(i.quantity) && Number.isInteger(i.available))
    .slice(0, MAX_LINES)
  return items.length > 0 && Number.isInteger(stored.branchId) ? { branchId: stored.branchId, branchName: String(stored.branchName ?? ''), items } : EMPTY
}

/**
 * The reservation cart: one branch at a time, up to 10 different medicines, quantity 1 to 20 and never above what the search showed
 * as available. It is kept in localStorage (per logged-in customer) so a reload or a login in between does not lose it.
 * Adding something from another branch asks first (an accessible confirm dialog).
 */
export function CartProvider({ children }) {
  const { user } = useAuth()
  const navigate = useNavigate()
  const userId = user?.id ?? null
  const [cart, setCart] = useState(() => (userId === null ? EMPTY : cleanCart(readJson('local', STORAGE_KEY), userId)))
  const [pending, setPending] = useState(null) // { row, go } waiting for "replace the cart?"
  const [notice, setNotice] = useState('')

  // when a different person logs in (or out), switch to their cart: nothing for a guest
  const [cartOwner, setCartOwner] = useState(userId)
  if (cartOwner !== userId) {
    setCartOwner(userId)
    setCart(userId === null ? EMPTY : cleanCart(readJson('local', STORAGE_KEY), userId))
  }

  const persist = useCallback(
    (next) => {
      setCart(next)
      if (userId === null) return
      if (next.items.length === 0) remove('local', STORAGE_KEY)
      else writeJson('local', STORAGE_KEY, { userId, ...next })
    },
    [userId],
  )

  const put = useCallback(
    (row, base, go) => {
      const line = lineFromResult(row)
      const existing = base.items.find((i) => i.medicineId === line.medicineId)
      let items
      if (existing) {
        const quantity = Math.min(MAX_QUANTITY, existing.available, existing.quantity + 1)
        items = base.items.map((i) => (i === existing ? { ...i, quantity, available: line.available, price: line.price } : i))
        setNotice(quantity === existing.quantity ? `${line.name} is already at the most you can reserve (${quantity}).` : `${line.name} quantity is now ${quantity}.`)
      } else if (base.items.length >= MAX_LINES) {
        setNotice(`A reservation can hold up to ${MAX_LINES} different medicines. Remove one to add another.`)
        items = base.items
      } else {
        items = [...base.items, line]
        setNotice(`${line.name} was added to your cart.`)
      }
      persist({ branchId: row.branch_id, branchName: row.branch_name, items })
      if (go) navigate('/cart')
    },
    [persist, navigate],
  )

  /** Add one of a search result (or reservation redirect) row. `go` opens the cart afterwards. */
  const add = useCallback(
    (row, { go = false } = {}) => {
      if (row.available_quantity < 1) {
        setNotice(`${row.medicine_name} is out of stock at ${row.branch_name}.`)
        return
      }
      if (cart.items.length > 0 && cart.branchId !== row.branch_id) {
        setPending({ row, go })
        return
      }
      put(row, cart.branchId === row.branch_id ? cart : EMPTY, go)
    },
    [cart, put],
  )

  const setQuantity = useCallback(
    (medicineId, quantity) =>
      persist({
        ...cart,
        items: cart.items.map((i) => (i.medicineId === medicineId ? { ...i, quantity: Math.max(1, Math.min(MAX_QUANTITY, i.available, quantity)) } : i)),
      }),
    [cart, persist],
  )

  const removeLine = useCallback(
    (medicineId) => persist({ ...cart, items: cart.items.filter((i) => i.medicineId !== medicineId) }),
    [cart, persist],
  )

  /** After a STOCK_INSUFFICIENT answer: update what is really available (0 removes the line). Quantities above it are lowered. */
  const applyAvailability = useCallback(
    (list) => {
      const byId = new Map(list.map((s) => [s.medicineId, s.available]))
      const items = cart.items
        .map((i) => (byId.has(i.medicineId) ? { ...i, available: byId.get(i.medicineId), quantity: Math.min(i.quantity, byId.get(i.medicineId)) } : i))
        .filter((i) => i.available > 0)
      persist({ ...cart, items })
    },
    [cart, persist],
  )

  /** The server says these medicines need a prescription (our copy of the flag was out of date). */
  const markPrescription = useCallback(
    (ids) => persist({ ...cart, items: cart.items.map((i) => (ids.includes(i.medicineId) ? { ...i, requiresPrescription: true } : i)) }),
    [cart, persist],
  )

  const clear = useCallback(() => persist(EMPTY), [persist])

  const value = useMemo(() => {
    const totalCents = cart.items.reduce((sum, i) => sum + toCents(i.price) * i.quantity, 0)
    return {
      ...cart,
      count: cart.items.length,
      totalCents,
      needsPrescription: cart.items.some((i) => i.requiresPrescription),
      notice,
      clearNotice: () => setNotice(''),
      add,
      setQuantity,
      removeLine,
      applyAvailability,
      markPrescription,
      clear,
    }
  }, [cart, notice, add, setQuantity, removeLine, applyAvailability, markPrescription, clear])

  return (
    <CartContext.Provider value={value}>
      {children}
      <div className="legacy-cart">
      <ConfirmDialog
        open={pending !== null}
        title="Start a new cart?"
        confirmLabel="Replace my cart"
        cancelLabel="Keep my cart"
        onCancel={() => setPending(null)}
        onConfirm={() => {
          const next = pending
          setPending(null)
          put(next.row, EMPTY, next.go)
        }}
      >
        <p>
          A reservation is for one branch only. Your cart has items from <strong>{cart.branchName}</strong>. To reserve at{' '}
          <strong>{pending?.row.branch_name}</strong>, your current cart must be replaced.
        </p>
      </ConfirmDialog>
      </div>
    </CartContext.Provider>
  )
}
