import { RouterProvider } from "@tanstack/react-router";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";

import { router } from "./router";
import "./styles.css";

/**
 * Punto de entrada de la SPA: monta el enrutador en el elemento `#root`.
 */
const container = document.getElementById("root");
if (!container) {
  throw new Error("No se encontró el elemento #root");
}

createRoot(container).render(
  <StrictMode>
    <RouterProvider router={router} />
  </StrictMode>,
);
