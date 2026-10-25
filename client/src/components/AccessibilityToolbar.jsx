import { useAccessibility } from '../context/useAccessibility.js'

/** Shown on every page, including log in. Text size, high contrast and a more readable font. */
export default function AccessibilityToolbar() {
  const a11y = useAccessibility()

  return (
    <div className="a11y-bar">
      <div className="a11y-toolbar" role="group" aria-label="Accessibility options">
        <div className="a11y-group" role="group" aria-label="Text size">
          <button type="button" className="a11y-button" onClick={a11y.shrink} disabled={!a11y.canShrink} aria-label="Make text smaller">
            A-
          </button>
          <button type="button" className="a11y-button" onClick={a11y.grow} disabled={!a11y.canGrow} aria-label="Make text larger">
            A+
          </button>
          <button type="button" className="a11y-button" onClick={a11y.resetText} disabled={a11y.textSize === 0} aria-label="Reset text size">
            Reset
          </button>
          <span className="a11y-size">{a11y.textPercent}%</span>
        </div>

        <button type="button" className="a11y-button" aria-pressed={a11y.highContrast} onClick={a11y.toggleContrast}>
          High contrast
        </button>
        <button type="button" className="a11y-button" aria-pressed={a11y.readableFont} onClick={a11y.toggleReadableFont}>
          Readable font
        </button>
      </div>
      <p className="visually-hidden" role="status">
        Text size {a11y.textPercent} percent. High contrast {a11y.highContrast ? 'on' : 'off'}. Readable font {a11y.readableFont ? 'on' : 'off'}.
      </p>
    </div>
  )
}
