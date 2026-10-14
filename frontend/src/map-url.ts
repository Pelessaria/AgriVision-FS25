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

/**
 * Prefer the authenticated Laravel map proxy (no feed code in the browser).
 * Fall back to direct Giants URL only in the Vite demo when a full feed URL+code is present.
 */
export function mapPreviewUrl(workspace: {
  feedUrl?: string
  mapPreviewPath?: string | null
  feedConfigured?: boolean
}) {
  if (workspace.mapPreviewPath) {
    return workspace.mapPreviewPath
  }

  const envBase = import.meta.env.VITE_FS25_FEED_BASE?.trim() ?? ""
  const envCode = import.meta.env.VITE_FS25_FEED_CODE?.trim() ?? ""
  const fallback = envBase && envCode ? fromBase(envBase, envCode) : null
  const trimmed = (workspace.feedUrl ?? "").trim()
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
