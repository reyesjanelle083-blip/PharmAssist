import { Link, NavLink } from 'react-router-dom'

/** The little name tag that shows when the bar is closed (mouse only, so it is hidden from keyboards and screen readers). */
export function Tag({ label, to = '#' }) {
  return (
    <ul className="sub-menu blank" aria-hidden="true">
      <li>
        <a className="link_name" href={to} tabIndex={-1}>
          {label}
        </a>
      </li>
    </ul>
  )
}

/** One entry of the old sidebar: icon, link name, and the name tag. */
export function Item({ to, icon, label, end, plain }) {
  const content = (
    <>
      <i className={icon} aria-hidden="true"></i>
      <span className="link_name">{label}</span>
    </>
  )

  return (
    <li>
      {plain ? (
        <Link to={to}>{content}</Link>
      ) : (
        <NavLink to={to} end={end} className={({ isActive }) => (isActive ? 'is-current' : undefined)}>
          {content}
        </NavLink>
      )}
      <Tag label={label} to={typeof to === 'string' ? to : '#'} />
    </li>
  )
}
