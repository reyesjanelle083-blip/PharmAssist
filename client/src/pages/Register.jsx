import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { fieldErrors, register } from '../api/index.js'
import ErrorMessage from '../components/ErrorMessage.jsx'
import Field from '../components/Field.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

/** The same rules as the gateway, checked here first so most mistakes are caught before anything is sent. */
function validate(values) {
  const errors = {}
  const email = values.email.trim()

  if (email === '' || email.length > 255 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errors.email = ['Please enter a valid email address.']
  }

  if (values.password.length < 8 || !/[A-Za-z]/.test(values.password) || !/[0-9]/.test(values.password)) {
    errors.password = ['Use at least 8 characters, with letters and numbers.']
  }

  if (values.first_name.trim() === '' || values.first_name.length > 100) {
    errors.first_name = ['Please enter your first name (up to 100 characters).']
  }

  if (values.last_name.trim() === '' || values.last_name.length > 100) {
    errors.last_name = ['Please enter your last name (up to 100 characters).']
  }

  if (!/^[0-9+\- ]{7,20}$/.test(values.contact_no.trim())) {
    errors.contact_no = ['Use 7 to 20 digits, spaces, "+" or "-". For example 0917 123 4567.']
  }

  return errors
}

export default function Register() {
  usePageTitle('Register')

  const [values, setValues] = useState({
    email: '',
    password: '',
    first_name: '',
    last_name: '',
    contact_no: '',
  })

  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)
  const summary = useRef(null)

  const set = (name) => (event) => {
    setValues((current) => ({
      ...current,
      [name]: event.target.value,
    }))
  }

  async function submit(event) {
    event.preventDefault()
    setError(null)

    const problems = validate(values)
    setErrors(problems)

    if (Object.keys(problems).length > 0) {
      requestAnimationFrame(() => summary.current?.focus())
      return
    }

    setBusy(true)

    try {
      await register({
        email: values.email.trim(),
        password: values.password,
        first_name: values.first_name.trim(),
        last_name: values.last_name.trim(),
        contact_no: values.contact_no.trim(),
      })

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
      <>
        <h2 className="login-title">Check your email</h2>
        <p className="notice">Your account has been created</p>

        <div className="app-notice app-notice--success" role="status">
          <p>
            Please verify your email using the 6-digit code we sent to{' '}
            <strong>{values.email.trim()}</strong>. Once verified, you can log in.
          </p>
        </div>

        <Link
          className="button-link"
          to="/verify-email"
          state={{ email: values.email.trim() }}
        >
          Go to Verify Email
        </Link>
      </>
    )
  }

  const problemCount = Object.keys(errors).length

  return (
    <div className="register-container" style={{ padding: 0 }}>
      <h2 className="login-title">Register</h2>
      <p className="notice">Create an account</p>

      <div ref={summary} tabIndex={-1}>
        <ErrorMessage
          error={error}
          message={
            error
              ? undefined
              : problemCount > 0
                ? 'Please fix the highlighted fields and try again.'
                : undefined
          }
        />
      </div>

      <form
        className="form-login"
        onSubmit={submit}
        noValidate
        aria-busy={busy}
      >
        <div className="name-row">
          <div className="name-col">
            <Field
              label="First Name"
              wrapperClass="input-first_name"
              errors={errors.first_name}
            >
              {(props) => (
                <input
                  {...props}
                  type="text"
                  name="first_name"
                  autoComplete="given-name"
                  maxLength={100}
                  placeholder="Enter your first name"
                  value={values.first_name}
                  onChange={set('first_name')}
                />
              )}
            </Field>
          </div>

          <div className="name-col">
            <Field
              label="Last Name"
              wrapperClass="input-last_name"
              errors={errors.last_name}
            >
              {(props) => (
                <input
                  {...props}
                  type="text"
                  name="last_name"
                  autoComplete="family-name"
                  maxLength={100}
                  placeholder="Enter your last name"
                  value={values.last_name}
                  onChange={set('last_name')}
                />
              )}
            </Field>
          </div>
        </div>

        <Field
          label="Email"
          wrapperClass="input-email"
          errors={errors.email}
        >
          {(props) => (
            <input
              {...props}
              type="email"
              name="email"
              autoComplete="email"
              maxLength={255}
              placeholder="Enter your email"
              value={values.email}
              onChange={set('email')}
            />
          )}
        </Field>

        <Field
          label="Contact Number"
          wrapperClass="input-username"
          errors={errors.contact_no}
        >
          {(props) => (
            <input
              {...props}
              type="text"
              inputMode="tel"
              name="contact_no"
              autoComplete="tel"
              maxLength={20}
              placeholder="Enter your contact number"
              value={values.contact_no}
              onChange={set('contact_no')}
            />
          )}
        </Field>

        <Field
          label="Password"
          wrapperClass="input-password"
          errors={errors.password}
          hint="Password must be at least 8 characters and include letters and numbers."
          after={
            <button
              type="button"
              className="toggle-password-btn"
              onClick={() => setShowPassword((value) => !value)}
              aria-pressed={showPassword}
              aria-label={showPassword ? 'Hide password' : 'Show password'}
            >
              <i
                className={showPassword ? 'far fa-eye-slash' : 'far fa-eye'}
                aria-hidden="true"
              ></i>
            </button>
          }
        >
          {(props) => (
            <input
              {...props}
              type={showPassword ? 'text' : 'password'}
              name="password"
              autoComplete="new-password"
              placeholder="Enter your password"
              value={values.password}
              onChange={set('password')}
            />
          )}
        </Field>

        <button
          type="submit"
          name="register"
          style={{ width: '100%' }}
          disabled={busy}
        >
          {busy ? 'Creating your account...' : 'Sign up'}
        </button>
      </form>

      <div
        className="signup-link"
        style={{ textAlign: 'center', margin: '10px 0 0 0' }}
      >
        <span style={{ fontSize: 12, color: '#666' }}>
          Already have an account?{' '}
          <Link
            to="/login"
            style={{
              display: 'inline',
              color: '#7393A7',
              textDecoration: 'none',
              fontSize: 12,
              margin: 0,
            }}
          >
            Log in
          </Link>
        </span>
      </div>
    </div>
  )
}