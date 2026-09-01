import { motion } from 'framer-motion'

export default function DynamicBlueWallpaper() {
  return (
    <div
      aria-hidden
      className="tw-pointer-events-none tw-fixed tw-inset-0 tw-z-0 tw-overflow-hidden"
    >
      <div className="tw-absolute tw-inset-0 tw-bg-[linear-gradient(165deg,#07111f_0%,#123a5c_48%,#0b4d6e_100%)]" />

      <motion.div
        className="tw-absolute -tw-left-[18%] -tw-top-[24%] tw-h-[min(90vw,760px)] tw-w-[min(90vw,760px)] tw-rounded-full tw-bg-sky-400/30 tw-blur-[90px]"
        animate={{ x: [0, 80, -50, 0], y: [0, 60, 40, 0] }}
        transition={{ duration: 18, repeat: Infinity, ease: 'easeInOut' }}
      />
      <motion.div
        className="tw-absolute -tw-right-[16%] tw-top-[8%] tw-h-[min(80vw,680px)] tw-w-[min(80vw,680px)] tw-rounded-full tw-bg-cyan-400/22 tw-blur-[100px]"
        animate={{ x: [0, -70, 40, 0], y: [0, 50, -40, 0] }}
        transition={{ duration: 22, repeat: Infinity, ease: 'easeInOut' }}
      />
      <motion.div
        className="tw-absolute tw-left-[10%] tw-bottom-[-22%] tw-h-[min(95vw,700px)] tw-w-[min(95vw,700px)] tw-rounded-full tw-bg-teal-400/18 tw-blur-[88px]"
        animate={{ x: [0, 60, -40, 0], y: [0, -50, 30, 0] }}
        transition={{ duration: 20, repeat: Infinity, ease: 'easeInOut' }}
      />

      <div
        className="tw-absolute tw-inset-0 tw-opacity-[0.18]"
        style={{
          backgroundImage:
            'radial-gradient(ellipse at 20% 0%, rgba(186,230,253,0.28), transparent 52%)',
        }}
      />
      <div className="tw-absolute tw-inset-0 tw-bg-gradient-to-t tw-from-[#041018]/70 tw-via-transparent tw-to-cyan-900/10" />
    </div>
  )
}
