import { useContext } from 'react'
import { CartContext } from './cartContextObject.js'

export function useCart() {
  const value = useContext(CartContext)
  if (value === null) throw new Error('useCart must be used inside <CartProvider>')
  return value
}
