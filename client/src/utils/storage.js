// Browser storage can be missing or throw (private windows, blocked site data). Every access is wrapped, and the app works without it.

function area(kind) {
  try {
    return kind === 'session' ? window.sessionStorage : window.localStorage
  } catch {
    return null
  }
}

export function readJson(kind, key) {
  try {
    const raw = area(kind)?.getItem(key)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export function writeJson(kind, key, value) {
  try {
    area(kind)?.setItem(key, JSON.stringify(value))
  } catch {
    // storage unavailable or full: carry on without it
  }
}

export function remove(kind, key) {
  try {
    area(kind)?.removeItem(key)
  } catch {
    // ignore
  }
}
