import { useCallback, useEffect, useMemo, useState } from 'react'
import { readJson, writeJson } from '../utils/storage.js'
import { AccessibilityContext, TEXT_SIZES } from './a11yContextObject.js'

const STORAGE_KEY = 'pharmassist.accessibility'

function prefersMoreContrast() {
  try {
    return window.matchMedia('(prefers-contrast: more)').matches
  } catch {
    return false
  }
}

function initialSettings() {
  const stored = readJson('local', STORAGE_KEY)
  const step = Number.isInteger(stored?.textSize) ? Math.min(TEXT_SIZES.length - 1, Math.max(0, stored.textSize)) : 0

  return {
    textSize: step,
    // Without a saved choice, follow the system setting (prefers-color-scheme is handled in the CSS itself).
    highContrast: typeof stored?.highContrast === 'boolean' ? stored.highContrast : prefersMoreContrast(),
    readableFont: stored?.readableFont === true,
  }
}

/**
 * Text size, high contrast and readable font. The choices are saved in localStorage and applied through data- attributes on
 * <html>; the CSS turns them into variables (see styles/accessibility.css).
 */
export function AccessibilityProvider({ children }) {
  const [settings, setSettings] = useState(initialSettings)

  useEffect(() => {
    const root = document.documentElement
    root.dataset.textSize = String(settings.textSize)
    root.dataset.contrast = settings.highContrast ? 'high' : 'normal'
    root.dataset.font = settings.readableFont ? 'readable' : 'normal'
    writeJson('local', STORAGE_KEY, settings)
  }, [settings])

  const update = useCallback((patch) => setSettings((current) => ({ ...current, ...patch })), [])

  const value = useMemo(
    () => ({
      ...settings,
      textPercent: TEXT_SIZES[settings.textSize],
      canGrow: settings.textSize < TEXT_SIZES.length - 1,
      canShrink: settings.textSize > 0,
      grow: () => setSettings((c) => ({ ...c, textSize: Math.min(TEXT_SIZES.length - 1, c.textSize + 1) })),
      shrink: () => setSettings((c) => ({ ...c, textSize: Math.max(0, c.textSize - 1) })),
      resetText: () => update({ textSize: 0 }),
      toggleContrast: () => setSettings((c) => ({ ...c, highContrast: !c.highContrast })),
      toggleReadableFont: () => setSettings((c) => ({ ...c, readableFont: !c.readableFont })),
    }),
    [settings, update],
  )

  return <AccessibilityContext.Provider value={value}>{children}</AccessibilityContext.Provider>
}
