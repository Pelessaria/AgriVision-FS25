import { useParams } from "react-router-dom"
import { useStore } from "./store"

export function useWorkspaceRoute() {
  const { id = "" } = useParams()
  const store = useStore()
  const workspace = store.workspaces.find((item) => item.id === id) ?? null
  return {
    id,
    workspace,
    allowed: workspace ? store.canOpen(workspace.id) : false,
    manage: workspace ? store.canManageWorkspace(workspace.id) : false,
  }
}
