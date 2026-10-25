import { useId } from 'react'

/**
 * A label tied to its input, with an optional hint and the error messages next to the field (aria-describedby, aria-invalid).
 * `children` is a function that receives the props to spread on the input.
 */
export default function Field({ label, hint, errors = [], children }) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = [hint ? hintId : null, errors.length > 0 ? errorId : null].filter(Boolean).join(' ') || undefined

  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      {hint ? (
        <p id={hintId} className="field__hint">
          {hint}
        </p>
      ) : null}
      {children({ id, 'aria-describedby': describedBy, 'aria-invalid': errors.length > 0 ? 'true' : undefined })}
      {errors.length > 0 ? (
        <p id={errorId} className="field__error">
          {errors.join(' ')}
        </p>
      ) : null}
    </div>
  )
}
