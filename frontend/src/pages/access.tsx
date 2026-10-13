import { Navigate, NavLink, useLocation } from "react-router-dom"
import { useTranslation } from "react-i18next"
import { useStore } from "../store"
import { GroupsPanel } from "./groups"
import { UsersPanel } from "./users"

export function AccessPage() {
  const { t } = useTranslation()
  const store = useStore()
  const location = useLocation()
  const tab = location.pathname.includes("/groups") ? "groups" : "users"

  if (!store.canManageUsers()) return <Navigate to="/" replace />

  return (
    <section className="access-page">
      <header className="access-head">
        <div className="access-head-copy">
          <h1>{t("access.title")}</h1>
          <p>{t("access.lead")}</p>
        </div>
        <nav className="access-tabs" role="tablist" aria-label={t("access.title")}>
          <NavLink
            to="/access"
            end
            role="tab"
            aria-selected={tab === "users"}
            className={({ isActive }) => (isActive ? "access-tab active" : "access-tab")}
          >
            {t("access.tabUsers")}
          </NavLink>
          <NavLink
            to="/access/groups"
            role="tab"
            aria-selected={tab === "groups"}
            className={({ isActive }) => (isActive ? "access-tab active" : "access-tab")}
          >
            {t("access.tabGroups")}
          </NavLink>
        </nav>
      </header>
      <div className="access-body">{tab === "groups" ? <GroupsPanel /> : <UsersPanel />}</div>
    </section>
  )
}
