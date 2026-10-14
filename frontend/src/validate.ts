export function isEmail(value: string) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())
}

/** Email or username (letters, digits, . _ -). */
export function isLoginId(value: string) {
  const trimmed = value.trim()
  if (trimmed.length < 2 || trimmed.length > 255) return false
  if (isEmail(trimmed)) return true
  return /^[a-zA-Z0-9._-]+$/.test(trimmed)
}

export function isFeedUrl(value: string) {
  const trimmed = value.trim()
  if (!trimmed) return true
  try {
    const url = new URL(trimmed)
    return url.protocol === "http:" || url.protocol === "https:"
  } catch {
    return false
  }
}

/** Full feed URL with ?code=…, or empty (clear). */
export function isFeedUrlWithCode(value: string) {
  const trimmed = value.trim()
  if (!trimmed) return true
  if (!isFeedUrl(trimmed)) return false
  try {
    return new URL(trimmed).searchParams.has("code")
  } catch {
    return false
  }
}
