import { motion } from 'framer-motion'

const themes = {
  green: {
    wash: 'tw-from-emerald-400/20',
    ink: 'tw-text-emerald-700',
    ring: 'tw-ring-emerald-300/60',
    iconBg: 'tw-bg-emerald-500/12',
    iconBorder: 'tw-border-emerald-500/20',
    iconColor: 'tw-text-emerald-700',
  },
  orange: {
    wash: 'tw-from-amber-400/20',
    ink: 'tw-text-amber-700',
    ring: 'tw-ring-amber-300/60',
    iconBg: 'tw-bg-amber-500/12',
    iconBorder: 'tw-border-amber-500/20',
    iconColor: 'tw-text-amber-700',
  },
  blue: {
    wash: 'tw-from-sky-400/22',
    ink: 'tw-text-sky-700',
    ring: 'tw-ring-sky-300/60',
    iconBg: 'tw-bg-sky-500/12',
    iconBorder: 'tw-border-sky-500/20',
    iconColor: 'tw-text-sky-700',
  },
}

function Arrow() {
  return (
    <svg className="tw-h-5 tw-w-5" viewBox="0 0 20 20" fill="none" aria-hidden>
      <path
        d="M4 10h11M11 5l5 5-5 5"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

export default function ServiceCard({
  id,
  theme,
  icon: Icon,
  title,
  description,
  ctaLabel,
  onActivate,
  highlighted,
  delay = 0,
}) {
  const t = themes[theme] || themes.blue

  return (
    <motion.button
      id={id}
      type="button"
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0, scale: highlighted ? 1.02 : 1 }}
      transition={{
        delay,
        duration: 0.45,
        ease: [0.22, 1, 0.36, 1],
      }}
      className={[
        'kiosk-service-card group tw-relative tw-flex tw-h-full tw-min-h-[260px] tw-w-full tw-cursor-pointer tw-flex-col tw-overflow-hidden tw-rounded-[1.65rem] tw-px-7 tw-pb-7 tw-pt-6 tw-text-left tw-outline-none focus-visible:tw-ring-4 focus-visible:tw-ring-sky-300/50 sm:tw-min-h-[300px] sm:tw-px-8 sm:tw-pt-7 lg:tw-min-h-[320px]',
        highlighted ? `tw-ring-4 ${t.ring}` : '',
      ].join(' ')}
      whileHover={{ y: -6 }}
      whileTap={{ scale: 0.98 }}
      onClick={onActivate}
    >
      <div
        className={`tw-pointer-events-none tw-absolute tw-inset-x-0 tw-bottom-0 tw-h-2/3 tw-bg-gradient-to-t ${t.wash} tw-to-transparent`}
      />

      <motion.div
        className={`kiosk-service-icon tw-relative tw-mb-5 tw-flex tw-h-14 tw-w-14 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-2xl tw-border ${t.iconBg} ${t.iconBorder}`}
        animate={{ y: [0, -4, 0] }}
        transition={{ duration: 4.2, repeat: Infinity, ease: 'easeInOut', delay }}
      >
        <Icon className={`tw-h-7 tw-w-7 ${t.iconColor}`} />
      </motion.div>

      <div className="tw-relative tw-mt-auto tw-flex tw-min-h-[8.5rem] tw-flex-col">
        <h3 className="tw-mb-1.5 tw-font-display tw-text-[1.55rem] tw-font-semibold tw-leading-tight tw-tracking-tight tw-text-slate-900 sm:tw-text-[1.75rem] lg:tw-text-[1.9rem]">
          {title}
        </h3>
        <p className="tw-mb-5 tw-flex-1 tw-text-[0.95rem] tw-leading-relaxed tw-text-slate-500 sm:tw-text-base">
          {description}
        </p>
        <span
          className={`tw-mt-auto tw-inline-flex tw-items-center tw-gap-2 tw-text-sm tw-font-semibold tw-tracking-wide sm:tw-text-base ${t.ink}`}
        >
          {ctaLabel}
          <span className="tw-transition-transform tw-duration-300 group-hover:tw-translate-x-1.5">
            <Arrow />
          </span>
        </span>
      </div>
    </motion.button>
  )
}
