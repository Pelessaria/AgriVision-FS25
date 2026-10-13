import type { AccessGroup } from "./types"
import { APP_MODULES } from "./modules"

const allWrite = APP_MODULES.map((module) => ({ moduleId: module.id, access: "write" as const }))
const allRead = APP_MODULES.map((module) => ({ moduleId: module.id, access: "read" as const }))

export const seedGroups: AccessGroup[] = [
  {
    id: "grp-admins",
    name: "Administratoren",
    description: "Volle Rechte in der Verwaltungsoberfläche, inkl. Benutzer und Gruppen.",
    system: true,
    grantsPlatformAdmin: true,
    workspaceRole: null,
    modules: [],
    workspaceIds: [],
    memberIds: ["mira"],
  },
  {
    id: "grp-ws-admin",
    name: "Workspace-Admins",
    description: "Volle Rechte in den zugewiesenen Workspaces, ohne die globale Verwaltung.",
    system: true,
    grantsPlatformAdmin: false,
    workspaceRole: "workspace_admin",
    modules: allWrite,
    workspaceIds: ["nord"],
    memberIds: [],
  },
  {
    id: "grp-managers",
    name: "Verwalter",
    description: "Workspace verwalten und Module freischalten, ohne Plattform-Admin zu sein.",
    system: true,
    grantsPlatformAdmin: false,
    workspaceRole: "manager",
    modules: allWrite,
    workspaceIds: ["sued"],
    memberIds: ["lea"],
  },
  {
    id: "grp-operators",
    name: "Mitarbeiter",
    description: "Operative Rechte in zugewiesenen Workspaces.",
    system: true,
    grantsPlatformAdmin: false,
    workspaceRole: "operator",
    modules: [
      { moduleId: "equipment", access: "write" },
      { moduleId: "fields", access: "read" },
      { moduleId: "storage", access: "read" },
      { moduleId: "finance", access: "write" },
    ],
    workspaceIds: ["nord"],
    memberIds: ["timo"],
  },
  {
    id: "grp-viewers",
    name: "Nur lesen",
    description: "Nur Ansicht, keine Schreibrechte.",
    system: true,
    grantsPlatformAdmin: false,
    workspaceRole: "viewer",
    modules: allRead,
    workspaceIds: ["nord"],
    memberIds: ["jonas"],
  },
]

export function emptyCustomGroup(): Omit<AccessGroup, "id" | "memberIds"> {
  return {
    name: "",
    description: "",
    system: false,
    grantsPlatformAdmin: false,
    workspaceRole: "viewer",
    modules: [],
    workspaceIds: [],
  }
}
