import { createContext } from 'react'

export const AccessibilityContext = createContext(null)

/** Root font size in steps, as percentages of the browser's own size (100% is normally 16 px). */
export const TEXT_SIZES = [100, 112, 125, 150, 175]
