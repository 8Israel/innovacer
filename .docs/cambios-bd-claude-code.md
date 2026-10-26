# Cambios a la base de datos — instrucciones para Claude Code

Proyecto: Sistema de Inventario y Punto de Venta de Innovacer (Laravel + MySQL).
Objetivo: ajustar la BD actual (35 tablas, documentada en `docs/base-de-datos.md`) para que cumpla con el diseño (`docs/diseno/`) y los requerimientos (`docs/requerimientos.md`).

## Cómo usar este archivo

1. Guárdalo en el repo como `docs/cambios-bd.md`.
2. Abre Claude Code en el proyecto y dale **una tarea a la vez**, en orden. Ejemplo:
   > "Lee docs/cambios-bd.md y haz la Tarea 1. Primero muéstrame el plan (migraciones, modelos y pruebas que vas a tocar). Cuando lo apruebe, impleméntalo, corre `php artisan test` y corrige hasta que todo pase."
3. Revisa el plan antes de aprobarlo, revisa las migraciones y haz un commit por tarea.

## Reglas generales (aplican a todas las tareas)

- **No edites migraciones que ya se corrieron.** Crea migraciones nuevas para cada cambio. Si la BD solo existe en tu máquina y no tiene datos reales, puedes decidir rehacerlas, pero dilo explícitamente en el plan.
- **Nada de dinero ni inventario se borra.** Los errores se corrigen con movimientos inversos. Las relaciones de tablas de movimientos usan `restrict`, no `cascade`.
- **Toda operación que toque existencias, saldos o caja va en `DB::transaction()`** y bloquea las filas que modifica con `lockForUpdate()`.
- **Cada tarea incluye pruebas automáticas** (Feature o Unit) que cubran los criterios de "Debe pasar". Usa `RefreshDatabase`.
- **Dinero** en `decimal(12,2)`, **cantidades** en `decimal(12,3)`, **tasas** en `decimal(5,4)`.
- Actualiza `docs/base-de-datos.md` al terminar cada tarea.

---

# Parte 1 · Antes de programar la fase 1

## Tarea 1 · Sesiones de caja (abrir y cerrar caja)

**Por qué:** el diseño tiene "Abrir caja", "Caja abierta" y un corte contra el fondo inicial. Hoy solo existe `cash_closings`, que se crea al cerrar.

**Migraciones**

- Crear `cash_sessions`:
  - `id`
  - `opened_by_id` → `users` (restrict)
  - `opened_at` (timestamp)
  - `opening_cash` decimal(12,2)
  - `opening_note` text nulo (obligatoria en la app si el fondo no coincide con el que dejó el corte anterior)
  - `closed_by_id` → `users` nulo (restrict)
  - `closed_at` timestamp nulo
  - `expected_cash` decimal(12,2) nulo
  - `counted_cash` decimal(12,2) nulo
  - `cash_difference` decimal(12,2) nulo
  - `card_total`, `transfer_total`, `credit_total` decimal(12,2) default 0
  - `closing_note` text nulo
  - `status`: `open` | `closed`
  - timestamps
  - Índice en `status`.
- Agregar `cash_session_id` (nulo, → `cash_sessions`, restrict) a: `sale_payments`, `customer_payments`, `customer_returns`, `cash_movements`, `supplier_payments`.
- Migrar los datos de `cash_closings` a `cash_sessions` (si hay) y eliminar `cash_closings`.

**Servicio** `CashSessionService`:

- `open(user, openingCash, note)`
- `current()`
- `expectedCash(session)`
- `close(user, countedCash, note)`

**Fórmula del efectivo esperado:**

```
fondo inicial
+ sale_payments en efectivo (campo amount, NO received_amount)
+ customer_payments en efectivo
+ cash_movements tipo inflow
- cash_movements tipo outflow
- customer_returns con refund_method = cash
- supplier_payments en efectivo con cash_session_id
```

**Debe pasar:**

- Solo puede haber una sesión `open` a la vez.
- No se puede registrar una venta con pago, un abono o una salida de efectivo si no hay caja abierta.
- El esperado del ejemplo del diseño da $15,550.00:
  - fondo 1,000
  - ventas en efectivo 14,230
  - abonos 1,500
  - salidas 1,180
- Cerrar con diferencia distinta de 0 sin nota lanza error de validación.
- Una sesión cerrada no acepta más movimientos.

## Tarea 2 · Ventas pendientes

**Por qué:** el punto de venta tiene "Dejar pendiente" y una lista para retomarlas.

**Migración:**

- Agregar `pending` como valor válido de `sales.status`.
- Permitir que `sales.sold_at` sea nulo mientras está pendiente.
- Agregar `held_at` (timestamp nulo) y `hold_note` (varchar nulo).

**Reglas:**

- Una venta `pending` **no** tiene `inventory_operation_id`, **no** tiene pagos y **no** mueve existencias.
- Al cobrarla:
  - Se revalidan existencia y precios.
  - Se crea la operación de inventario.
  - Se registran los pagos.
  - Pasa a `completed` con `sold_at = now()`.
- Descartar una venta pendiente sí puede borrarla, junto con sus `sale_items`, porque nunca afectó inventario ni caja.

**Debe pasar:**

- Guardar pendiente no cambia `stocks`.
- Retomar y cobrar descuenta existencias.
- Si al retomar ya no alcanza la existencia, lanza error y no cobra.
- No se puede descartar una venta `completed`.

## Tarea 3 · Clientes de crédito

**Por qué:** hoy cualquier cliente puede comprar a crédito, porque `credit_limit` vacío significa "sin límite".

**Migración:** agregar `customers.credit_enabled` boolean, default `false`.

**Reglas:**

- Si `credit_enabled = false`, una venta no puede quedar con saldo pendiente: los pagos deben cubrir el total.
- Si `credit_enabled = true` y `credit_limit` tiene valor, la venta que rebase el límite requiere autorización del dueño (ver Tarea 5).
- `credit_limit` vacío con `credit_enabled = true` = crédito sin límite.

**Debe pasar:**

- Un cliente de contado no puede dejar saldo.
- Ernesto Castaño (límite $50,000, debe $3,200) no puede cerrar una venta de $48,900 a crédito sin autorización.

## Tarea 4 · Aplicar un abono a varias ventas

**Por qué:** en el diseño, un abono de $5,000 liquida la venta #0981 ($2,000) y abona $3,000 a la #1012. Hoy `customer_payments.sale_id` solo permite una venta.

**Migraciones:**

- Crear `customer_payment_allocations`:
  - `id`
  - `customer_payment_id` → `customer_payments` (restrict)
  - `sale_id` → `sales` (restrict)
  - `amount` decimal(12,2)
  - timestamps
  - Índice único (`customer_payment_id`, `sale_id`).
- Migrar los `customer_payments` que tengan `sale_id` a una asignación cada uno.
- Después, eliminar `customer_payments.sale_id`.

**Definiciones (crea un servicio `CustomerBalanceService`):**

- **Pendiente de una venta** =
  - `sales.total`
  - − suma de `sale_payments.amount`
  - − suma de `customer_payment_allocations.amount` de esa venta
  - − devoluciones de esa venta con `refund_method = account_credit`
- **Saldo de una cuenta (destino)** = suma de pendientes de sus ventas `completed`.
- **Saldo del cliente** = suma de pendientes de todas sus ventas `completed`.

**Reglas:**

- Al registrar un abono se elige la cuenta (`customer_destination_id`) y se reparte automáticamente: primero se liquida la venta más antigua con saldo de esa cuenta, luego la siguiente.
- La suma de las asignaciones no puede ser mayor al monto del abono.
- Una venta no puede recibir más de su pendiente.

**Debe pasar:** con los datos del diseño (cuenta Reynosa: #0981 pendiente $2,000 y #1012 pendiente $6,200), un abono de $5,000 deja la #0981 en $0, la #1012 en $3,200 y el saldo de Reynosa en $3,200.

## Tarea 5 · Autorizaciones y cancelaciones

**Por qué:** hoy `sales.user_id` significa a la vez "quién vendió" y "quién autorizó el descuento". Además, falta registrar quién canceló y por qué.

**Migración en `sales`:**

- `authorized_by_id` → `users` nulo (restrict)
- `authorization_reason` varchar nulo
- `canceled_by_id` → `users` nulo (restrict)
- `cancel_reason` varchar nulo

**Reglas (servicio `SaleService`):**

- **Descuento extra** (`sales.discount_rate > 0`): requiere `authorized_by_id` de un usuario con rol `owner`. Se valida su contraseña en el momento.
- **Venta que rebasa el límite de crédito:** misma regla.
- **Cancelar:** requiere dueño y motivo, y hace lo siguiente:
  - Crea una operación de inventario tipo `reversal` que regresa el producto a los mismos lotes y almacén.
  - Si hubo pago en efectivo, registra la salida en la caja abierta.
  - Marca la venta como `canceled` con `canceled_at`, `canceled_by_id` y `cancel_reason`.
- No se puede cancelar una venta que ya está facturada (primero se cancela la factura).

**Debe pasar:**

- Cancelar la venta #1043 (2 Ultra Nitrógeno, $2,500 en efectivo) regresa 2 piezas a Mostrador y saca $2,500 de caja.
- Una encargada no puede cancelar ni autorizar.

## Tarea 6 · Usuarios y permisos

**Por qué:** el diseño pide "usuario" (no correo) para entrar, contraseñas temporales y permisos por rol.

**Migración en `users`:**

- `username` varchar único
- `must_change_password` boolean default false
- `is_active` boolean default true

**Reglas:**

- Se inicia sesión con `username`. El correo pasa a ser opcional (cámbialo a nulo) si el cliente no lo usa.
- Si `must_change_password = true`, después de entrar se le manda a cambiar contraseña antes de cualquier otra pantalla.
- Un usuario con `is_active = false` no puede entrar.

**Permisos. DECISIÓN PENDIENTE, pregúntamela antes de implementar:**

- **Opción A (recomendada si el cliente quiere ajustar permisos):** instalar `spatie/laravel-permission`, con los roles `owner` y `clerk` y estos permisos:
  - `sell`
  - `manage_inventory`
  - `register_payments`
  - `register_returns`
  - `invoice`
  - `change_prices`
  - `authorize_discounts`
  - `cancel_sales`
  - `pay_suppliers`
  - `view_reports`
  - `manage_settings`
- **Opción B (más simple):** permisos fijos por rol en una `Policy`/`Gate`, sin tabla editable. En ese caso se quita la tabla de permisos de la pantalla de Configuración.

**Debe pasar:**

- La encargada no ve ni puede abrir Reportes ni Configuración (403 o redirección al punto de venta).
- Un usuario inactivo no entra.
- Una contraseña temporal obliga a cambiarla.

## Tarea 7 · Ajustes menores

- `products.tax_rate`: quitar el default 0.1600 y permitir nulo. La app obliga a elegir la tasa al dar de alta (confirmar tasas con el contador).
- `products`: agregar `wholesale_min_quantity` decimal(12,3) nulo ("mayoreo desde N piezas").
- `invoice_items.quantity`: cambiar a decimal(12,3).
- `sale_items.sale_id` y `sale_payments.sale_id`: cambiar de `cascade` a `restrict`.
- `stocks`: agregar restricción `CHECK (quantity >= 0)` con `DB::statement` (requiere MySQL 8.0.16 o mayor; si la versión es menor, documentarlo y validar solo en la app).

**Debe pasar:** intentar dejar una existencia negativa falla tanto en el servicio como en la BD.

## Tarea 8 · Movimientos de caja y pagos a proveedores

**Por qué:** las salidas de caja necesitan categoría. Además, un pago en efectivo a un proveedor no debe contarse dos veces.

**Migración en `cash_movements`:**

- `category`: `supplier_expense` | `expense` | `owner_withdrawal` | `other`
- `supplier_id` → `suppliers` nulo (restrict)

**Regla para no duplicar:**

- **Pago de deuda** a un proveedor (consigna o compra) → se registra en `supplier_payments`. Si es en efectivo y sale de caja, lleva `cash_session_id`.
- **Gasto con un proveedor que no es deuda** (por ejemplo, el flete de Coda) → se registra en `cash_movements` con `category = supplier_expense` y `supplier_id`.
- Un mismo pago **nunca** se registra en ambas tablas.

**Debe pasar:**

- Un pago de deuda a Ortitec en efectivo resta del esperado de caja una sola vez.
- El flete de Coda ($780) aparece en salidas de caja y no reduce la deuda con Coda.

## Tarea 9 · Datos del negocio

**Migración:** crear `settings`:

- `id`
- `key` varchar único
- `value` text nulo
- timestamps

**Llaves iniciales:**

- `business_name`
- `business_address`
- `business_phone`
- `business_rfc`
- `ticket_printer_width` (80 o 58)

Agrega un helper `setting('business_phone')` con caché que se limpie al guardar.

**Debe pasar:** el ticket y la nota de venta muestran los datos guardados.

---

# Parte 2 · Fases 2 y 3 (hacer cuando se llegue a ellas)

## Tarea 10 · Conteo físico

**Migraciones:**

- Crear `physical_counts`:
  - `id`
  - `warehouse_id` → `warehouses` (restrict)
  - `started_by_id` → `users` (restrict)
  - `started_at`
  - `status`: `in_progress` | `applied` | `canceled`
  - `applied_by_id` → `users` nulo
  - `applied_at` nulo
  - `inventory_operation_id` → `inventory_operations` nulo
  - `note` nulo
  - timestamps
- Crear `physical_count_items`:
  - `id`
  - `physical_count_id` (cascade)
  - `product_id` (restrict)
  - `system_quantity` decimal(12,3)
  - `counted_quantity` decimal(12,3) nulo
  - timestamps
  - Índice único (`physical_count_id`, `product_id`).

**Reglas:**

- Solo un conteo `in_progress` por almacén.
- Se puede guardar y seguir después.
- Al aplicar se crea **una** operación tipo `adjustment` con motivo "Conteo físico" y un movimiento por cada producto con diferencia. Lo no contado (`counted_quantity` nulo) no se toca.

**Debe pasar:** con el ejemplo del diseño, al aplicar se generan exactamente 2 movimientos: Ultra Nitrógeno −2 y Bigfix +1.

## Tarea 11 · Facturación: folio fiscal, cancelación y complemento de pago

**Migración en `invoices`:**

- `uuid` varchar(36) nulo único
- `cancellation_reason` varchar(2) nulo (motivos SAT 01–04)
- `substitution_uuid` varchar(36) nulo
- `type`: `income` | `payment` (default `income`)

**Crear `invoice_payments`** (complementos de pago):

- `id`
- `invoice_id` → `invoices` (restrict). Es el CFDI de tipo pago.
- `related_invoice_id` → `invoices` (restrict). Es la factura PPD que se paga.
- `customer_payment_id` → `customer_payments` (restrict)
- `installment` smallint (número de parcialidad)
- `previous_balance` decimal(12,2)
- `amount_paid` decimal(12,2)
- `outstanding_balance` decimal(12,2)
- timestamps

**Reglas:**

- Una factura PPD muestra "falta complemento" mientras tenga abonos sin `invoice_payments`.
- La parcialidad es consecutiva por factura.
- `outstanding_balance = previous_balance − amount_paid`.

**Debe pasar:** con el ejemplo del diseño (factura A-0002 por $3,200 y abono de $1,500), el complemento queda con parcialidad 1, saldo anterior $3,200 y saldo pendiente $1,700.

---

## Datos de prueba (seeder)

Al terminar la Parte 1, crea un `DemoSeeder` con los datos de ejemplo del diseño, para probar con el cliente usando nombres que reconoce:

- **Almacenes:** Mostrador Innovacer, Bodega Ortitec, Consigna Francisco.
- **Proveedores:** Ortitec (consigna), Biotexa, Coda, NH, TN, Tierra Verde, Francisco Rodríguez (en especie).
- **Productos:** Ultra Nitrógeno 20 L, Ultra Calcio 20 L, Ultra Magnesio 20 L, Core 20 L, Bigfix 1 L, Calcio 1 L, Mirabis 1 L y Manguera de riego 16 mm (sin precio).
- **Clientes:** Francisco Castañón (crédito, 10% de descuento, cuentas Reynosa y Jalincher), Ernesto Castaño (crédito con límite de $50,000), Ing. Meléndez (crédito), Alejandro Carrasco (contado).
- **Usuarios:** `dueno` (owner) y `encargada` (clerk), ambos con `must_change_password = true`.
