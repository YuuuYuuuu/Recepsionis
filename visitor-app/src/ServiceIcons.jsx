const stroke = {
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 2.2,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
}

export function RoomIcon({ className = '' }) {
  return (
    <svg viewBox="0 0 24 24" className={className} aria-hidden>
      <path
        {...stroke}
        d="M4 10.5 12 5l8 5.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z"
      />
      <path {...stroke} d="M9 21V13h6v8" opacity="0.55" />
    </svg>
  )
}

export function ProdiIcon({ className = '' }) {
  return (
    <svg viewBox="0 0 24 24" className={className} aria-hidden>
      <path
        {...stroke}
        d="M4 9.5 12 5l8 4.5-8 4.5-8-4.5Z"
      />
      <path {...stroke} d="M6 11v5.2c0 .8 2.4 2.3 6 2.3s6-1.5 6-2.3V11" />
      <path {...stroke} d="M20 9.8V16" opacity="0.55" />
    </svg>
  )
}

export function StaffIcon({ className = '' }) {
  return (
    <svg viewBox="0 0 24 24" className={className} aria-hidden>
      <path
        {...stroke}
        d="M8.5 14.5a4 4 0 1 1 7 0"
      />
      <path
        {...stroke}
        d="M5.5 19.5c.9-2.6 2.8-4 6-4s5.1 1.4 6 4"
      />
      <path
        {...stroke}
        d="M17.5 8.5a3.5 3.5 0 0 1 0 5"
        opacity="0.55"
      />
      <path
        {...stroke}
        d="M19.5 6.5a5.5 5.5 0 0 1 0 9"
        opacity="0.35"
      />
    </svg>
  )
}
