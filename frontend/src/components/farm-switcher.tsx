import { useEffect, useRef, useState } from "react"
import { useTranslation } from "react-i18next"
import { Check, ChevronDown } from "lucide-react"
import { farmDisplayName } from "../farm-label"
import type { Farm } from "../types"

export function FarmSwitcher({
  farms,
  farm,
  onSelect,
  collapsed,
  discovering,
}: {
  farms: Farm[]
  farm: Farm | null
  onSelect: (farmId: string) => void
  collapsed?: boolean
  discovering?: boolean
}) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const canSwitch = farms.length > 1

  useEffect(() => {
    if (!open) return
    function onPointer(event: PointerEvent) {
      if (rootRef.current?.contains(event.target as Node)) return
      setOpen(false)
    }
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") setOpen(false)
    }
    window.addEventListener("pointerdown", onPointer)
    window.addEventListener("keydown", onKey)
    return () => {
      window.removeEventListener("pointerdown", onPointer)
      window.removeEventListener("keydown", onKey)
    }
  }, [open])

  if (farms.length === 0) {
    return (
      <p className="farm-switch-empty">{discovering ? t("farm.discovering") : t("farm.none")}</p>
    )
  }

  if (!farm) return null

  const label = farmDisplayName(farm, t)

  return (
    <div className={open ? "farm-switch open" : "farm-switch"} ref={rootRef}>
      <button
        type="button"
        className="farm-switch-trigger"
        aria-label={t("farm.switch")}
        aria-haspopup="listbox"
        aria-expanded={open}
        disabled={!canSwitch}
        title={collapsed ? label : undefined}
        onClick={() => {
          if (!canSwitch) return
          setOpen((value) => !value)
        }}
      >
        <span className="ws-no">{farm.gameFarmId}</span>
        <span className="farm-switch-copy">
          <em>{t("farm.switch")}</em>
          <strong>{label}</strong>
        </span>
        {canSwitch ? <ChevronDown className="icon farm-switch-chevron" aria-hidden="true" /> : null}
      </button>
      {open ? (
        <ul className="farm-switch-menu" role="listbox" aria-label={t("farm.switch")}>
          {farms.map((item) => {
            const active = item.id === farm.id
            return (
              <li key={item.id} role="option" aria-selected={active}>
                <button
                  type="button"
                  className={active ? "farm-switch-option active" : "farm-switch-option"}
                  onClick={() => {
                    onSelect(item.id)
                    setOpen(false)
                  }}
                >
                  <span className="ws-no">{item.gameFarmId}</span>
                  <span className="farm-switch-option-copy">
                    <strong>{farmDisplayName(item, t)}</strong>
                    <span className="faint">{t("farm.savegameId", { id: item.gameFarmId })}</span>
                  </span>
                  {active ? <Check className="icon" aria-hidden="true" /> : null}
                </button>
              </li>
            )
          })}
        </ul>
      ) : null}
    </div>
  )
}
