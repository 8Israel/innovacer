# Diseño de pantallas — Innovacer

Maquetas en HTML estático de las 44 pantallas, ventanas y estados del sistema. Se abren directo en el navegador (`index.html` tiene la lista). Son la referencia visual para construir las vistas en Livewire + Tailwind.

## Cómo usarlas

- **Los datos son de ejemplo.** Nombres, montos, folios y existencias sirven para que las pantallas cuadren entre sí (p. ej. UNI-20 con 12 en Mostrador, Francisco Castañón con saldo de $12,400, corte con −$50). No son datos reales ni reglas de negocio.
- `[Clave SAT]`, `[Nombre del dueño]` y textos entre corchetes son marcadores pendientes.
- El IVA aparece en $0.00 hasta confirmar las tasas con el contador.
- Los permisos son **fijos por rol** (dueño y encargada). La tabla de `Configuracion.html` solo los muestra; no se editan.
- Las ventanas (modales) están dibujadas solas, sin la pantalla de fondo; el tamaño indica el ancho sugerido.
- Tipografías: Montserrat (títulos) e IBM Plex Sans (texto), desde Google Fonts. Los logos están en `assets/`.
- Colores principales: verde `#125D40` (acción principal), fondo `#F4F6F3`, texto `#15211B`, bordes `#DDE3DD`, enlaces `#1A4F9C`.

## Pantallas por módulo

### Base y acceso

Requerimientos: RF-40, RNF-02

| Archivo | Pantalla | Tamaño |
| --- | --- | --- |
| [`Login.html`](Login.html) | Inicio de sesión | 1440×900 |
| [`MenuRoles.html`](MenuRoles.html) | Menú según el rol | 1040×1000 |
| [`MiCuenta.html`](MiCuenta.html) | Mi cuenta | 640×680 |
| [`Configuracion.html`](Configuracion.html) | Configuración: usuarios y almacenes | 1440×1100 |
| [`UsuariosModales.html`](UsuariosModales.html) | Configuración · usuario, contraseña y almacén | 1440×760 |

### Punto de venta · Fase 1

Requerimientos: RF-17 a RF-23, RF-25

| Archivo | Pantalla | Tamaño |
| --- | --- | --- |
| [`Main.html`](Main.html) | Punto de venta | 1440×1000 |
| [`Busqueda.html`](Busqueda.html) | POS · resultados de búsqueda | 900×600 |
| [`ClienteRapido.html`](ClienteRapido.html) | POS · elegir cliente / alta rápida | 720×760 |
| [`NuevaCuenta.html`](NuevaCuenta.html) | POS · nueva cuenta o destino | 640×520 |
| [`PagoMixto.html`](PagoMixto.html) | POS · pago mixto | 720×760 |
| [`Autorizacion.html`](Autorizacion.html) | POS · autorización del dueño | 640×640 |
| [`VentaRegistrada.html`](VentaRegistrada.html) | POS · venta registrada | 640×640 |
| [`VentasPendientes.html`](VentasPendientes.html) | POS · ventas pendientes | 720×640 |
| [`Avisos.html`](Avisos.html) | POS · avisos que bloquean la venta | 1440×560 |
| [`Ticket.html`](Ticket.html) | Ticket y nota de venta impresos | 1440×1000 |

### Ventas · Fase 1

Requerimientos: RF-16, RF-22, RF-36

| Archivo | Pantalla | Tamaño |
| --- | --- | --- |
| [`Ventas.html`](Ventas.html) | Historial de ventas | 1440×1000 |
| [`VentaDetalle.html`](VentaDetalle.html) | Detalle de venta y devolución | 1440×1100 |
| [`CancelarVenta.html`](CancelarVenta.html) | Ventas · cancelar venta | 640×760 |

### Caja · Fase 1

Requerimientos: RF-21, RF-32, RF-33

| Archivo | Pantalla | Tamaño |
| --- | --- | --- |
| [`AbrirCaja.html`](AbrirCaja.html) | Caja · abrir caja | 640×600 |
| [`Caja.html`](Caja.html) | Caja y corte | 1440×1100 |
| [`CajaHistorial.html`](CajaHistorial.html) | Cortes de caja anteriores | 1440×1000 |
| [`Impresos2.html`](Impresos2.html) | Corte y estado de cuenta impresos | 1440×1000 |

### Inventario · Fase 1

Requerimientos: RF-01 a RF-16

| Archivo | Pantalla | Tamaño |
| --- | --- | --- |
| [`Inventario.html`](Inventario.html) | Inventario | 1440×1000 |
| [`Producto.html`](Producto.html) | Alta de producto | 1440×1100 |
| [`ClaveSAT.html`](ClaveSAT.html) | Producto · buscar clave SAT | 720×720 |
| [`Entrada.html`](Entrada.html) | Entrada de mercancía | 1440×1000 |
| [`Traspaso.html`](Traspaso.html) | Traspaso entre almacenes | 1440×1000 |
| [`Kardex.html`](Kardex.html) | Kardex de producto | 1440×1100 |
| [`AjusteConteo.html`](AjusteConteo.html) | Inventario · ajuste por conteo | 640×720 |
| [`ConteoFisico.html`](ConteoFisico.html) | Conteo físico por almacén | 1440×1100 |
| [`Prestamos.html`](Prestamos.html) | Préstamos y reposiciones | 1440×1000 |
| [`Importar.html`](Importar.html) | Importar desde Excel | 1440×1000 |

### Clientes y cobranza · Fase 2

Requerimientos: RF-24 a RF-28

| Archivo | Pantalla | Tamaño |
| --- | --- | --- |
| [`Clientes.html`](Clientes.html) | Clientes | 1440×1000 |
| [`Cliente.html`](Cliente.html) | Ficha del cliente | 1440×1100 |
| [`EstadoCuenta.html`](EstadoCuenta.html) | Estado de cuenta del cliente | 1440×1100 |

### Proveedores · Fase 2

Requerimientos: RF-29 a RF-31

| Archivo | Pantalla | Tamaño |
| --- | --- | --- |
| [`Proveedores.html`](Proveedores.html) | Proveedores y alta | 1440×1000 |
| [`Proveedor.html`](Proveedor.html) | Proveedor y cuenta por pagar | 1440×1100 |

### Facturación · Fase 3

Requerimientos: RF-34, RF-35

| Archivo | Pantalla | Tamaño |
| --- | --- | --- |
| [`Facturar.html`](Facturar.html) | Facturar ventas | 1440×1200 |
| [`FacturasEmitidas.html`](FacturasEmitidas.html) | Facturas emitidas | 1440×1100 |
| [`ComplementoPago.html`](ComplementoPago.html) | Facturación · complemento de pago | 720×760 |

### Reportes · Fase 3

Requerimientos: RF-36 a RF-39

| Archivo | Pantalla | Tamaño |
| --- | --- | --- |
| [`Reportes.html`](Reportes.html) | Reportes | 1440×1200 |
| [`ReportesMovil.html`](ReportesMovil.html) | Reportes en celular | 390×1300 |

### Estados y mensajes · Todas

Requerimientos: RNF-05, RNF-06

| Archivo | Pantalla | Tamaño |
| --- | --- | --- |
| [`Estados.html`](Estados.html) | Estados vacíos y mensajes | 1440×900 |
| [`Confirmaciones.html`](Confirmaciones.html) | Confirmaciones de acciones delicadas | 1440×600 |

Los requerimientos completos están en [`../requerimientos.md`](../requerimientos.md).
