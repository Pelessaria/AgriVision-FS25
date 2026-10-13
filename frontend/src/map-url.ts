const MAP_PATH = "/feed/dedicated-server-stats-map.jpg"

function withPreviewSize(url: URL) {
  url.searchParams.set("size", "384")
  if (!url.searchParams.has("quality")) url.searchParams.set("quality", "45")
  return url.toString()
}

function fromBase(base: string, code: string) {
  const url = new URL(MAP_PATH, base.endsWith("/") ? base : `${base}/`)
  url.searchParams.set("code", code)
  url.searchParams.set("quality", "45")
  url.searchParams.set("size", "384")
  return url.toString()
}

export function mapPreviewUrl(feedUrl: string) {
  const envBase = import.meta.env.VITE_FS25_FEED_BASE?.trim() ?? ""
  const envCode = import.meta.env.VITE_FS25_FEED_CODE?.trim() ?? ""
  const fallback = envBase && envCode ? fromBase(envBase, envCode) : null
  const trimmed = feedUrl.trim()
  if (!trimmed) return fallback
  try {
    const url = new URL(trimmed)
    if (url.pathname.includes("dedicated-server-stats-map")) return withPreviewSize(url)
    const code = url.searchParams.get("code") || envCode
    if (!code) return fallback
    return fromBase(url.origin, code)
  } catch {
    return fallback
  }
}
