export type IconName =
  | 'grid' | 'file' | 'users' | 'card' | 'settings' | 'help' | 'chevron' | 'plus' | 'search' | 'bell'
  | 'arrow' | 'calendar' | 'more' | 'trend' | 'clock' | 'sparkle' | 'sun' | 'moon' | 'monitor'
  | 'menu' | 'building' | 'mail' | 'check' | 'close' | 'link' | 'shield' | 'globe' | 'logout'
  | 'eye' | 'eyeOff'

const iconPaths: Record<IconName, string[]> = {
  grid: ['M3.5 3.5h7v7h-7zM13.5 3.5h7v7h-7zM3.5 13.5h7v7h-7zM13.5 13.5h7v7h-7z'],
  file: ['M6 2.75h8l4 4v14.5H6z', 'M14 2.75v4h4', 'M9 12h6M9 16h6'],
  users: ['M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20', 'M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M17 3.2a4 4 0 0 1 0 7.6M21 20v-1.5a4 4 0 0 0-3-3.87'],
  card: ['M3 6.5h18v12H3z', 'M3 10h18', 'M7 15.5h3'],
  settings: ['M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z', 'M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-1.7 2.94-.1-.03a1.7 1.7 0 0 0-1.8.72l-.04.09h-3.4l-.04-.09a1.7 1.7 0 0 0-1.8-.72l-.1.03-1.7-2.94.06-.06A1.7 1.7 0 0 0 9.5 15l-.1-.03v-3.4l.1-.03a1.7 1.7 0 0 0-.34-1.88L9.1 9.6l1.7-2.94.1.03a1.7 1.7 0 0 0 1.8-.72l.04-.09h3.4l.04.09a1.7 1.7 0 0 0 1.8.72l.1-.03 1.7 2.94-.06.06A1.7 1.7 0 0 0 19.4 12l.1.03v3.4z'],
  help: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M9.6 9a2.5 2.5 0 1 1 4.4 1.6c-1.1 1.1-2 1.4-2 3', 'M12 17.5h.01'],
  chevron: ['m9 18 6-6-6-6'], plus: ['M12 5v14M5 12h14'],
  search: ['M10.8 18.1a7.3 7.3 0 1 0 0-14.6 7.3 7.3 0 0 0 0 14.6z', 'm16.2 16.2 4.3 4.3'],
  bell: ['M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9', 'M10 21h4'],
  arrow: ['M5 12h14', 'm13 6 6 6-6 6'], calendar: ['M4 5h16v16H4z', 'M8 3v4M16 3v4M4 10h16'],
  more: ['M5 12h.01M12 12h.01M19 12h.01'], trend: ['m3 17 6-6 4 4 8-9', 'M15 6h6v6'],
  clock: ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z', 'M12 6v6l4 2'],
  sparkle: ['m12 3 1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5z', 'm19 15 .7 2.3L22 18l-2.3.7L19 21l-.7-2.3L16 18l2.3-.7z'],
  sun: ['M12 3v2M12 19v2M3 12h2M19 12h2M5.64 5.64l1.42 1.42m9.88 9.88 1.42 1.42m0-12.72-1.42 1.42m-9.88 9.88-1.42 1.42', 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z'],
  moon: ['M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2z'],
  monitor: ['M3 4h18v13H3z', 'M8 21h8M12 17v4'], menu: ['M4 6h16M4 12h16M4 18h16'],
  building: ['M3 21h18', 'M5 21V5l7-3 7 3v16', 'M9 9h.01M15 9h.01M9 13h.01M15 13h.01M10 21v-4h4v4'],
  mail: ['M4 5h16v14H4z', 'm4 7 8 6 8-6'],
  check: ['m5 12 4 4L19 6'], close: ['m6 6 12 12M18 6 6 18'],
  link: ['M10 13a5 5 0 0 0 7.1 0l3-3a5 5 0 0 0-7.1-7.1l-1.7 1.7', 'M14 11a5 5 0 0 0-7.1 0l-3 3a5 5 0 0 0 7.1 7.1l1.7-1.7'],
  shield: ['M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z', 'm9 12 2 2 4-4'],
  logout: ['M15 3h3.5A2.5 2.5 0 0 1 21 5.5v13a2.5 2.5 0 0 1-2.5 2.5H15', 'M10 16.5 14.5 12 10 7.5', 'M14.5 12H3'],
  globe: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M3.6 9h16.8M3.6 15h16.8', 'M12 3c2.4 2.4 3.6 5.4 3.6 9s-1.2 6.6-3.6 9c-2.4-2.4-3.6-5.4-3.6-9s1.2-6.6 3.6-9z'],
  eye: ['M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z'],
  eyeOff: ['M4 4l16 16', 'M9.9 5.1A9.7 9.7 0 0 1 12 4.9c6 0 9.5 6.2 9.5 6.2a17 17 0 0 1-3 3.7', 'M6.4 6.8A17.4 17.4 0 0 0 2.5 11.1s3.5 6.2 9.5 6.2a9 9 0 0 0 3.3-.6', 'M10 10a3 3 0 0 0 4.2 4.2'],
}

export function Icon({ name, size = 18 }: { name: IconName; size?: number }) {
  return (
    <svg aria-hidden="true" className="icon" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
      {iconPaths[name].map((path) => <path key={path} d={path} />)}
    </svg>
  )
}
