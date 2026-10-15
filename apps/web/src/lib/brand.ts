/** Marca de la cuenta tal como la entrega la API. */
export interface Brand {
  name: string;
  color: string | null;
  tagline: string | null;
  email: string | null;
  phone: string | null;
  footer: string | null;
  logo_url: string | null;
  logo_dark_url: string | null;
  icon_url: string | null;
  powered_by: boolean;
  /** `true` cuando la cuenta personalizó la marca; `false` es la del producto. */
  custom: boolean;
}

/** Marca del producto, usada mientras carga o cuando la cuenta no personalizó nada. */
export const DEFAULT_BRAND: Brand = {
  name: "Gavi Caserito",
  color: null,
  tagline: null,
  email: null,
  phone: null,
  footer: null,
  logo_url: null,
  logo_dark_url: null,
  icon_url: null,
  powered_by: false,
  custom: false,
};

/** Componentes RGB (0-255) de un color. */
type Rgb = [number, number, number];

/**
 * Convierte `#RRGGBB` en componentes RGB.
 *
 * @param hex Color hexadecimal de 6 dígitos.
 */
function parse(hex: string): Rgb {
  const value = hex.replace("#", "");
  return [0, 2, 4].map((i) => parseInt(value.slice(i, i + 2), 16)) as Rgb;
}

/**
 * Convierte componentes RGB en `#rrggbb`.
 *
 * @param rgb Componentes del color.
 */
function format(rgb: Rgb): string {
  return `#${rgb.map((c) => Math.round(c).toString(16).padStart(2, "0")).join("")}`;
}

/**
 * Mezcla un color con otro.
 *
 * @param hex Color base.
 * @param other Color con el que se mezcla.
 * @param amount Proporción de `other` entre 0 y 1.
 */
export function mix(hex: string, other: string, amount: number): string {
  const a = parse(hex);
  const b = parse(other);
  return format(a.map((c, i) => c + ((b[i] ?? 0) - c) * amount) as Rgb);
}

/**
 * Luminancia relativa WCAG de un color.
 *
 * @param hex Color hexadecimal.
 */
function luminance(hex: string): number {
  const [r, g, b] = parse(hex).map((c) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  }) as Rgb;
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/**
 * Elige blanco o casi negro según cuál contraste mejor con el fondo.
 *
 * @param hex Color de fondo.
 */
export function readableOn(hex: string): string {
  return luminance(hex) > 0.4 ? "#111827" : "#ffffff";
}

/**
 * Genera las variables CSS que re-marcan el panel con el color de la cuenta.
 *
 * En tema claro usa el color tal cual (si es muy claro, lo oscurece para mantener
 * contraste del texto sobre botones); en oscuro lo aclara. El texto sobre el color
 * se elige por contraste.
 *
 * @param hex Color principal `#RRGGBB`.
 * @returns Hoja de estilos a inyectar.
 */
export function brandCss(hex: string): string {
  const light = luminance(hex) > 0.75 ? mix(hex, "#000000", 0.35) : hex;
  const dark = mix(hex, "#ffffff", 0.3);
  const block = (base: string) => {
    const foreground = readableOn(base);
    const end = mix(base, "#000000", 0.25);
    return [
      `--primary:${base}`,
      `--primary-foreground:${foreground}`,
      `--brand:${base}`,
      `--brand-foreground:${foreground}`,
      `--ring:${base}`,
      `--sidebar-primary:${base}`,
      `--sidebar-primary-foreground:${foreground}`,
      `--sidebar-ring:${base}`,
      `--gradient-brand:linear-gradient(135deg,${base},${end})`,
    ].join(";");
  };
  return `:root{${block(light)}}.dark{${block(dark)}}`;
}
