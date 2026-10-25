export const STATUS_LABELS = {
  pending: 'Pending',
  approved: 'Approved',
  completed: 'Completed',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
  expired: 'Expired',
}

export const STATUS_OPTIONS = Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label }))

/** Statuses a reservation can still change out of (and whose claim code can still be used at the branch). */
export const ACTIVE_STATUSES = ['pending', 'approved']

export function isActiveStatus(status) {
  return ACTIVE_STATUSES.includes(status)
}

/** One plain sentence per real backend status: what it means for the customer. */
export const STATUS_HELP = {
  pending: 'The branch has not reviewed this yet. The medicines are held for you in the meantime.',
  approved: 'The branch approved this reservation. Show the claim code at the branch and pay there.',
  completed: 'This reservation was picked up and completed.',
  rejected: 'The branch could not accept this reservation. The medicines held for it went back on the shelf.',
  cancelled: 'This reservation was cancelled. The medicines held for it went back on the shelf.',
  expired: 'The hold ran out before pickup, so the medicines went back on the shelf. Reserve again if you still need them.',
}
