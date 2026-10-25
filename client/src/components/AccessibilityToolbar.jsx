import { useAccessibility } from '../context/useAccessibility.js'

/**
 * Shown on every page, including log in. It uses the look of the old floating zoom buttons (round, blue-grey, bottom right):
 * "+" and "-" change the text size, then reset, high contrast and readable font.
 */
export default function AccessibilityToolbar() {
  const a11y = useAccessibility()

  return (
    <div className="floating-buttons" role="group" aria-label="Accessibility options">
      <button type="button" className="fab fab-text" id="zoomInBtn" onClick={a11y.grow} disabled={!a11y.canGrow} aria-label="Make text larger" title="Larger text">
        +
      </button>
      <button type="button" className="fab fab-text" id="zoomOutBtn" onClick={a11y.shrink} disabled={!a11y.canShrink} aria-label="Make text smaller" title="Smaller text">
        &minus;
      </button>
      <button type="button" className="fab" onClick={a11y.resetText} disabled={a11y.textSize === 0} aria-label="Reset text size" title="Reset text size">
        <i className="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
      </button>
      <button type="button" className="fab" aria-pressed={a11y.highContrast} onClick={a11y.toggleContrast} aria-label="High contrast" title="High contrast">
        <i className="bi bi-circle-half" aria-hidden="true"></i>
      </button>
      <button type="button" className="fab" aria-pressed={a11y.readableFont} onClick={a11y.toggleReadableFont} aria-label="Readable font" title="Readable font">
        <i className="bi bi-fonts" aria-hidden="true"></i>
      </button>

      <p className="visually-hidden" role="status">
        Text size {a11y.textPercent} percent. High contrast {a11y.highContrast ? 'on' : 'off'}. Readable font {a11y.readableFont ? 'on' : 'off'}.
      </p>
    </div>
  )
}
