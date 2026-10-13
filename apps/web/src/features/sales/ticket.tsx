import { formatPen } from "@/lib/money";

/** Línea de un documento de venta tal como la entrega la API. */
export interface SaleLine {
  id: string;
  description: string;
  qty: string;
  unit_price: string;
  total: string;
}

/** Cobro de un documento de venta. */
export interface SalePayment {
  id: string;
  method: string;
  amount: string;
}

/** Documento de venta con líneas y cobros. */
export interface SaleDetail {
  id: string;
  series: string;
  number: number;
  status: "confirmed" | "voided";
  total: string;
  igv_total: string;
  taxable_total: string;
  issued_at: string;
  lines: SaleLine[];
  payments: SalePayment[];
}

const METHOD_LABEL: Record<string, string> = {
  cash: "Efectivo",
  card: "Tarjeta",
  yape: "Yape",
  plin: "Plin",
  transfer: "Transferencia",
  other: "Otro",
};

/** Propiedades de {@link Ticket}. */
interface TicketProps {
  /** Documento a imprimir. */
  sale: SaleDetail;
  /** Nombre comercial de la empresa en el encabezado. */
  companyName: string;
}

/**
 * Ticket de venta de 80 mm, listo para imprimir con `window.print()`.
 *
 * Se marca con la clase `print-area`: al imprimir, el resto de la página se
 * oculta (ver estilos de impresión en `styles.css`).
 */
export function Ticket({ sale, companyName }: TicketProps) {
  const issued = new Date(sale.issued_at);
  return (
    <div className="print-area mx-auto w-[80mm] bg-white p-3 font-mono text-[11px] leading-tight text-black">
      <p className="text-center text-sm font-bold">{companyName}</p>
      <p className="text-center">NOTA DE VENTA</p>
      <p className="text-center font-bold">
        {sale.series}-{String(sale.number).padStart(6, "0")}
      </p>
      {sale.status === "voided" && <p className="text-center font-bold">*** ANULADO ***</p>}
      <p className="text-center">{issued.toLocaleString("es-PE")}</p>
      <hr className="my-2 border-dashed border-black" />
      {sale.lines.map((line) => (
        <div key={line.id} className="mb-1">
          <p>{line.description}</p>
          <p className="flex justify-between">
            <span>
              {Number(line.qty)} x {formatPen(line.unit_price)}
            </span>
            <span>{formatPen(line.total)}</span>
          </p>
        </div>
      ))}
      <hr className="my-2 border-dashed border-black" />
      <p className="flex justify-between">
        <span>Op. gravada</span>
        <span>{formatPen(sale.taxable_total)}</span>
      </p>
      <p className="flex justify-between">
        <span>IGV 18%</span>
        <span>{formatPen(sale.igv_total)}</span>
      </p>
      <p className="flex justify-between text-sm font-bold">
        <span>TOTAL</span>
        <span>{formatPen(sale.total)}</span>
      </p>
      <hr className="my-2 border-dashed border-black" />
      {sale.payments.map((payment) => (
        <p key={payment.id} className="flex justify-between">
          <span>{METHOD_LABEL[payment.method] ?? payment.method}</span>
          <span>{formatPen(payment.amount)}</span>
        </p>
      ))}
      <p className="mt-3 text-center">Gracias por tu compra</p>
    </div>
  );
}
