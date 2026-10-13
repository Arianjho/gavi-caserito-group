/**
 * Textos de la interfaz en español (Perú).
 *
 * Las claves están en inglés; los valores en español. Ningún componente debe
 * llevar literales de interfaz: se reciben desde aquí o desde la API.
 */
export const messages = {
  "common.cancel": "Cancelar",
  "common.close": "Cerrar",
  "common.save": "Guardar",
  "common.saving": "Guardando…",
  "common.register": "Registrar",
  "common.registering": "Registrando…",
  "common.delete": "Eliminar",
  "common.deleting": "Eliminando…",
  "common.retry": "Reintentar",
  "common.loading": "Cargando…",
  "common.empty.title": "Aún no hay nada aquí",
  "common.error.title": "No pudimos cargar esta información",
  "common.error.description": "Revisa tu conexión e inténtalo de nuevo.",
  "common.processing.hint": "Estamos procesando tu solicitud. No cierres esta ventana.",
  "common.processing.slow": "Sigue procesando… esto está tardando más de lo normal.",
  "common.processing.timeout":
    "No pudimos confirmar si se guardó. Verifica antes de intentarlo otra vez.",
  "common.success": "Listo",
  "nav.home": "Inicio",
} as const;

/** Claves válidas del diccionario de mensajes. */
export type MessageKey = keyof typeof messages;

/**
 * Códigos de error de la API y su texto amigable.
 * Si un código no está aquí se usa el mensaje que envía el servidor.
 */
export const errorMessages: Record<string, string> = {
  validation_failed: "Revisa los datos marcados e inténtalo de nuevo.",
  stock_insufficient: "No hay stock suficiente para esta operación.",
  document_number_invalid: "El número de documento no es válido.",
  record_in_use: "Este registro ya tiene movimientos y no puede eliminarse. Puedes desactivarlo.",
  permission_denied: "No tienes permiso para realizar esta acción.",
  concurrent_modification:
    "Otra persona modificó este registro. Recarga la página para ver los cambios.",
  network_unreachable: "No pudimos comunicarnos con el servidor. Revisa tu conexión.",
  system_error: "Algo salió mal de nuestro lado. Tu información no se perdió; inténtalo de nuevo.",
};

/**
 * Obtiene un texto de la interfaz.
 *
 * @param key Clave del diccionario.
 */
export function t(key: MessageKey): string {
  return messages[key];
}
