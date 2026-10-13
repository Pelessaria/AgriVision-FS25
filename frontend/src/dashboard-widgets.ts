export const DASHBOARD_WIDGETS = [
  { id: "stats", labelKey: "dashboard.widgetStats", span: "full" },
  { id: "status", labelKey: "dashboard.widgetStatus", span: "half" },
  { id: "players", labelKey: "dashboard.widgetPlayers", span: "half" },
  { id: "attention", labelKey: "dashboard.widgetAttention", span: "full" },
] as const

export type DashboardWidgetId = (typeof DASHBOARD_WIDGETS)[number]["id"]

export interface DashboardLayoutItem {
  id: DashboardWidgetId
  visible: boolean
}

export type DashboardLayout = DashboardLayoutItem[]

export function defaultDashboardLayout(): DashboardLayout {
  return DASHBOARD_WIDGETS.map((widget) => ({ id: widget.id, visible: true }))
}

export function normalizeDashboardLayout(raw: unknown): DashboardLayout {
  const fallback = defaultDashboardLayout()
  if (!Array.isArray(raw)) return fallback
  const known = new Set(DASHBOARD_WIDGETS.map((widget) => widget.id))
  const seen = new Set<string>()
  const items: DashboardLayout = []
  for (const entry of raw) {
    if (!entry || typeof entry !== "object") continue
    const id = (entry as { id?: string }).id
    if (!id || !known.has(id as DashboardWidgetId) || seen.has(id)) continue
    seen.add(id)
    items.push({
      id: id as DashboardWidgetId,
      visible: (entry as { visible?: boolean }).visible !== false,
    })
  }
  for (const widget of fallback) {
    if (!seen.has(widget.id)) items.push(widget)
  }
  return items
}

export function layoutStorageKey(userId: string) {
  return `fs25.dashboard.layout.v1:${userId}`
}
