import { useAccessibility } from '../context/useAccessibility.js'

export default function AccessibilityToolbar({
  className = '',
}) {
  const a11y =
    useAccessibility()

  return (
    <aside
      className={`floating-buttons ${className}`.trim()}
      aria-label="Accessibility options"
    >
      <button
        type="button"
        className="fab fab-text"
        id="zoomInBtn"
        onClick={a11y.grow}
        disabled={!a11y.canGrow}
        aria-label="Make text larger"
        title="Larger text"
      >
        +
      </button>

      <button
        type="button"
        className="fab fab-text"
        id="zoomOutBtn"
        onClick={a11y.shrink}
        disabled={!a11y.canShrink}
        aria-label="Make text smaller"
        title="Smaller text"
      >
        &minus;
      </button>

      <button
        type="button"
        className="fab"
        onClick={
          a11y.resetText
        }
        disabled={
          a11y.textSize === 0
        }
        aria-label="Reset text size"
        title="Reset text size"
      >
        <i
          className="bi bi-arrow-counterclockwise"
          aria-hidden="true"
        ></i>
      </button>

      <button
        type="button"
        className="fab"
        onClick={
          a11y.toggleContrast
        }
        aria-pressed={
          a11y.highContrast
        }
        aria-label="Toggle high contrast"
        title="High contrast"
      >
        <i
          className="bi bi-circle-half"
          aria-hidden="true"
        ></i>
      </button>

      <button
        type="button"
        className="fab fab-text"
        onClick={
          a11y.toggleReadable
        }
        aria-pressed={
          a11y.readableFont
        }
        aria-label="Toggle readable font"
        title="Readable font"
      >
        T
      </button>
    </aside>
  )
}