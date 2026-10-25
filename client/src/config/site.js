// The one place for the project's contact details. Replace a value, or leave it empty: the footer and the Contact dialog show a
// line or an icon only for the values that are set, so nothing ever points nowhere.
export const site = {
  name: 'PharmAssist',
  email: 'a.pharmasee@gmail.com',
  phone: '', // for example '(+63) 900 000 0000'
  social: {
    facebook: '', // a full https:// address
    instagram: '', // a full https:// address
  },
}

/** The social links that are actually set. */
export function socialLinks() {
  return [
    { key: 'facebook', label: 'Facebook', icon: 'bi bi-facebook', href: site.social.facebook },
    { key: 'instagram', label: 'Instagram', icon: 'bi bi-instagram', href: site.social.instagram },
  ].filter((link) => typeof link.href === 'string' && /^https:\/\//.test(link.href))
}
