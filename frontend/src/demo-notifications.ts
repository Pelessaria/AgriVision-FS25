export type AppNotification = {
  id: string
  workspaceId: string | null
  tone: "info" | "warn" | "ok"
  titleKey: string
  bodyKey: string
  at: string
  read: boolean
}

export const seedNotifications: AppNotification[] = [
  {
    id: "n1",
    workspaceId: "nord",
    tone: "warn",
    titleKey: "notify.staleTitle",
    bodyKey: "notify.staleBody",
    at: "2026-10-12T00:05:00.000Z",
    read: false,
  },
  {
    id: "n2",
    workspaceId: "sued",
    tone: "info",
    titleKey: "notify.offlineTitle",
    bodyKey: "notify.offlineBody",
    at: "2026-10-11T21:40:00.000Z",
    read: false,
  },
  {
    id: "n3",
    workspaceId: null,
    tone: "ok",
    titleKey: "notify.demoTitle",
    bodyKey: "notify.demoBody",
    at: "2026-10-11T18:00:00.000Z",
    read: true,
  },
]
