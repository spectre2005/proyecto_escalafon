import { normalizeInstitution } from './institution.js'

const rgb = hex => hex.match(/[a-f0-9]{2}/gi).map(part => parseInt(part, 16))
const hex = channels => `#${channels.map(channel => Math.round(channel).toString(16).padStart(2, '0')).join('')}`
const mix = (color, target, amount) => color.map(channel => channel * (1 - amount) + target * amount)
const luminance = color => color.map(channel => channel / 255).map(channel => channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4).reduce((sum, channel, index) => sum + channel * [.2126, .7152, .0722][index], 0)

export function institutionPageTheme(settings) {
  const { pageColor, pageBackground } = normalizeInstitution(settings)
  let primary = rgb(pageColor)
  // Los botones con texto blanco mantienen contraste incluso con colores muy claros.
  while ((1.05 / (luminance(primary) + .05)) < 4.5) primary = mix(primary, 0, .08)
  const colors = { 50: hex(mix(primary, 255, .95)), 100: hex(mix(primary, 255, .88)), 200: hex(mix(primary, 255, .75)),
    300: hex(mix(primary, 255, .6)), 400: hex(mix(primary, 255, .35)), 500: hex(primary), 600: hex(primary),
    700: hex(primary), 800: hex(mix(primary, 0, .2)), 900: hex(mix(primary, 0, .4)), 950: hex(mix(primary, 0, .6)) }
  return { '--page-background': pageBackground, ...Object.fromEntries(Object.entries(colors).map(([shade, color]) => [`--color-teal-${shade}`, color])) }
}
