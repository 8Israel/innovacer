# Base de datos — Sistema de Inventario y Punto de Venta

Desglose de las 35 tablas de la base de datos `innovacer`: para qué sirve cada tabla, qué guarda cada campo, su traducción al español, el tipo de dato y sus reglas. Generado a partir de la estructura real de la base de datos el 13/10/2026.

## Cómo leer este documento

| Columna | Significado |
|---|---|
| **Campo** | Nombre real de la columna en la base de datos (en inglés). |
| **Traducción** | Nombre del campo en español. |
| **Tipo** | Tipo de dato (ver tabla de tipos abajo). |
| **Nulo** | *Sí* = el campo puede quedar vacío. *No* = siempre debe tener valor. |
| **Por defecto** | Valor que toma si no se indica otro. |
| **Reglas** | Llave primaria, valores únicos, relaciones con otras tablas e índices. |

**Tipos de dato:**

| Tipo | Qué guarda |
|---|---|
| `bigint unsigned` | Número entero positivo (identificadores y relaciones). |
| `int`, `smallint`, `tinyint` | Número entero. |
| `tinyint(1)` | Sí/No (1 = sí, 0 = no). |
| `varchar(n)` | Texto corto de hasta *n* caracteres. |
| `text`, `longtext`, `mediumtext` | Texto largo. |
| `decimal(p,e)` | Número con decimales exactos: *p* dígitos en total, *e* después del punto. `decimal(12,2)` = dinero (hasta 9,999,999,999.99); `decimal(12,3)` = cantidades (permite 2.5 L); `decimal(5,4)` = tasas como fracción (0.1600 = 16 %). |
| `timestamp`, `datetime` | Fecha y hora. |
| `date` | Solo fecha. |

**Reglas de relación (al borrar el registro relacionado):** *restringir* = no se puede borrar mientras se use; *en cascada* = se borra junto; *poner vacío* = el campo queda vacío. En la práctica el sistema no borra movimientos: los errores se corrigen con movimientos inversos.

## Índice

- **Usuarios y acceso:** [`users`](#users), [`passkeys`](#passkeys), [`password_reset_tokens`](#password_reset_tokens), [`sessions`](#sessions)
- **Catálogos:** [`categories`](#categories), [`products`](#products), [`suppliers`](#suppliers), [`warehouses`](#warehouses), [`stock_minimums`](#stock_minimums)
- **Inventario:** [`inventory_operations`](#inventory_operations), [`lots`](#lots), [`stocks`](#stocks), [`inventory_movements`](#inventory_movements), [`loan_entries`](#loan_entries)
- **Clientes:** [`customers`](#customers), [`customer_destinations`](#customer_destinations)
- **Ventas:** [`sales`](#sales), [`sale_items`](#sale_items), [`sale_payments`](#sale_payments)
- **Devoluciones:** [`customer_returns`](#customer_returns), [`customer_return_items`](#customer_return_items)
- **Cobranza:** [`customer_payments`](#customer_payments)
- **Caja:** [`cash_movements`](#cash_movements), [`cash_sessions`](#cash_sessions)
- **Proveedores:** [`supplier_payments`](#supplier_payments), [`supplier_payment_allocations`](#supplier_payment_allocations)
- **Facturación:** [`invoices`](#invoices), [`invoice_items`](#invoice_items), [`invoice_sale`](#invoice_sale)
- **Tablas internas de Laravel:** [`migrations`](#migrations), [`cache`](#cache), [`cache_locks`](#cache_locks), [`jobs`](#jobs), [`job_batches`](#job_batches), [`failed_jobs`](#failed_jobs)

---

## Usuarios y acceso

### users

**Usuarios.** Personas que entran al sistema (dueño y encargada). Cada movimiento guarda qué usuario lo hizo, así queda la bitácora (RNF-02).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `name` | Nombre | `varchar(255)` | No | — | — | Nombre de la persona. |
| `email` | Correo | `varchar(255)` | No | — | Único | Correo con el que inicia sesión. No se puede repetir. |
| `role` | Rol | `varchar(255)` | No | `clerk` | — | Tipo de usuario. Valores: `owner` (dueño), `clerk` (encargada). Los nuevos quedan como encargada. |
| `email_verified_at` | Correo verificado el | `timestamp` | Sí | — | — | Fecha en que se confirmó el correo. Vacío si no se ha verificado. |
| `password` | Contraseña | `varchar(255)` | No | — | — | Contraseña cifrada (nunca se guarda en texto plano). |
| `two_factor_secret` | Secreto de doble factor | `text` | Sí | — | — | Clave cifrada para la verificación en dos pasos. Vacío si no la usa. |
| `two_factor_recovery_codes` | Códigos de recuperación | `text` | Sí | — | — | Códigos cifrados para entrar si se pierde el celular de doble factor. |
| `two_factor_confirmed_at` | Doble factor confirmado el | `timestamp` | Sí | — | — | Fecha en que activó la verificación en dos pasos. |
| `remember_token` | Token de "recordarme" | `varchar(100)` | Sí | — | — | Permite mantener la sesión abierta en el equipo. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### passkeys

**Llaves de acceso.** Llaves de acceso (huella, rostro o llave física) registradas por cada usuario para entrar sin contraseña.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `user_id` | Usuario | `bigint unsigned` | No | — | Relación → `users.id` (al borrar: en cascada) | Usuario dueño de la llave. |
| `name` | Nombre | `varchar(255)` | No | — | — | Nombre que el usuario le puso a la llave (p. ej. "Laptop mostrador"). |
| `credential_id` | ID de credencial | `varchar(255)` | No | — | Único | Identificador de la credencial que entrega el dispositivo. |
| `credential` | Credencial | `json` | No | — | — | Datos públicos de la credencial para verificarla. |
| `last_used_at` | Último uso | `timestamp` | Sí | — | — | Última vez que se usó para entrar. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### password_reset_tokens

**Tokens de recuperación de contraseña.** Códigos temporales que se envían por correo para restablecer la contraseña.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `email` | Correo | `varchar(255)` | No | — | Llave primaria | Correo del usuario que pidió el cambio. |
| `token` | Token | `varchar(255)` | No | — | — | Código cifrado enviado por correo. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Cuándo se generó; caduca después de un tiempo. |

### sessions

**Sesiones.** Sesiones abiertas en cada computadora o navegador.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `varchar(255)` | No | — | Llave primaria | Identificador de la sesión. |
| `user_id` | Usuario | `bigint unsigned` | Sí | — | — | Usuario de la sesión. Vacío si aún no inicia sesión. |
| `ip_address` | Dirección IP | `varchar(45)` | Sí | — | — | IP desde donde se conectó. |
| `user_agent` | Navegador | `text` | Sí | — | — | Navegador y sistema del equipo. |
| `payload` | Contenido | `longtext` | No | — | — | Datos internos de la sesión. |
| `last_activity` | Última actividad | `int` | No | — | — | Momento de la última actividad (marca de tiempo Unix). |

---

## Catálogos

### categories

**Categorías.** Categorías para agrupar y filtrar productos (p. ej. fertilizantes, insecticidas).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `name` | Nombre | `varchar(255)` | No | — | Único | Nombre de la categoría. No se puede repetir. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### products

**Productos.** Catálogo único de productos. Un producto nunca se duplica por almacén ni por proveedor: las existencias van en `stocks` y `lots`. Un producto sin precio existe en inventario pero no se puede vender (RF-02).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `name` | Nombre | `varchar(255)` | No | — | — | Nombre del producto. |
| `brand` | Marca | `varchar(255)` | Sí | — | — | Marca o fabricante. |
| `presentation` | Presentación | `varchar(255)` | Sí | — | — | Tamaño o empaque, p. ej. "20 L", "1 kg". |
| `category_id` | Categoría | `bigint unsigned` | Sí | — | Relación → `categories.id` (al borrar: poner vacío) | Categoría a la que pertenece. |
| `unit` | Unidad | `varchar(255)` | No | `pieza` | — | Unidad interna con la que se cuenta (pieza, garrafa, caja, litro). |
| `description` | Descripción | `text` | Sí | — | — | Descripción libre. |
| `sku` | Código interno | `varchar(255)` | No | — | Único | Código propio del negocio. No se puede repetir. |
| `barcode` | Código de barras | `varchar(255)` | Sí | — | Único | Código de barras del fabricante o generado internamente. No se puede repetir. |
| `reference_cost` | Costo de referencia | `decimal(12,2)` | Sí | — | — | Costo aproximado del producto. Se usa cuando la mercancía no tiene costo propio (p. ej. la tomada de la consigna de Francisco; tema pendiente con el cliente). |
| `unit_price` | Precio público | `decimal(12,2)` | Sí | — | — | Precio de venta normal. Vacío = no se puede vender todavía. |
| `wholesale_price` | Precio de mayoreo | `decimal(12,2)` | Sí | — | — | Precio para venta de mayoreo. |
| `tax_rate` | Tasa de IVA | `decimal(5,4)` | No | `0.1600` | — | IVA que causa, como fracción: 0.1600 = 16 %, 0 = exento o tasa cero. |
| `ieps_rate` | Tasa de IEPS | `decimal(5,4)` | Sí | — | — | IEPS que causa, como fracción. Vacío si no aplica. |
| `product_key` | Clave de producto SAT | `varchar(8)` | Sí | — | — | Clave del catálogo del SAT para facturar (8 dígitos). |
| `product_key_name` | Descripción de la clave SAT | `varchar(255)` | Sí | — | — | Nombre de la clave SAT, para mostrarla sin buscarla. |
| `unit_key` | Clave de unidad SAT | `varchar(3)` | Sí | — | — | Clave de unidad del SAT (p. ej. H87 = pieza). |
| `unit_name` | Nombre de la unidad SAT | `varchar(255)` | Sí | — | — | Nombre de la clave de unidad SAT. |
| `is_active` | Activo | `tinyint(1)` | No | `1` | — | Si el producto se sigue usando. Los inactivos se ocultan sin borrarse. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### suppliers

**Proveedores.** Proveedores y prestamistas de mercancía. Su tipo de liquidación define cómo se les paga.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `name` | Nombre | `varchar(255)` | No | — | — | Nombre del proveedor (Biotexa, Coda, NH, TN, Tierra Verde, Ortitec, Francisco Rodríguez…). |
| `settlement_type` | Tipo de liquidación | `varchar(255)` | No | — | — | Cómo se le paga. Valores: `purchase` (compra: se paga al recibir o a crédito), `consignment` (consigna: se paga en dinero solo lo vendido; p. ej. Ortitec), `in_kind` (en especie: se le repone el mismo producto; Francisco). |
| `rfc` | RFC | `varchar(13)` | Sí | — | — | RFC del proveedor. |
| `phone` | Teléfono | `varchar(255)` | Sí | — | — | Teléfono de contacto. |
| `email` | Correo | `varchar(255)` | Sí | — | — | Correo de contacto. |
| `notes` | Notas | `text` | Sí | — | — | Observaciones libres. |
| `is_active` | Activo | `tinyint(1)` | No | `1` | — | Si se sigue trabajando con él. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### warehouses

**Almacenes.** Lugares donde hay mercancía: mostrador, bodega Ortitec, consigna Francisco y los que se agreguen (RF-08). Si el almacén tiene prestamista (consigna Francisco), todo lo que hay en él es de ese prestamista. La bodega Ortitec no tiene prestamista: su mercancía es de Ortitec porque sus lotes tienen a Ortitec como proveedor dueño.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `name` | Nombre | `varchar(255)` | No | — | — | Nombre del almacén. |
| `code` | Código | `varchar(255)` | No | — | Único | Código corto del almacén. No se puede repetir. |
| `lender_id` | Prestamista | `bigint unsigned` | Sí | — | Relación → `suppliers.id` (al borrar: restringir) | Proveedor en especie dueño de lo que hay aquí (Francisco). Vacío si el almacén es del negocio. Sacar producto de un almacén con prestamista genera deuda en especie. |
| `is_sellable` | Se vende desde aquí | `tinyint(1)` | No | `0` | — | Si se puede vender directamente desde este almacén (solo el mostrador). |
| `is_active` | Activo | `tinyint(1)` | No | `1` | — | Si el almacén se sigue usando. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### stock_minimums

**Stocks mínimos.** Cantidad mínima deseada de un producto en un almacén, para avisar cuando se acabe (RF-04).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `product_id` | Producto | `bigint unsigned` | No | — | Relación → `products.id` (al borrar: en cascada) | Producto al que aplica. |
| `warehouse_id` | Almacén | `bigint unsigned` | No | — | Relación → `warehouses.id` (al borrar: en cascada) | Almacén al que aplica. |
| `min_quantity` | Cantidad mínima | `decimal(12,3)` | No | — | — | Al llegar a esta cantidad o menos, el sistema avisa. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

**Índices combinados:**

- `product_id` + `warehouse_id` — Único (no se puede repetir la combinación)

---

## Inventario

### inventory_operations

**Operaciones de inventario.** Encabezado de cada operación de inventario (entrada, traspaso, salida, ajuste, venta, devolución). Nunca se borra: un error se corrige con una operación de reversa (RNF-06).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `type` | Tipo | `varchar(255)` | No | — | — | Tipo de operación. Valores: `entry` (entrada), `transfer` (traspaso), `exit` (salida que no es venta), `adjustment` (ajuste por conteo), `sale` (venta), `return` (devolución de cliente), `reversal` (reversa de otra operación). |
| `user_id` | Usuario | `bigint unsigned` | No | — | Relación → `users.id` (al borrar: restringir) | Quién registró la operación. |
| `supplier_id` | Proveedor | `bigint unsigned` | Sí | — | Relación → `suppliers.id` (al borrar: restringir) | Proveedor que entregó la mercancía (en entradas). |
| `responsible` | Responsable | `varchar(255)` | Sí | — | — | Quién se llevó el producto en salidas (p. ej. "Ing. Meléndez"). |
| `reason` | Motivo | `varchar(255)` | Sí | — | — | Motivo de salidas y ajustes (muestra, experimento, conteo físico…). |
| `note` | Nota | `text` | Sí | — | — | Nota libre. Obligatoria en traspasos (se valida en la aplicación). |
| `reference` | Referencia | `varchar(255)` | Sí | — | — | Folio o documento relacionado (remisión del proveedor, etc.). |
| `reversal_of_id` | Reversa de | `bigint unsigned` | Sí | — | Relación → `inventory_operations.id` (al borrar: restringir) | Si esta operación corrige otra, aquí va la operación original. |
| `occurred_at` | Fecha de la operación | `timestamp` | No | — | — | Cuándo ocurrió realmente (puede ser distinta a cuándo se capturó). |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

**Índices combinados:**

- `type` + `occurred_at` — Índice (para buscar más rápido)

### lots

**Lotes.** Cada entrada de mercancía por separado: de quién vino, cuánto costó y cuándo llegó. Es contabilidad interna: físicamente el producto no se separa. Las salidas consumen primero los lotes más antiguos (PEPS).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `product_id` | Producto | `bigint unsigned` | No | — | Relación → `products.id` (al borrar: restringir) | Producto del lote. |
| `supplier_id` | Proveedor | `bigint unsigned` | Sí | — | Relación → `suppliers.id` (al borrar: restringir) | Quién trajo la mercancía. |
| `owner_supplier_id` | Proveedor dueño | `bigint unsigned` | Sí | — | Relación → `suppliers.id` (al borrar: restringir) | Proveedor dueño de la mercancía mientras no se venda (consigna, p. ej. Ortitec). Vacío = es del negocio. |
| `inventory_operation_id` | Operación de entrada | `bigint unsigned` | No | — | Relación → `inventory_operations.id` (al borrar: restringir) | Entrada que creó este lote. |
| `unit_cost` | Costo unitario | `decimal(12,2)` | Sí | — | — | Costo por unidad. Sirve para la utilidad y para saber cuánto se debe al proveedor. Vacío si no se conoce. |
| `quantity_received` | Cantidad recibida | `decimal(12,3)` | No | — | — | Cuánto llegó en esta entrada. |
| `received_at` | Recibido el | `timestamp` | No | — | — | Fecha de llegada; define el orden PEPS. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

**Índices combinados:**

- `product_id` + `received_at` — Índice (para buscar más rápido)

### stocks

**Existencias.** Cuánto hay de cada lote en cada almacén. La existencia de un producto en un almacén es la suma de sus renglones.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `warehouse_id` | Almacén | `bigint unsigned` | No | — | Relación → `warehouses.id` (al borrar: restringir) | Dónde está. |
| `lot_id` | Lote | `bigint unsigned` | No | — | Relación → `lots.id` (al borrar: restringir) | De qué lote es. |
| `product_id` | Producto | `bigint unsigned` | No | — | Relación → `products.id` (al borrar: restringir) | Producto (copiado del lote para buscar más rápido). |
| `quantity` | Cantidad | `decimal(12,3)` | No | `0.000` | — | Cantidad disponible. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

**Índices combinados:**

- `product_id` + `warehouse_id` — Índice (para buscar más rápido)
- `warehouse_id` + `lot_id` — Único (no se puede repetir la combinación)

### inventory_movements

**Movimientos de inventario (kardex).** Cada renglón del kardex: qué entró o salió, de qué almacén y lote, y cuánto había antes y después (RF-14). Nunca se borra.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `inventory_operation_id` | Operación | `bigint unsigned` | No | — | Relación → `inventory_operations.id` (al borrar: restringir) | Operación a la que pertenece. |
| `product_id` | Producto | `bigint unsigned` | No | — | Relación → `products.id` (al borrar: restringir) | Producto que se movió. |
| `warehouse_id` | Almacén | `bigint unsigned` | No | — | Relación → `warehouses.id` (al borrar: restringir) | Almacén afectado. |
| `lot_id` | Lote | `bigint unsigned` | No | — | Relación → `lots.id` (al borrar: restringir) | Lote afectado. |
| `sale_item_id` | Partida de venta | `bigint unsigned` | Sí | — | Relación → `sale_items.id` (al borrar: restringir) | Si el movimiento es por una venta, la partida vendida. Así se sabe de qué lote salió lo vendido. |
| `quantity` | Cantidad | `decimal(12,3)` | No | — | — | Positiva = entra, negativa = sale. |
| `stock_before` | Existencia anterior | `decimal(12,3)` | No | — | — | Total del producto en ese almacén antes del movimiento ("había 15"). |
| `stock_after` | Existencia resultante | `decimal(12,3)` | No | — | — | Total después del movimiento ("quedan 13"). |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

**Índices combinados:**

- `product_id` + `warehouse_id` + `id` — Índice (para buscar más rápido)

### loan_entries

**Movimientos de préstamo en especie.** Libreta de la deuda en producto con Francisco (y cualquier otro proveedor en especie): suma al tomar producto y resta al reponer. La deuda actual es la suma por prestamista y producto (RF-15).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `lender_id` | Prestamista | `bigint unsigned` | No | — | Relación → `suppliers.id` (al borrar: restringir) | A quién se le debe (proveedor en especie). |
| `product_id` | Producto | `bigint unsigned` | No | — | Relación → `products.id` (al borrar: restringir) | Producto prestado o repuesto. |
| `inventory_operation_id` | Operación | `bigint unsigned` | No | — | Relación → `inventory_operations.id` (al borrar: restringir) | Traspaso que originó el movimiento. |
| `quantity` | Cantidad | `decimal(12,3)` | No | — | — | Positiva = se tomó prestado, negativa = se repuso. |
| `note` | Nota | `varchar(255)` | Sí | — | — | Observaciones. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

**Índices combinados:**

- `lender_id` + `product_id` — Índice (para buscar más rápido)

---

## Clientes

### customers

**Clientes.** Clientes de contado y de crédito, con sus datos de contacto y fiscales (RF-24). Cada cliente tiene un solo saldo.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `name` | Nombre o razón social | `varchar(255)` | No | — | — | Nombre del cliente o razón social para facturar. |
| `rfc` | RFC | `varchar(13)` | Sí | — | — | RFC. Opcional para clientes de contado; se puede repetir (RFC genérico XAXX010101000). |
| `email` | Correo | `varchar(255)` | Sí | — | — | Correo de contacto y para enviar facturas. |
| `phone` | Teléfono | `varchar(255)` | Sí | — | — | Teléfono de contacto. |
| `address` | Dirección | `varchar(255)` | Sí | — | — | Dirección de contacto. |
| `tax_system` | Régimen fiscal | `varchar(3)` | Sí | — | — | Clave del régimen fiscal del SAT (p. ej. 601, 612). |
| `cfdi_use` | Uso de CFDI | `varchar(4)` | Sí | — | — | Clave de uso de CFDI por defecto (p. ej. G03). |
| `zip_code` | Código postal | `varchar(5)` | Sí | — | — | Código postal fiscal. |
| `credit_limit` | Límite de crédito | `decimal(12,2)` | Sí | — | — | Máximo que puede deber. Vacío = sin límite. Se avisa al rebasarlo (RF-26). |
| `discount_rate` | Descuento fijo | `decimal(5,4)` | No | `0.0000` | — | Descuento que se le aplica siempre, como fracción: 0.1000 = 10 %. |
| `is_active` | Activo | `tinyint(1)` | No | `1` | — | Si se le sigue vendiendo. |
| `facturapi_id` | ID en Facturapi | `varchar(255)` | Sí | — | Único | Identificador del cliente en Facturapi (el servicio de timbrado). |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### customer_destinations

**Destinos del cliente.** Cuentas o destinos de un cliente (Reynosa, "Jalincher", ranchos). Son solo una etiqueta: el saldo sigue siendo del cliente, pero se puede desglosar por destino (RF-25).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `customer_id` | Cliente | `bigint unsigned` | No | — | Relación → `customers.id` (al borrar: restringir) | Cliente al que pertenece. |
| `name` | Nombre | `varchar(255)` | No | — | — | Nombre del destino. |
| `notes` | Notas | `text` | Sí | — | — | Observaciones. |
| `is_active` | Activo | `tinyint(1)` | No | `1` | — | Si se sigue usando. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

---

## Ventas

### sales

**Ventas.** Cada venta. Lo que no cubren sus pagos queda a crédito del cliente (RF-18).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `cash_session_id` | Sesión de caja | `bigint unsigned` | Sí | — | Relación → `cash_sessions.id` (al borrar: restringir) | Sesión de caja en la que se registró (en ventas: en la que se completó). Vacío si no pasó por la caja. |
| `folio` | Folio | `varchar(255)` | No | — | Único | Número de venta. No se puede repetir. |
| `customer_id` | Cliente | `bigint unsigned` | Sí | — | Relación → `customers.id` (al borrar: restringir) | Cliente. Vacío = venta de mostrador a público en general. |
| `customer_destination_id` | Destino | `bigint unsigned` | Sí | — | Relación → `customer_destinations.id` (al borrar: restringir) | Destino o cuenta del cliente a la que va la venta. |
| `warehouse_id` | Almacén | `bigint unsigned` | No | — | Relación → `warehouses.id` (al borrar: restringir) | Almacén de donde sale la mercancía. |
| `user_id` | Usuario | `bigint unsigned` | No | — | Relación → `users.id` (al borrar: restringir) | Quién registró la venta (y autorizó el descuento, si hubo). |
| `inventory_operation_id` | Operación de inventario | `bigint unsigned` | Sí | — | Relación → `inventory_operations.id` (al borrar: restringir) | Salida de inventario que generó la venta. |
| `status` | Estado | `varchar(255)` | No | `completed` | — | Valores: `completed` (completada), `canceled` (cancelada). |
| `discount_rate` | Descuento de la venta | `decimal(5,4)` | No | `0.0000` | — | Descuento extra autorizado para esta venta, como fracción: 0.0500 = 5 %. |
| `subtotal` | Subtotal | `decimal(12,2)` | No | `0.00` | — | Suma de partidas antes de descuentos e impuestos. |
| `discount_total` | Total de descuento | `decimal(12,2)` | No | `0.00` | — | Monto total descontado. |
| `tax_total` | Total de IVA | `decimal(12,2)` | No | `0.00` | — | IVA de toda la venta. |
| `ieps_total` | Total de IEPS | `decimal(12,2)` | No | `0.00` | — | IEPS de toda la venta. |
| `total` | Total | `decimal(12,2)` | No | `0.00` | — | Importe final a pagar. |
| `notes` | Observaciones | `text` | Sí | — | — | Observaciones de la venta. |
| `sold_at` | Fecha de venta | `timestamp` | No | — | — | Cuándo se hizo la venta. |
| `canceled_at` | Cancelada el | `timestamp` | Sí | — | — | Cuándo se canceló, si aplica. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

**Índices combinados:**

- `customer_id` + `sold_at` — Índice (para buscar más rápido)

### sale_items

**Partidas de venta.** Cada producto vendido dentro de una venta, con su precio, descuento e impuestos (RF-19, RF-23).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `sale_id` | Venta | `bigint unsigned` | No | — | Relación → `sales.id` (al borrar: en cascada) | Venta a la que pertenece. |
| `product_id` | Producto | `bigint unsigned` | No | — | Relación → `products.id` (al borrar: restringir) | Producto vendido. |
| `quantity` | Cantidad | `decimal(12,3)` | No | — | — | Cuánto se vendió. |
| `price_type` | Tipo de precio | `varchar(255)` | No | `public` | — | Valores: `public` (público), `wholesale` (mayoreo). |
| `unit_price` | Precio unitario | `decimal(12,2)` | No | — | — | Precio por unidad aplicado. |
| `discount_rate` | Tasa de descuento | `decimal(5,4)` | No | `0.0000` | — | Descuento total aplicado a la partida, como fracción. |
| `discount_amount` | Monto de descuento | `decimal(12,2)` | No | `0.00` | — | Dinero descontado en la partida. |
| `subtotal` | Subtotal | `decimal(12,2)` | No | — | — | Importe de la partida antes de impuestos. |
| `iva_rate` | Tasa de IVA | `decimal(5,4)` | No | — | — | IVA aplicado, como fracción. |
| `iva_amount` | IVA | `decimal(12,2)` | No | — | — | Monto de IVA de la partida. |
| `ieps_rate` | Tasa de IEPS | `decimal(5,4)` | No | `0.0000` | — | IEPS aplicado, como fracción. |
| `ieps_amount` | IEPS | `decimal(12,2)` | No | `0.00` | — | Monto de IEPS de la partida. |
| `total` | Total | `decimal(12,2)` | No | — | — | Importe final de la partida. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### sale_payments

**Pagos de venta.** Formas de pago con que se cobró una venta en el momento. Una venta puede tener varias (p. ej. parte efectivo, parte tarjeta) (RF-20, RF-21).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `cash_session_id` | Sesión de caja | `bigint unsigned` | Sí | — | Relación → `cash_sessions.id` (al borrar: restringir) | Sesión de caja en la que se registró (en ventas: en la que se completó). Vacío si no pasó por la caja. |
| `sale_id` | Venta | `bigint unsigned` | No | — | Relación → `sales.id` (al borrar: en cascada) | Venta que se paga. |
| `method` | Forma de pago | `varchar(255)` | No | — | — | Valores: `cash` (efectivo), `card` (tarjeta), `transfer` (transferencia). |
| `amount` | Monto | `decimal(12,2)` | No | — | — | Cuánto se abonó a la venta con esta forma de pago. |
| `received_amount` | Monto recibido | `decimal(12,2)` | Sí | — | — | Dinero que entregó el cliente (en efectivo). |
| `change_amount` | Cambio | `decimal(12,2)` | Sí | — | — | Cambio que se le devolvió. |
| `reference` | Referencia | `varchar(255)` | Sí | — | — | Número de autorización de tarjeta o de transferencia. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

---

## Devoluciones

### customer_returns

**Devoluciones de clientes.** Devolución de producto de una venta. Regresa el producto al inventario y genera una nota de reembolso (RF-16).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `cash_session_id` | Sesión de caja | `bigint unsigned` | Sí | — | Relación → `cash_sessions.id` (al borrar: restringir) | Sesión de caja en la que se registró (en ventas: en la que se completó). Vacío si no pasó por la caja. |
| `folio` | Folio | `varchar(255)` | No | — | Único | Número de la nota de reembolso. No se puede repetir. |
| `sale_id` | Venta | `bigint unsigned` | No | — | Relación → `sales.id` (al borrar: restringir) | Venta de la que se devuelve. |
| `customer_id` | Cliente | `bigint unsigned` | Sí | — | Relación → `customers.id` (al borrar: restringir) | Cliente que devuelve. |
| `warehouse_id` | Almacén | `bigint unsigned` | No | — | Relación → `warehouses.id` (al borrar: restringir) | Almacén al que regresa el producto. |
| `user_id` | Usuario | `bigint unsigned` | No | — | Relación → `users.id` (al borrar: restringir) | Quién registró la devolución. |
| `inventory_operation_id` | Operación de inventario | `bigint unsigned` | Sí | — | Relación → `inventory_operations.id` (al borrar: restringir) | Entrada de inventario que generó la devolución. |
| `refund_method` | Forma de reembolso | `varchar(255)` | No | — | — | Valores: `cash` (efectivo), `card` (tarjeta), `transfer` (transferencia), `account_credit` (se abona al saldo del cliente en vez de devolver dinero). |
| `refund_amount` | Monto reembolsado | `decimal(12,2)` | No | — | — | Total devuelto al cliente. |
| `reference` | Referencia | `varchar(255)` | Sí | — | — | Referencia del reembolso. |
| `reason` | Motivo | `text` | Sí | — | — | Por qué se devolvió. |
| `returned_at` | Fecha de devolución | `timestamp` | No | — | — | Cuándo se hizo. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### customer_return_items

**Partidas de devolución.** Cada producto devuelto dentro de una devolución.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `customer_return_id` | Devolución | `bigint unsigned` | No | — | Relación → `customer_returns.id` (al borrar: en cascada) | Devolución a la que pertenece. |
| `sale_item_id` | Partida de venta | `bigint unsigned` | No | — | Relación → `sale_items.id` (al borrar: restringir) | Partida original que se devuelve. |
| `product_id` | Producto | `bigint unsigned` | No | — | Relación → `products.id` (al borrar: restringir) | Producto devuelto. |
| `quantity` | Cantidad | `decimal(12,3)` | No | — | — | Cuánto se devolvió. |
| `refund_amount` | Monto reembolsado | `decimal(12,2)` | No | — | — | Dinero devuelto por esta partida. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

---

## Cobranza

### customer_payments

**Abonos de clientes.** Pagos que hace un cliente a su saldo de crédito, con forma de pago, referencia y nota. Se pueden ligar a un destino o a una venta (RF-27).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `cash_session_id` | Sesión de caja | `bigint unsigned` | Sí | — | Relación → `cash_sessions.id` (al borrar: restringir) | Sesión de caja en la que se registró (en ventas: en la que se completó). Vacío si no pasó por la caja. |
| `customer_id` | Cliente | `bigint unsigned` | No | — | Relación → `customers.id` (al borrar: restringir) | Cliente que abona. |
| `customer_destination_id` | Destino | `bigint unsigned` | Sí | — | Relación → `customer_destinations.id` (al borrar: restringir) | Destino al que se aplica el abono (opcional). |
| `sale_id` | Venta | `bigint unsigned` | Sí | — | Relación → `sales.id` (al borrar: restringir) | Venta específica que se paga (opcional). |
| `user_id` | Usuario | `bigint unsigned` | No | — | Relación → `users.id` (al borrar: restringir) | Quién registró el abono. |
| `method` | Forma de pago | `varchar(255)` | No | — | — | Valores: `cash` (efectivo), `card` (tarjeta), `transfer` (transferencia). |
| `amount` | Monto | `decimal(12,2)` | No | — | — | Cuánto abonó. |
| `reference` | Referencia | `varchar(255)` | Sí | — | — | Número de transferencia, autorización, etc. |
| `note` | Nota | `text` | Sí | — | — | Observaciones. |
| `paid_at` | Fecha de pago | `timestamp` | No | — | — | Cuándo pagó. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

**Índices combinados:**

- `customer_id` + `paid_at` — Índice (para buscar más rápido)

---

## Caja

### cash_movements

**Movimientos de caja.** Dinero que entra o sale de la caja fuera de ventas y abonos (p. ej. pago de flete, fondo inicial). Las salidas exigen concepto y beneficiario (RF-32).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `cash_session_id` | Sesión de caja | `bigint unsigned` | Sí | — | Relación → `cash_sessions.id` (al borrar: restringir) | Sesión de caja en la que se registró (en ventas: en la que se completó). Vacío si no pasó por la caja. |
| `user_id` | Usuario | `bigint unsigned` | No | — | Relación → `users.id` (al borrar: restringir) | Quién registró el movimiento. |
| `type` | Tipo | `varchar(255)` | No | — | — | Valores: `inflow` (entrada de dinero), `outflow` (salida de dinero). |
| `concept` | Concepto | `varchar(255)` | No | — | — | Para qué fue el dinero. |
| `beneficiary` | Beneficiario | `varchar(255)` | Sí | — | — | A quién se le dio el dinero. Obligatorio en salidas (se valida en la aplicación). |
| `amount` | Monto | `decimal(12,2)` | No | — | — | Cantidad de dinero. |
| `reference` | Referencia | `varchar(255)` | Sí | — | — | Recibo o comprobante. |
| `note` | Nota | `text` | Sí | — | — | Observaciones. |
| `occurred_at` | Fecha | `timestamp` | No | — | — | Cuándo ocurrió. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### cash_sessions

**Sesiones de caja.** Cada turno de la única caja, desde que se abre con un fondo inicial hasta el corte (RF-33). Solo puede haber una abierta a la vez. Ventas, abonos, devoluciones, movimientos de caja y pagos a proveedores en efectivo se ligan a la sesión abierta; una sesión cerrada ya no acepta movimientos. Reemplaza a la antigua tabla `cash_closings`.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `opened_by_id` | Abierta por | `bigint unsigned` | No | — | Relación → `users.id` (al borrar: restringir) | Usuario que abrió la caja. |
| `opened_at` | Abierta el | `timestamp` | No | — | — | Cuándo se abrió. |
| `opening_cash` | Fondo inicial | `decimal(12,2)` | No | — | — | Efectivo con que se abrió la caja. |
| `opening_note` | Nota de apertura | `text` | Sí | — | — | Obligatoria si el fondo inicial no coincide con lo que dejó el corte anterior (`cash_left`). |
| `closed_by_id` | Cerrada por | `bigint unsigned` | Sí | — | Relación → `users.id` (al borrar: restringir) | Usuario que hizo el corte. |
| `closed_at` | Cerrada el | `timestamp` | Sí | — | — | Cuándo se hizo el corte. |
| `expected_cash` | Efectivo esperado | `decimal(12,2)` | Sí | — | — | Lo que debería haber: fondo + ventas y abonos en efectivo + entradas − salidas − reembolsos en efectivo − pagos a proveedores en efectivo. |
| `counted_cash` | Efectivo contado | `decimal(12,2)` | Sí | — | — | Efectivo que realmente se contó al cerrar. |
| `cash_difference` | Diferencia | `decimal(12,2)` | Sí | — | — | Contado menos esperado (negativo = faltante, positivo = sobrante). Si no es cero, el corte exige nota. |
| `cash_left` | Fondo que se deja | `decimal(12,2)` | Sí | — | — | Efectivo que se queda en la caja para la siguiente sesión. No puede ser mayor al contado. |
| `card_total` | Total en tarjeta | `decimal(12,2)` | No | `0.00` | — | Cobrado con tarjeta en la sesión (ventas y abonos). |
| `transfer_total` | Total en transferencia | `decimal(12,2)` | No | `0.00` | — | Cobrado por transferencia en la sesión (ventas y abonos). |
| `credit_total` | Total a crédito | `decimal(12,2)` | No | `0.00` | — | Lo que quedó sin pagar de las ventas completadas en la sesión (total − pagos). |
| `closing_note` | Nota de cierre | `text` | Sí | — | — | Explicación de la diferencia u observaciones del corte. |
| `status` | Estado | `varchar(255)` | No | `open` | — | Valores: `open` (abierta), `closed` (cerrada). |
| `open_lock` | Candado de caja abierta | `tinyint unsigned` | Sí | — | Único | Vale 1 mientras la sesión está abierta y queda vacío al cerrar. Su índice único impide que la base de datos tenga dos cajas abiertas. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

---

## Proveedores

### supplier_payments

**Pagos a proveedores.** Pagos en dinero a proveedores de consigna o compra (RF-31). Incluye los pagos globales a Ortitec. Francisco no aparece aquí: a él se le repone producto.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `cash_session_id` | Sesión de caja | `bigint unsigned` | Sí | — | Relación → `cash_sessions.id` (al borrar: restringir) | Sesión de caja en la que se registró (en ventas: en la que se completó). Vacío si no pasó por la caja. |
| `supplier_id` | Proveedor | `bigint unsigned` | No | — | Relación → `suppliers.id` (al borrar: restringir) | A quién se le pagó. |
| `user_id` | Usuario | `bigint unsigned` | No | — | Relación → `users.id` (al borrar: restringir) | Quién registró el pago. |
| `method` | Forma de pago | `varchar(255)` | No | — | — | Valores: `cash` (efectivo), `card` (tarjeta), `transfer` (transferencia). |
| `amount` | Monto | `decimal(12,2)` | No | — | — | Total pagado. |
| `reference` | Referencia | `varchar(255)` | Sí | — | — | Número de transferencia o comprobante. |
| `note` | Nota | `text` | Sí | — | — | Observaciones. |
| `paid_at` | Fecha de pago | `timestamp` | No | — | — | Cuándo se pagó. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### supplier_payment_allocations

**Aplicación de pagos a proveedores.** Reparte un pago global entre los lotes que cubre (p. ej. "100 mil: todo el Core, todo el Bigfix…"). Lo que queda sin cubrir es el saldo pendiente (RF-31).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `supplier_payment_id` | Pago | `bigint unsigned` | No | — | Relación → `supplier_payments.id` (al borrar: en cascada) | Pago que se reparte. |
| `lot_id` | Lote | `bigint unsigned` | No | — | Relación → `lots.id` (al borrar: restringir) | Lote que se paga. |
| `quantity` | Cantidad | `decimal(12,3)` | No | — | — | Unidades del lote que cubre este pago. |
| `amount` | Monto | `decimal(12,2)` | No | — | — | Dinero aplicado a ese lote. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

---

## Facturación

### invoices

**Facturas.** Facturas electrónicas (CFDI 4.0) timbradas con Facturapi.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `customer_id` | Cliente | `bigint unsigned` | No | — | Relación → `customers.id` (al borrar: restringir) | Cliente al que se factura. |
| `status` | Estado | `varchar(255)` | No | `draft` | — | Valores: `draft` (borrador), `stamped` (timbrada), `canceled` (cancelada), `failed` (falló el timbrado). |
| `facturapi_id` | ID en Facturapi | `varchar(255)` | Sí | — | Único | Identificador de la factura en Facturapi. |
| `series` | Serie | `varchar(255)` | Sí | — | — | Serie de la factura. |
| `folio` | Folio | `varchar(255)` | Sí | — | — | Folio de la factura. |
| `payment_form` | Forma de pago SAT | `varchar(2)` | No | — | — | Clave SAT de forma de pago (01 efectivo, 03 transferencia, 04 tarjeta…). |
| `payment_method` | Método de pago SAT | `varchar(3)` | No | `PUE` | — | `PUE` (una sola exhibición) o `PPD` (parcialidades o diferido). |
| `cfdi_use` | Uso de CFDI | `varchar(4)` | No | — | — | Clave SAT de uso del CFDI. |
| `currency` | Moneda | `varchar(3)` | No | `MXN` | — | Moneda (MXN). |
| `subtotal` | Subtotal | `decimal(12,2)` | No | `0.00` | — | Importe antes de impuestos. |
| `tax_total` | Total de impuestos | `decimal(12,2)` | No | `0.00` | — | Impuestos de la factura. |
| `total` | Total | `decimal(12,2)` | No | `0.00` | — | Importe final. |
| `pdf_url` | URL del PDF | `varchar(255)` | Sí | — | — | Enlace al PDF de la factura. |
| `xml_url` | URL del XML | `varchar(255)` | Sí | — | — | Enlace al XML timbrado. |
| `error_message` | Mensaje de error | `text` | Sí | — | — | Por qué falló el timbrado, si falló. |
| `stamped_at` | Timbrada el | `timestamp` | Sí | — | — | Cuándo se timbró. |
| `canceled_at` | Cancelada el | `timestamp` | Sí | — | — | Cuándo se canceló. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### invoice_items

**Partidas de factura.** Cada concepto de una factura.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `invoice_id` | Factura | `bigint unsigned` | No | — | Relación → `invoices.id` (al borrar: en cascada) | Factura a la que pertenece. |
| `product_id` | Producto | `bigint unsigned` | No | — | Relación → `products.id` (al borrar: restringir) | Producto facturado. |
| `quantity` | Cantidad | `decimal(10,2)` | No | — | — | Cantidad facturada. |
| `unit_price` | Precio unitario | `decimal(12,2)` | No | — | — | Precio por unidad sin impuestos. |
| `tax_rate` | Tasa de IVA | `decimal(5,4)` | No | — | — | IVA aplicado, como fracción. |
| `subtotal` | Subtotal | `decimal(12,2)` | No | — | — | Importe antes de impuestos. |
| `tax_amount` | IVA | `decimal(12,2)` | No | — | — | Monto de IVA. |
| `ieps_rate` | Tasa de IEPS | `decimal(5,4)` | No | `0.0000` | — | IEPS aplicado, como fracción. |
| `ieps_amount` | IEPS | `decimal(12,2)` | No | `0.00` | — | Monto de IEPS. |
| `total` | Total | `decimal(12,2)` | No | — | — | Importe final de la partida. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

### invoice_sale

**Ventas facturadas.** Relación entre facturas y ventas: una factura puede cubrir una o varias ventas (RF-34).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `invoice_id` | Factura | `bigint unsigned` | No | — | Relación → `invoices.id` (al borrar: en cascada) | Factura. |
| `sale_id` | Venta | `bigint unsigned` | No | — | Relación → `sales.id` (al borrar: restringir) | Venta incluida en la factura. |
| `created_at` | Creado el | `timestamp` | Sí | — | — | Fecha y hora en que se creó el registro. |
| `updated_at` | Actualizado el | `timestamp` | Sí | — | — | Fecha y hora de la última modificación del registro. |

**Índices combinados:**

- `invoice_id` + `sale_id` — Único (no se puede repetir la combinación)

---

## Tablas internas de Laravel

### migrations

**Migraciones.** Registro de qué cambios de estructura ya se aplicaron a la base de datos.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `int unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `migration` | Migración | `varchar(255)` | No | — | — | Nombre del archivo de migración aplicado. |
| `batch` | Lote | `int` | No | — | — | Número de tanda en que se aplicó (para poder revertir). |

### cache

**Caché.** Datos guardados temporalmente para acelerar el sistema.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `key` | Llave | `varchar(255)` | No | — | Llave primaria | Nombre del dato. |
| `value` | Valor | `mediumtext` | No | — | — | Contenido guardado. |
| `expiration` | Expiración | `bigint` | No | — | — | Cuándo caduca (marca de tiempo Unix). |

### cache_locks

**Bloqueos de caché.** Candados para que dos procesos no hagan lo mismo a la vez.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `key` | Llave | `varchar(255)` | No | — | Llave primaria | Nombre del candado. |
| `owner` | Dueño | `varchar(255)` | No | — | — | Proceso que tiene el candado. |
| `expiration` | Expiración | `bigint` | No | — | — | Cuándo se libera. |

### jobs

**Trabajos en cola.** Tareas pendientes de ejecutarse en segundo plano (p. ej. enviar correos).

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `queue` | Cola | `varchar(255)` | No | — | — | Nombre de la cola. |
| `payload` | Contenido | `longtext` | No | — | — | Datos de la tarea. |
| `attempts` | Intentos | `smallint unsigned` | No | — | — | Veces que se ha intentado. |
| `reserved_at` | Reservado el | `int unsigned` | Sí | — | — | Cuándo la tomó un proceso. |
| `available_at` | Disponible el | `int unsigned` | No | — | — | Desde cuándo se puede ejecutar. |
| `created_at` | Creado el | `int unsigned` | No | — | — | Cuándo se encoló. |

### job_batches

**Lotes de trabajos.** Grupos de tareas en segundo plano que se siguen juntas.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `varchar(255)` | No | — | Llave primaria | Identificador del lote. |
| `name` | Nombre | `varchar(255)` | No | — | — | Nombre del lote. |
| `total_jobs` | Total de trabajos | `int` | No | — | — | Cuántas tareas tiene. |
| `pending_jobs` | Pendientes | `int` | No | — | — | Cuántas faltan. |
| `failed_jobs` | Fallidas | `int` | No | — | — | Cuántas fallaron. |
| `failed_job_ids` | IDs fallidos | `longtext` | No | — | — | Cuáles fallaron. |
| `options` | Opciones | `mediumtext` | Sí | — | — | Configuración del lote. |
| `cancelled_at` | Cancelado el | `int` | Sí | — | — | Cuándo se canceló. |
| `created_at` | Creado el | `int` | No | — | — | Cuándo se creó. |
| `finished_at` | Terminado el | `int` | Sí | — | — | Cuándo terminó. |

### failed_jobs

**Trabajos fallidos.** Tareas en segundo plano que fallaron, para revisarlas o reintentarlas.

| Campo | Traducción | Tipo | Nulo | Por defecto | Reglas | Descripción |
|---|---|---|---|---|---|---|
| `id` | ID | `bigint unsigned` | No | Autoincremental | Llave primaria | Identificador único del registro, se genera automáticamente. |
| `uuid` | UUID | `varchar(255)` | No | — | Único | Identificador único de la tarea. |
| `connection` | Conexión | `varchar(255)` | No | — | — | Conexión de cola usada. |
| `queue` | Cola | `varchar(255)` | No | — | — | Nombre de la cola. |
| `payload` | Contenido | `longtext` | No | — | — | Datos de la tarea. |
| `exception` | Error | `longtext` | No | — | — | Detalle del error. |
| `failed_at` | Falló el | `timestamp` | No | `CURRENT_TIMESTAMP` | — | Cuándo falló. |

**Índices combinados:**

- `connection` + `queue` + `failed_at` — Índice (para buscar más rápido)

