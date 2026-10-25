import { createContext } from 'react'

export const CartContext = createContext(null)

/** Limits of one reservation (the same as the gateway and reservation-service). */
export const MAX_LINES = 10
export const MAX_QUANTITY = 20
