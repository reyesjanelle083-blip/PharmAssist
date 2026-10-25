import { Link } from 'react-router-dom'

const VARIANTS = { primary: 'ui-btn--primary', outline: 'ui-btn--outline', danger: 'ui-btn--danger' }

/**
 * The one button of the app. `variant` is primary (filled), outline or danger. With `to` it is a router link that looks the same.
 * Every button is at least 44 px high (--pa-control-height). Extra props (onClick, disabled, aria-*) go to the element.
 */
export default function Button({ variant = 'outline', block = false, to, type = 'button', className = '', children, ...rest }) {
  const classes = ['ui-btn', VARIANTS[variant] ?? VARIANTS.outline, block ? 'ui-btn--block' : '', className].filter(Boolean).join(' ')
  if (to) {
    return (
      <Link className={classes} to={to} {...rest}>
        {children}
      </Link>
    )
  }
  return (
    <button type={type} className={classes} {...rest}>
      {children}
    </button>
  )
}
