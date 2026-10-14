export type Locale = "de" | "en"

export type LinkState = "online" | "offline" | "unpaired" | "unknown" | "error"

export type WorkspaceRole = "workspace_admin" | "manager" | "operator" | "viewer"

export type ModuleAccess = "read" | "write"

export type GlobalRole = "admin" | "member"

export type VehicleCategory = "tractor" | "harvester" | "trailer" | "tool"

export interface LocalizedText {
  de: string
  en: string
}

export interface Membership {
  workspaceId: string
  role: WorkspaceRole
  modules: ModuleGrant[]
  /** Savegame farm IDs; omit or null = all farms in the workspace. */
  farmIds?: number[] | null
}

export interface ModuleGrant {
  moduleId: string
  access: ModuleAccess
}

export interface AppUser {
  id: string
  name: string
  username?: string | null
  email: string
  firstName: string
  lastName: string
  alias: string
  notificationEmail: string
  avatarDataUrl: string | null
  twoFactorEnabled: boolean
  globalRole: GlobalRole
  active: boolean
  memberships: Membership[]
  groupIds: string[]
}

export type MailEncryption = "none" | "starttls" | "tls"

export interface MailSettings {
  host: string
  port: number
  encryption: MailEncryption
  username: string
  password: string
  fromEmail: string
  fromName: string
}

export interface AccessGroup {
  id: string
  name: string
  description: string
  system: boolean
  grantsPlatformAdmin: boolean
  workspaceRole: WorkspaceRole | null
  modules: ModuleGrant[]
  workspaceIds: string[]
  memberIds: string[]
}

export interface Workspace {
  id: string
  number: number
  name: string
  linkState: LinkState
  disabled: boolean
  mapName: string | null
  playersOnline: number | null
  playerCapacity: number | null
  gameTime: string | null
  lastSeenAt: string | null
  lastSyncAt: string | null
  connectorVersion: string | null
  notice: "unpaired" | "stale" | "feed" | "disabled" | null
  feedUrl: string
}

/** In-game farm (Hof) inside a gameserver workspace. */
export interface Farm {
  id: string
  workspaceId: string
  /** Savegame farm ID (1, 2, …). */
  gameFarmId: number
  name: string
  money: number | null
  hectares: number | null
  animals: number | null
  storageFill: number | null
}

export interface Vehicle {
  id: string
  workspaceId: string
  name: string
  category: VehicleCategory
  farmId: number | null
  /** Resolved icon URL after backend maps modKey → stored icon; null = placeholder. */
  iconUrl: string | null
  /** Stable mod key from connector / feed hash for icon assignment. */
  modKey?: string | null
  x: number | null
  y: number | null
  z: number | null
  fill: LocalizedText | null
  hours: number | null
  observedAt: string
}

export interface ActivityItem {
  id: string
  workspaceId: string
  key: "sync" | "pair" | "stale"
  at: string
}

export interface ToastItem {
  id: string
  tone: "ok" | "warn" | "danger"
  text: string
}
