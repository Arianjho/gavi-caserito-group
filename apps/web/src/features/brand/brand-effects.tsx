import { useEffect } from "react";

import { useBrand } from "@/features/brand/use-brand";
import { brandCss } from "@/lib/brand";

const STYLE_ID = "brand-style";

/**
 * Aplica la marca a toda la página: color del panel, título y favicon.
 *
 * No dibuja nada; se monta una vez en la raíz.
 */
export function BrandEffects() {
  const brand = useBrand();

  useEffect(() => {
    let style = document.getElementById(STYLE_ID);
    if (!brand.color) {
      style?.remove();
      return;
    }
    if (!style) {
      style = document.createElement("style");
      style.id = STYLE_ID;
      document.head.appendChild(style);
    }
    style.textContent = brandCss(brand.color);
  }, [brand.color]);

  useEffect(() => {
    document.title = brand.name;
  }, [brand.name]);

  useEffect(() => {
    if (!brand.icon_url) return;
    let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    if (!link) {
      link = document.createElement("link");
      link.rel = "icon";
      document.head.appendChild(link);
    }
    link.type = "image/png";
    link.href = brand.icon_url;
  }, [brand.icon_url]);

  return null;
}
