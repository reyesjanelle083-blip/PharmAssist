import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { fieldErrors, register } from '../api/index.js'
import ErrorMessage from '../components/ErrorMessage.jsx'
import Field from '../components/Field.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

const MAX_PHOTO_BYTES = 2 * 1024 * 1024
const PHOTO_TYPES = ['image/jpeg', 'image/png']

/** The same rules as the gateway, checked here first so most mistakes are caught before anything is sent. */
function validate(values, photo) {
  const errors = {}
  const email = values.email.trim()

  if (email === '' || email.length > 255 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = ['Please enter a valid email address.']
  if (values.password.length < 8 || !/[A-Za-z]/.test(values.password) || !/[0-9]/.test(values.password)) {
    errors.password = ['Use at least 8 characters, with letters and numbers.']
  }
  if (values.first_name.trim() === '' || values.first_name.length > 100) errors.first_name = ['Please enter your first name (up to 100 characters).']
  if (values.last_name.trim() === '' || values.last_name.length > 100) errors.last_name = ['Please enter your last name (up to 100 characters).']
  if (!/^[0-9+\- ]{7,20}$/.test(values.contact_no.trim())) errors.contact_no = ['Use 7 to 20 digits, spaces, "+" or "-". For example 0917 123 4567.']

  if (!photo) {
    errors.id_photo = ['Please choose a photo of your ID.']
  } else if (!PHOTO_TYPES.includes(photo.type)) {
    errors.id_photo = ['The photo must be a JPG or PNG picture.']
  } else if (photo.size > MAX_PHOTO_BYTES) {
    errors.id_photo = ['The photo must be 2 MB or smaller.']
  }

  return errors
}

export default function Register() {
  usePageTitle('Register')
  const [values, setValues] = useState({ email: '', password: '', first_name: '', last_name: '', contact_no: '' })
  const [photo, setPhoto] = useState(null)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)
  const summary = useRef(null)

  const set = (name) => (event) => setValues((current) => ({ ...current, [name]: event.target.value }))

  async function submit(event) {
    event.preventDefault()
    setError(null)

    const problems = validate(values, photo)
    setErrors(problems)
    if (Object.keys(problems).length > 0) {
      requestAnimationFrame(() => summary.current?.focus())
      return
    }

    setBusy(true)
    try {
      await register({ ...values, email: values.email.trim(), first_name: values.first_name.trim(), last_name: values.last_name.trim(), contact_no: values.contact_no.trim(), id_photo: photo })
      setDone(true)
    } catch (caught) {
      setError(caught)
      setErrors(fieldErrors(caught))
      requestAnimationFrame(() => summary.current?.focus())
    } finally {
      setBusy(false)
    }
  }

  if (done) {
    return (
      <div className="form-page">
        <h1>Check your email</h1>
        <div className="notice notice--success" role="status">
          <p>
            Your account has been created, but you cannot use it yet. Two steps are left:
          </p>
          <ol>
            <li>
              <strong>Verify your email.</strong> We sent a 6-digit code to {values.email.trim()}. Enter it on the verification page.
            </li>
            <li>
              <strong>Wait for approval.</strong> The pharmacy administrator checks your ID and approves your account. You can log in after that.
            </li>
          </ol>
        </div>
        <p>
          <Link className="button" to="/verify-email" state={{ email: values.email.trim() }}>
            Go to Verify Email
          </Link>
        </p>
      </div>
    )
  }

  const problemList = Object.values(errors).flat()

  return (
    <div className="form-page">
      <h1>Create an account</h1>
      <p>You need an account to reserve a medicine. Searching does not need one.</p>

      <div ref={summary} tabIndex={-1} className="form-summary">
        <ErrorMessage error={error} message={error ? undefined : problemList.length > 0 ? 'Please fix the highlighted fields and try again.' : undefined} />
      </div>

      <form onSubmit={submit} noValidate className="form" aria-busy={busy}>
        <Field label="Email address" errors={errors.email}>
          {(props) => <input {...props} type="email" name="email" autoComplete="email" maxLength={255} value={values.email} onChange={set('email')} />}
        </Field>
        <Field label="Password" hint="At least 8 characters, with letters and numbers." errors={errors.password}>
          {(props) => <input {...props} type="password" name="password" autoComplete="new-password" value={values.password} onChange={set('password')} />}
        </Field>
        <Field label="First name" errors={errors.first_name}>
          {(props) => <input {...props} type="text" name="first_name" autoComplete="given-name" maxLength={100} value={values.first_name} onChange={set('first_name')} />}
        </Field>
        <Field label="Last name" errors={errors.last_name}>
          {(props) => <input {...props} type="text" name="last_name" autoComplete="family-name" maxLength={100} value={values.last_name} onChange={set('last_name')} />}
        </Field>
        <Field label="Contact number" errors={errors.contact_no}>
          {(props) => <input {...props} type="tel" name="contact_no" autoComplete="tel" maxLength={20} value={values.contact_no} onChange={set('contact_no')} />}
        </Field>
        <Field label="Photo of your ID (JPG or PNG, up to 2 MB)" hint="The ID photo is used only to verify your identity. It is deleted after the administrator has made a decision." errors={errors.id_photo}>
          {(props) => <input {...props} type="file" name="id_photo" accept="image/jpeg,image/png" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />}
        </Field>

        <button type="submit" className="button button--block" disabled={busy}>
          {busy ? 'Creating your account...' : 'Create account'}
        </button>
      </form>

      <p className="form-page__links">
        Already have an account? <Link to="/login">Log in</Link>
      </p>
    </div>
  )
}
