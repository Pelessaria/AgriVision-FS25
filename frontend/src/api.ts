import type { AppUser, Farm, Vehicle, Workspace } from "./types"

export class ApiError extends Error {
  status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name.replace(/[.$?*|{}()[\]\\/+^]/g, "\\$&")}=([^;]*)`))
  return match ? decodeURIComponent(match[1]) : null
}

async function ensureCsrf(): Promise<void> {
  await fetch("/api/csrf-cookie", {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
  })
}

export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? "GET").toUpperCase()
  if (method !== "GET" && method !== "HEAD") {
    await ensureCsrf()
  }

  const headers = new Headers(init.headers)
  headers.set("Accept", "application/json")
  if (!headers.has("Content-Type") && init.body) {
    headers.set("Content-Type", "application/json")
  }
  const xsrf = readCookie("XSRF-TOKEN")
  if (xsrf) headers.set("X-XSRF-TOKEN", xsrf)

  const response = await fetch(path, {
    ...init,
    credentials: "include",
    headers,
  })

  if (response.status === 204) {
    return undefined as T
  }

  const data = (await response.json().catch(() => ({}))) as Record<string, unknown>
  if (!response.ok) {
    const message =
      typeof data.message === "string"
        ? data.message
        : typeof data.error === "string"
          ? data.error
          : `HTTP ${response.status}`
    throw new ApiError(response.status, message)
  }

  return data as T
}

export async function apiMe(): Promise<AppUser | null> {
  try {
    const data = await apiFetch<{ user: AppUser }>("/api/me")
    return data.user
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null
    throw error
  }
}

export async function apiLogin(login: string, password: string): Promise<AppUser> {
  const data = await apiFetch<{ user: AppUser }>("/api/login", {
    method: "POST",
    body: JSON.stringify({ login, email: login, password }),
  })
  return data.user
}

export async function apiLogout(): Promise<void> {
  await apiFetch("/api/logout", { method: "POST", body: "{}" })
}

export async function apiWorkspaces(): Promise<Workspace[]> {
  const data = await apiFetch<{ workspaces: Workspace[] }>("/api/workspaces")
  return data.workspaces
}

export async function apiCreateWorkspace(name: string): Promise<{ workspace: Workspace; pairingCode: string }> {
  return apiFetch("/api/workspaces", {
    method: "POST",
    body: JSON.stringify({ name }),
  })
}

export async function apiUpdateWorkspace(
  id: string,
  patch: {
    name?: string
    disabled?: boolean
    feedUrl?: string | null
    keepCode?: boolean
    sync?: boolean
  },
): Promise<{ workspace: Workspace; ok: boolean; error?: string }> {
  return apiFetch(`/api/workspaces/${encodeURIComponent(id)}`, {
    method: "PATCH",
    body: JSON.stringify(patch),
  })
}

export async function apiSyncWorkspace(
  id: string,
  options?: { light?: boolean },
): Promise<{ workspace: Workspace; ok: boolean; error?: string }> {
  const light = options?.light !== false
  return apiFetch(`/api/workspaces/${encodeURIComponent(id)}/sync?light=${light ? "1" : "0"}`, {
    method: "POST",
    body: "{}",
  })
}

export async function apiWorkspaceVehicles(
  id: string,
): Promise<{
  ok: boolean
  error?: string | null
  fetchedAt?: string | null
  vehicles: Vehicle[]
  farms: Farm[]
  workspace?: Workspace
}> {
  return apiFetch(`/api/workspaces/${encodeURIComponent(id)}/vehicles`)
}

export async function apiWorkspaceFarms(
  id: string,
  options?: { sync?: boolean },
): Promise<{ farms: Farm[]; workspace?: Workspace }> {
  const sync = options?.sync ? "?sync=1" : ""
  return apiFetch(`/api/workspaces/${encodeURIComponent(id)}/farms${sync}`)
}

/** Probe whether the Laravel API is reachable (not just Vite demo). */
export async function apiIsLive(): Promise<boolean> {
  try {
    const response = await fetch("/api/csrf-cookie", {
      method: "GET",
      credentials: "include",
      headers: { Accept: "application/json" },
    })
    return response.ok || response.status === 204
  } catch {
    return false
  }
}
