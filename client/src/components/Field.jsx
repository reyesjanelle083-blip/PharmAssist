import { useId } from 'react'

/**
 * A field of the old login and register pages: a label, then a rounded box (the wrapper class, for example "input-email")
 * with an optional icon, the input and an optional extra (the show-password eye), then the messages. The label is tied to the
 * input, and errors and hints are announced with it (aria-describedby, aria-invalid).
 * `children` is a function that receives the props to spread on the input.
 */
export default function Field({ label, wrapperClass = 'input-username', icon, hint, errors = [], after, children }) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = [hint ? hintId : null, errors.length > 0 ? errorId : null].filter(Boolean).join(' ') || undefined

  return (
    <>
      <label htmlFor={id}>{label}</label>
      <div className={wrapperClass}>
        {icon ? <i className={`${icon} icon`} aria-hidden="true"></i> : null}
        {children({ id, 'aria-describedby': describedBy, 'aria-invalid': errors.length > 0 ? 'true' : undefined })}
        {after}
      </div>
      {errors.length > 0 ? (
        <p id={errorId} className="field-error">
          {errors.join(' ')}
        </p>
      ) : null}
      {hint ? (
        <small id={hintId} className="field-hint">
          {hint}
        </small>
      ) : null}
    </>
  )
}
