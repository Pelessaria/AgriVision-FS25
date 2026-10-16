import type { Vehicle } from "../types"

type GaugeTone = "fuel" | "wear" | "def"

function amountLabel(value: number, max: number, language: string) {
  const format = (n: number) => n.toLocaleString(language, { maximumFractionDigits: 0 })
  return `${format(value)} / ${format(max)}`
}

export function VehicleGauges({
  vehicle,
  language,
  label,
}: {
  vehicle: Pick<Vehicle, "fuel" | "fuelCapacity" | "wear" | "defLevel" | "defCapacity">
  language: string
  label: (key: string) => string
}) {
  const gauges: ReturnType<typeof EquipGauge>[] = []
  if (vehicle.fuel != null && vehicle.fuelCapacity != null && vehicle.fuelCapacity > 0) {
    gauges.push(
      <EquipGauge
        key="fuel"
        tone="fuel"
        label={label("vehicles.fuel")}
        value={vehicle.fuel}
        max={vehicle.fuelCapacity}
        amount={amountLabel(vehicle.fuel, vehicle.fuelCapacity, language)}
      />,
    )
  }
  if (vehicle.wear != null) {
    gauges.push(
      <EquipGauge
        key="wear"
        tone="wear"
        label={label("vehicles.wear")}
        value={vehicle.wear}
        max={100}
        amount={amountLabel(vehicle.wear, 100, language)}
      />,
    )
  }
  if (vehicle.defLevel != null && vehicle.defCapacity != null && vehicle.defCapacity > 0) {
    gauges.push(
      <EquipGauge
        key="def"
        tone="def"
        label={label("vehicles.def")}
        value={vehicle.defLevel}
        max={vehicle.defCapacity}
        amount={amountLabel(vehicle.defLevel, vehicle.defCapacity, language)}
      />,
    )
  }
  if (gauges.length === 0) return null
  return <span className="equip-gauges">{gauges}</span>
}

export function EquipGauge({
  label,
  value,
  max,
  tone,
  amount,
}: {
  label: string
  value: number
  max: number
  tone: GaugeTone
  amount: string
}) {
  const pct = max > 0 ? Math.max(0, Math.min(100, (value / max) * 100)) : 0
  const radius = 16
  const circ = 2 * Math.PI * radius
  const dash = (pct / 100) * circ

  return (
    <span className={`equip-gauge is-${tone}`}>
      <span className="equip-gauge-ring">
        <svg viewBox="0 0 40 40" aria-hidden="true">
          <circle className="equip-gauge-track" cx="20" cy="20" r={radius} />
          <circle
            className="equip-gauge-value"
            cx="20"
            cy="20"
            r={radius}
            strokeDasharray={`${dash} ${circ}`}
          />
        </svg>
        <span className="equip-gauge-pct">{Math.round(pct)}%</span>
      </span>
      <span className="equip-gauge-label">{label}</span>
      <span className="equip-gauge-amt">{amount}</span>
    </span>
  )
}
