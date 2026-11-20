# Requerimientos — Sistema de Inventario y Punto de Venta

Innovacer Agrotechnology · versión del 9 oct 2026, actualizada el 19 nov 2026

El programa actual solo sirve para cobrar. El inventario, los créditos, los pagos a proveedores y la facturación se llevan a mano en papel, Excel y varias computadoras que no cuadran entre sí. El sistema nuevo debe tener un solo inventario confiable por almacén y dueño, con trazabilidad de cada movimiento.

## Decisiones tomadas después del levantamiento

- El inventario de venta se llama **Innovacer** (en las transcripciones aparecía como "Inovacer").
- Sistema web en Laravel con una sola base de datos en un servidor en la nube.
- Timbrado de facturas con Facturapi.
- Permisos **fijos por rol** (dueño y encargada), sin tabla editable de permisos.
- El diseño de todas las pantallas está en `docs/diseno/` (ver `docs/diseno/README.md`).

## Contexto

El negocio vende productos agrícolas (nitrógeno, calcio, magnesio, etc.) que en su mayoría llegan en consigna: se le paga al proveedor solo lo que se vende. La encargada lleva dos meses operando el inventario y hoy registra todo tres veces: en papel, en Excel y en el programa.

| Actor | Quién es | Qué hace en el flujo |
| --- | --- | --- |
| Dueño | "Tu tío" | Decide precios, descuentos, créditos y pagos a proveedores |
| Encargada | Quien explica el proceso en los audios | Registra ventas, entradas, salidas, traspasos y abonos; factura |
| Proveedores | Biotexa, Coda, NH, TN, Tierra Verde | Dejan producto, casi siempre en consigna; Biotexa a veces se paga de contado o por transferencia |
| Bodega Ortitec | Bodega que el dueño le renta a Ortitec | El producto es de Ortitec; el negocio toma de ahí para vender y paga después en montos globales |
| Consigna de Francisco Rodríguez | Producto Biotexa de un tercero | Se toma producto prestado y luego se repone |
| Inventario de venta (Innovacer) | Inventario propio del mostrador | Es desde donde se vende; hoy se llena pasando producto a mano desde las otras bodegas |
| Clientes | Contado y crédito abierto | Algunos tienen varias cuentas o destinos (Reynosa, "Jalincher" y otros, nombres por confirmar) |

### Flujo del producto

```
Ortitec ──deja producto──▶ Bodega Ortitec ──traspaso──▶ ┐
Proveedores (Biotexa, Coda, NH, TN, Tierra Verde) ──entrada──▶ Inventario de venta (Mostrador Innovacer) ──venta──▶ Clientes
Francisco Rodríguez ──▶ Consigna Francisco ──préstamo (se repone después)──▶ ┘
```

Las flechas desde la bodega Ortitec y la consigna de Francisco hoy son una baja y una alta capturadas a mano, con un código de producto distinto. En el sistema nuevo cada una será un solo traspaso (S1).

## Problemática

Se detectaron 22 problemas. Los más graves son los inventarios separados que se cuadran a mano (P1–P4) y los créditos y pagos sin trazabilidad (P8–P13). La evidencia indica audio y minuto de la grabación.

| ID | Problema | Evidencia | Impacto |
| --- | --- | --- | --- |
| P1 | Cada bodega y consigna es un inventario aparte. Para vender producto de Ortitec o de Francisco hay que darle baja en uno, alta en otro y crear un código distinto ("Core" vs "H Core") | A1 05:18, 15:05, 20:01 · A6 00:00 · A7 00:25 · A8 00:00 | Varios registros por cada traspaso; errores y descuadres |
| P2 | El mismo producto de dos proveedores se mezcla en uno solo; no se sabe quién trajo cuántos ni a qué costo | A7 02:19 | No se puede pagar ni costear correctamente por proveedor |
| P3 | Los movimientos de inventario no aceptan notas (quién se lo llevó, para qué, p. ej. "Ing. Meléndez, 12 cajas" o "para un experimento") | A1 07:05, 21:17 | Para reconstruir qué pasó hay que buscar nota por nota |
| P4 | Las salidas no muestran existencia anterior ni restante ("había 15, salieron 2, quedan 13") | A1 08:06 | No se puede verificar rápido contra el físico |
| P5 | Las existencias se actualizan a mano (18 + 10 = 28, capturado por ella) | A2 03:10 | Errores de suma |
| P6 | No se puede dar de alta un producto sin precio (p. ej. materiales que no se venden) | A2 26:29 · A5 01:29 · A6 02:14 | Hay inventario que no existe en el sistema |
| P7 | Buscar un producto o su historial es lento (con flechas, sin filtro) | A1 09:17 | Tiempo perdido al atender |
| P8 | Las ventas no registran a qué cuenta o destino van (Reynosa, "Jalincher") ni observaciones | A1 00:00, 01:22, 22:36 | Cuando el dueño pregunta "¿qué se llevó a tal lugar?" hay que revisar notas en papel |
| P9 | Los abonos solo bajan el saldo total; no se puede decir a qué cuenta o venta se aplican, ni agregar nota | A1 01:49–03:52 | Clientes con varias cuentas quedan revueltos |
| P10 | El reporte de saldos no muestra abonos, historial ni cuentas liquidadas | A2 21:12–22:40 | No se sabe cuánto ha pagado un cliente ni cuándo liquidó |
| P11 | No existen proveedores en el sistema; las salidas de dinero no dicen a quién ni por qué | A1 10:07–11:31 · A2 03:41 | Pagos sin trazabilidad |
| P12 | Con la consigna hay que calcular a mano cuánto se debe a cada proveedor y qué productos cubre un pago global (p. ej. 100 mil a Ortitec: todo el Core, todo el Bigfix…) | A2 04:05–07:52 | Cálculos manuales de montos grandes |
| P13 | El cambio entregado no queda registrado, y las referencias de pago solo dicen "Visa" o "transferencia" | A1 11:39–13:39 | No cuadra la caja; no se sabe quién pagó cómo |
| P14 | No hay descuentos: un cliente tiene 10% fijo y a veces el dueño autoriza 5%; solo existe precio de mayoreo | A1 26:57–27:43 | Se cobra mal o se ajusta fuera del sistema |
| P15 | Solo imprime ticket; las notas de venta formales se hacen a mano | A2 00:13 | Retrabajo cuando el cliente pide nota |
| P16 | La facturación se hace aparte en el portal del SAT: hay que buscar la clave SAT de cada producto, si causa IVA o IEPS, y capturar los datos fiscales del cliente | A2 09:53–13:58, 24:46 | Proceso lento; la encargada no sabe bien cómo hacerlo |
| P17 | Los créditos son abiertos; el límite de crédito existe pero no se usa con consistencia | A2 19:27 | Riesgo de cartera sin control |
| P18 | Todo se registra tres veces (papel, Excel y programa) y además en varias computadoras con inventarios distintos | A1 12:31, 22:02 · A4 07:01 | Ninguno cuadra con el físico sin conteos constantes |
| P19 | Mayoría de productos sin código de barras; existe una lista de códigos que se captura a mano o con lector | A2 01:00–02:56 | Captura lenta al dar de alta |
| P20 | El programa actual tiene funciones bloqueadas por licencia (p. ej. importar desde Excel), está en una sola PC y conserva usuario y contraseña de quien lo instaló | A1 23:42 · A6 05:57 | Sin seguridad ni acceso desde otros equipos |
| P21 | Las devoluciones solo se anotan con fecha y lo que se llevaron | A6 00:42 | No se reintegran al inventario de forma controlada |
| P22 | No hay reportes de ganancias, reinversión o cierre de mes | A4 00:03 | Pendiente que el dueño defina cuáles necesita |

## Soluciones propuestas

La idea central es un solo catálogo de productos con existencias separadas por almacén y por dueño del producto. Así un traspaso es un solo movimiento, y no un producto duplicado con otro código.

| ID | Solución | Resuelve |
| --- | --- | --- |
| S1 | **Almacenes y propietarios.** Cada existencia se guarda por producto + almacén (bodega Ortitec, consigna Francisco, mostrador) + dueño. Un traspaso descuenta en origen y suma en destino en una sola operación, sin cambiar el código | P1, P18 |
| S2 | **Lotes por entrada.** Cada entrada de mercancía guarda proveedor, costo y fecha. La venta consume lotes (primero en entrar, primero en salir), así se sabe de quién era lo vendido | P2, P12 |
| S3 | **Kardex con notas.** Todo movimiento (entrada, salida, traspaso, ajuste, devolución) guarda usuario, fecha, nota libre, referencia, existencia anterior y existencia resultante. Se puede consultar el historial por producto, almacén o fecha | P3, P4, P5, P21 |
| S4 | **Entradas que suman solas.** Al registrar "llegaron 10", el sistema suma a la existencia; los ajustes por conteo físico quedan como movimiento aparte con motivo | P5, P18 |
| S5 | **Productos sin precio.** El precio de venta es opcional; un producto sin precio existe en inventario pero no se puede vender hasta que tenga uno | P6 |
| S6 | **Búsqueda rápida.** Buscador por nombre, código o código de barras, con filtros por almacén, proveedor y categoría | P7 |
| S7 | **Clientes con cuentas o destinos.** Un cliente puede tener varias cuentas (ranchos, ciudades). Cada venta se liga a una cuenta y lleva observaciones | P8 |
| S8 | **Cuentas por cobrar completas.** Los abonos se aplican a una cuenta o venta específica, con forma de pago, referencia y nota. El estado de cuenta muestra cargos, abonos, saldo y fecha de liquidación. El límite de crédito es configurable y se avisa al rebasarlo | P9, P10, P17 |
| S9 | **Proveedores y cuentas por pagar.** Catálogo de proveedores. La consigna genera automáticamente lo que se debe por lo vendido. Un pago global se reparte entre productos o entradas, y queda el saldo pendiente | P11, P12 |
| S10 | **Caja.** Cada cobro guarda forma de pago, referencia, monto recibido y cambio. Las salidas de efectivo exigen concepto y beneficiario. Corte de caja diario | P11, P13 |
| S11 | **Precios y descuentos.** Precio público, de mayoreo y descuento por cliente (fijo, p. ej. 10%) o por venta (p. ej. 5%, autorizado por el dueño) | P14 |
| S12 | **Documentos de venta.** Ticket y nota de venta formal imprimibles o en PDF desde la misma venta | P15 |
| S13 | **Datos fiscales y facturación.** El producto guarda clave SAT, unidad SAT, IVA e IEPS; el cliente guarda RFC, razón social, régimen, código postal y uso de CFDI. La factura se arma desde las ventas y se timbra con Facturapi | P16 |
| S14 | **Códigos de barras.** Lectura con escáner, captura manual y generación e impresión de etiquetas internas para productos sin código | P19 |
| S15 | **Sistema web centralizado.** Una sola base de datos accesible desde cualquier equipo, con usuarios propios, respaldos automáticos e importación inicial desde Excel | P18, P20 |
| S16 | **Reportes.** Ventas, utilidad (venta menos costo del lote), inventario valorizado, saldos de clientes y proveedores, movimientos por destino. Los demás los define el dueño | P8, P22 |

## Requerimientos funcionales

Son 40 requerimientos en 10 módulos. La prioridad es una propuesta: Alta es lo que el cliente señaló como dolor diario.

| ID | Módulo | El sistema debe… | Prioridad | Origen |
| --- | --- | --- | --- | --- |
| RF-01 | Productos | Registrar productos con código interno, código de barras, nombre, marca, presentación (p. ej. 20 L), categoría y unidad | Alta | S5, S14 |
| RF-02 | Productos | Permitir productos sin precio de venta y bloquear su venta hasta que lo tengan | Alta | S5 |
| RF-03 | Productos | Manejar costo, precio público y precio de mayoreo por producto | Alta | S11 |
| RF-04 | Productos | Guardar stock mínimo por producto y almacén, y alertar al llegar a él | Media | S3 |
| RF-05 | Productos | Guardar clave de producto SAT, unidad SAT y si causa IVA o IEPS (tasa) | Media | S13 |
| RF-06 | Productos | Importar productos y existencias desde Excel con una plantilla | Alta | S15 |
| RF-07 | Productos | Leer códigos con escáner y generar e imprimir etiquetas para productos sin código | Media | S14 |
| RF-08 | Inventario | Manejar varios almacenes (mostrador, bodega Ortitec, consigna Francisco y los que se agreguen) | Alta | S1 |
| RF-09 | Inventario | Llevar la existencia por producto, almacén y dueño (propio o de un proveedor en consigna) | Alta | S1 |
| RF-10 | Inventario | Hacer traspasos entre almacenes en una sola operación, con nota obligatoria | Alta | S1 |
| RF-11 | Inventario | Registrar entradas con proveedor, costo, cantidad, fecha y nota, generando un lote | Alta | S2, S4 |
| RF-12 | Inventario | Registrar salidas que no son venta (muestras, experimentos, préstamos) con motivo y responsable | Alta | S3 |
| RF-13 | Inventario | Registrar ajustes por conteo físico con motivo | Media | S4 |
| RF-14 | Inventario | Mostrar el kardex por producto: cada movimiento con existencia anterior, cantidad, existencia resultante, usuario y nota | Alta | S3 |
| RF-15 | Inventario | Registrar préstamos de consigna (p. ej. 3 garrafas de Francisco) y su reposición pendiente | Media | S1, S3 |
| RF-16 | Inventario | Registrar devoluciones de clientes que regresen el producto al inventario | Media | S3 |
| RF-17 | Ventas | Buscar productos por nombre, código o código de barras con filtros | Alta | S6 |
| RF-18 | Ventas | Ligar cada venta a cliente, cuenta o destino, almacén de salida y observaciones | Alta | S7 |
| RF-19 | Ventas | Aplicar precio de mayoreo, descuento fijo por cliente y descuento por venta con autorización | Alta | S11 |
| RF-20 | Ventas | Cobrar en efectivo, tarjeta, transferencia o crédito, y combinaciones, guardando referencia | Alta | S10 |
| RF-21 | Ventas | Registrar monto recibido y cambio entregado | Media | S10 |
| RF-22 | Ventas | Imprimir ticket y generar nota de venta formal (impresa o PDF) | Alta | S12 |
| RF-23 | Ventas | Calcular IVA e IEPS por partida y el total con impuestos | Media | S13 |
| RF-24 | Clientes | Registrar clientes de contado y crédito con datos de contacto y fiscales (RFC, razón social, régimen, CP, uso CFDI) | Alta | S7, S13 |
| RF-25 | Clientes | Permitir varias cuentas o destinos por cliente | Alta | S7 |
| RF-26 | Clientes | Definir límite de crédito opcional y avisar al rebasarlo | Media | S8 |
| RF-27 | Cobranza | Registrar abonos aplicados a una cuenta o venta, con forma de pago, referencia y nota | Alta | S8 |
| RF-28 | Cobranza | Mostrar estado de cuenta con cargos, abonos, saldo y cuentas liquidadas, e imprimirlo | Alta | S8 |
| RF-29 | Proveedores | Registrar proveedores y su modalidad (consigna o compra) | Alta | S9 |
| RF-30 | Proveedores | Calcular lo que se debe a cada proveedor en consigna según lo vendido de sus lotes | Alta | S9 |
| RF-31 | Proveedores | Registrar pagos a proveedores y repartir un pago global entre productos o lotes, mostrando saldo pendiente | Alta | S9 |
| RF-32 | Caja | Registrar salidas de efectivo con concepto y beneficiario | Alta | S10 |
| RF-33 | Caja | Hacer corte de caja con totales por forma de pago | Media | S10 |
| RF-34 | Facturación | Preparar los datos de factura desde una o varias ventas | Media | S13 |
| RF-35 | Facturación | Timbrar CFDI 4.0 mediante un PAC (Facturapi) | Baja | S13 |
| RF-36 | Reportes | Reporte de ventas por periodo, cliente, destino y producto | Alta | S16 |
| RF-37 | Reportes | Reporte de existencias e inventario valorizado por almacén y dueño | Alta | S16 |
| RF-38 | Reportes | Reporte de saldos de clientes y de proveedores | Alta | S16 |
| RF-39 | Reportes | Reporte de utilidad (venta menos costo del lote) | Media | S16 |
| RF-40 | Usuarios | Manejar usuarios con roles (dueño, encargada) y permisos fijos por rol. Autorizar descuentos y cambiar precios lo pueden hacer los dos (decidido el 19 nov 2026) | Alta | S15 |

## Requerimientos no funcionales

| ID | Requerimiento | Origen |
| --- | --- | --- |
| RNF-01 | Una sola base de datos central, accesible desde varias computadoras del negocio | P18, P20 |
| RNF-02 | Usuarios con contraseña propia y bitácora de quién hizo cada movimiento | P3, P20 |
| RNF-03 | Respaldo automático diario y posibilidad de restaurar | P20 |
| RNF-04 | Compatible con lector de código de barras (USB) e impresora térmica de tickets | P15, P19 |
| RNF-05 | Interfaz sencilla y en español; registrar una venta o un traspaso en pocos pasos | P18 |
| RNF-06 | Ningún movimiento se borra: los errores se corrigen con un movimiento inverso | P3, P18 |
| RNF-07 | Migración inicial de productos, existencias y saldos desde el programa actual o Excel | P20 |
| RNF-08 | Cálculos de impuestos conforme a las reglas vigentes del SAT (IVA, IEPS, CFDI 4.0) | P16 |
| RNF-09 | Que siga operando si falla internet, o definir con el cliente si es aceptable depender de la conexión | P20 |

## Preguntas abiertas para el cliente

- [ ] ¿Qué reportes quiere el dueño al cierre de mes (ganancias, reinversión, otros)? Quedó pendiente en el audio 4.
- [ ] Las "cuentas" de un cliente (Reynosa, "Jalincher", etc.), ¿son ranchos, sucursales o personas distintas?
- [ ] ¿Cuántos almacenes físicos hay además de Mostrador, Bodega Ortitec y Consigna Francisco?
- [ ] Con Ortitec, ¿el pago es por lo vendido (consigna) o por lo que se toma de su bodega aunque no se venda?
- [ ] ¿La consigna de Francisco se maneja igual que la de un proveedor, o es un préstamo que se repone en especie? ¿Qué costo se usa para su producto?
- [ ] ¿Ya tienen e.firma o certificado de sello digital para timbrar con Facturapi?
- [ ] ¿En cuántas computadoras se va a usar y tienen internet estable en todas? ¿Es aceptable depender de la conexión?
- [ ] ¿Cómo manejan hoy las devoluciones y deben generar nota de crédito?
- [ ] ¿Se puede obtener la lista de códigos de barras y un Excel con los productos y existencias actuales para la migración?

## Preguntas para el contador

- [ ] Tasas de IVA e IEPS de cada tipo de producto.
- [ ] Claves de producto y unidad SAT de los productos principales.
- [ ] Plazo para emitir el complemento de pago de facturas a crédito.

*Fuente: transcripciones automáticas de 7 grabaciones (audios 1, 2, 4, 5, 6, 7 y 8; el 3 no tenía contenido). Algunos nombres propios pueden estar mal escritos.*
