/** Public GitHub source of truth for AgriVision installs and in-app updates. */
export const GITHUB_OWNER = "Pelessaria"
export const GITHUB_REPO = "AgriVision-FS25"
export const GITHUB_BRANCH = "main"
export const GITHUB_REPO_URL = `https://github.com/${GITHUB_OWNER}/${GITHUB_REPO}`

export type UpdateStatus = {
  enabled: boolean
  installedVersion: string
  installedSha: string | null
  installedAt: string | null
  repo: string
  branch: string
  remoteSha: string | null
  remoteMessage: string | null
  remoteHtmlUrl: string | null
  updateAvailable: boolean
  checkedAt: string
  source: "api" | "github" | "demo"
  error?: string
}

function shortSha(sha: string | null | undefined) {
  if (!sha) return null
  return sha.slice(0, 7)
}

async function fetchGithubHead(): Promise<{
  sha: string
  message: string | null
  htmlUrl: string | null
}> {
  const response = await fetch(
    `https://api.github.com/repos/${GITHUB_OWNER}/${GITHUB_REPO}/commits/${GITHUB_BRANCH}`,
    {
      headers: {
        Accept: "application/vnd.github+json",
        "User-Agent": "AgriVision-UI",
      },
    },
  )
  if (!response.ok) {
    throw new Error(`GitHub HTTP ${response.status}`)
  }
  const json = (await response.json()) as {
    sha?: string
    html_url?: string
    commit?: { message?: string }
  }
  const sha = json.sha ?? ""
  if (!sha) throw new Error("GitHub response missing SHA")
  const raw = json.commit?.message?.trim() ?? null
  const message = raw ? raw.split("\n")[0] ?? raw : null
  return { sha, message, htmlUrl: json.html_url ?? null }
}

/**
 * Prefer Laravel `/api/system/update` when the backend is reachable.
 * Fall back to the public GitHub API so the settings UI works in the Vite prototype.
 */
export async function checkForUpdates(installedVersion: string, installedSha: string | null): Promise<UpdateStatus> {
  const checkedAt = new Date().toISOString()

  try {
    const response = await fetch("/api/system/update", { headers: { Accept: "application/json" } })
    if (response.ok) {
      const json = (await response.json()) as Omit<UpdateStatus, "source">
      return { ...json, source: "api" }
    }
  } catch {
    // Prototype / separate Vite origin — use GitHub directly.
  }

  try {
    const head = await fetchGithubHead()
    const updateAvailable = !installedSha || installedSha !== head.sha
    return {
      enabled: true,
      installedVersion,
      installedSha,
      installedAt: null,
      repo: `${GITHUB_OWNER}/${GITHUB_REPO}`,
      branch: GITHUB_BRANCH,
      remoteSha: head.sha,
      remoteMessage: head.message,
      remoteHtmlUrl: head.htmlUrl,
      updateAvailable,
      checkedAt,
      source: "github",
    }
  } catch (error) {
    return {
      enabled: true,
      installedVersion,
      installedSha,
      installedAt: null,
      repo: `${GITHUB_OWNER}/${GITHUB_REPO}`,
      branch: GITHUB_BRANCH,
      remoteSha: null,
      remoteMessage: null,
      remoteHtmlUrl: null,
      updateAvailable: false,
      checkedAt,
      source: "demo",
      error: error instanceof Error ? error.message : "Update check failed",
    }
  }
}

export async function applyUpdate(sha: string | null): Promise<{ ok: boolean; error?: string; message?: string }> {
  try {
    const response = await fetch("/api/system/update", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ confirm: true, sha: sha ?? undefined }),
    })
    const json = (await response.json()) as { ok?: boolean; error?: string; message?: string }
    if (!response.ok || json.ok === false) {
      return { ok: false, error: json.error ?? `HTTP ${response.status}` }
    }
    return { ok: true, message: json.message }
  } catch {
    return {
      ok: false,
      error: "backend_unavailable",
    }
  }
}

export function formatSha(sha: string | null | undefined) {
  return shortSha(sha) ?? "—"
}
