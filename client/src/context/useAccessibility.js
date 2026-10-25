import { useContext } from 'react'
import { AccessibilityContext } from './a11yContextObject.js'

export function useAccessibility() {
  const value = useContext(AccessibilityContext)
  if (value === null) throw new Error('useAccessibility must be used inside <AccessibilityProvider>')
  return value
}
