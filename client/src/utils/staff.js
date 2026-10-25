export const ROLE_LABELS = {
  customer: 'Customer',
  pharmacy_technician: 'Pharmacy Technician',
  pharmacy_assistant: 'Pharmacy Assistant',
  branch_manager: 'Branch Manager',
  ceo: 'CEO',
}

export const STAFF_ROLES = ['pharmacy_technician', 'pharmacy_assistant', 'branch_manager', 'ceo']

export function isStaffRole(role) {
  return STAFF_ROLES.includes(role)
}

/**
 * Who may do what to a reservation. This mirrors reservation-service (ReservationStatusService::ACTIONS) so the page can hide buttons
 * a person could never use. It is a convenience only: the backend checks the role, the branch and the status on every request and
 * its 403/409 answers are what counts.
 */
export const RESERVATION_ACTION_ROLES = {
  approve: ['pharmacy_technician', 'branch_manager'],
  reject: ['pharmacy_technician', 'branch_manager'],
  complete: ['pharmacy_technician', 'pharmacy_assistant', 'branch_manager'],
}

/** The status a reservation must be in for the action (the backend refuses any other with 409 INVALID_TRANSITION). */
export const RESERVATION_ACTION_FROM = { approve: 'pending', reject: 'pending', complete: 'approved' }

export function mayDo(role, action) {
  return RESERVATION_ACTION_ROLES[action]?.includes(role) ?? false
}

/** The actions a role could take on a reservation in this status, in display order. */
export function actionsFor(role, status) {
  return Object.keys(RESERVATION_ACTION_ROLES).filter((action) => mayDo(role, action) && RESERVATION_ACTION_FROM[action] === status)
}
