-- Ventas en fase 5: salida terminada, pendiente solo de timbrado.
-- Creacion ejecutada por artifacts/existencia-fase5-20260922/deploy.php con respaldo y validacion.
CREATE DEFINER=`afadev`@`%` PROCEDURE `sp_calcularExistenciaCompleta`(IN in_sku VARCHAR(255),          -- SKU del producto específico a buscar
    IN in_id_almacen INT)
main_block: BEGIN
    -- 1. Declaración de variables para almacenar resultados
    DECLARE v_id_modelo INT DEFAULT NULL;             -- ID del modelo correspondiente al SKU
    DECLARE v_tipo INT DEFAULT NULL;             -- ID del modelo correspondiente al SKU
    DECLARE v_stock INT DEFAULT 0;                    -- Stock físico actual en almacén
    DECLARE v_pendientesVenta INT DEFAULT 0;          -- Cantidades pendientes en ventas
    DECLARE v_transito INT DEFAULT 0;                 -- Cantidades actualmente en tránsito
    DECLARE v_pretransferencia INT DEFAULT 0;         -- Cantidades pendientes por pretransferencia
    DECLARE v_disponible INT DEFAULT 0;               -- Stock disponible (stock físico menos pendientesVenta)
    DECLARE v_error INT DEFAULT 0;                    -- Indicador de error (0=no hay error, 1=error encontrado)
    DECLARE v_msg VARCHAR(500) DEFAULT '';            -- Mensaje descriptivo del resultado

    -- 2. Buscar el ID del modelo a partir del SKU proporcionado
    SELECT id INTO v_id_modelo
    FROM modelo WHERE sku = in_sku LIMIT 1;
		
		SELECT id_tipo INTO v_tipo
    FROM modelo WHERE sku = in_sku LIMIT 1;

    -- 3. Validar si se encontró el modelo; si no, devolver error y salir
    IF v_id_modelo IS NULL THEN
        SET v_error = 1;
        SET v_msg = CONCAT('No se encontró el SKU ', in_sku);
        SELECT v_error AS error, v_msg AS mensaje, NULL AS stock, NULL AS pendientesVenta, NULL AS transito, NULL AS pretransferencia, NULL AS disponible;
        LEAVE main_block;
    END IF;

    -- 4. Obtener el stock físico actual del producto en el almacén especificado
    SELECT IFNULL(stock,0) INTO v_stock
    FROM modelo_existencias
    WHERE id_modelo = v_id_modelo AND id_almacen = in_id_almacen
    LIMIT 1;

    -- 5. Calcular pendientes de venta:
    -- Documentos activos de tipo 2 (ventas), no anticipadas y fases pendientes (7,2,3,4); fase 5 ya es salida de venta
    SELECT IFNULL(SUM(mov.cantidad),0) INTO v_pendientesVenta
    FROM documento d
    JOIN movimiento mov ON d.id = mov.id_documento
    WHERE mov.id_modelo = v_id_modelo
      AND d.id_almacen_principal_empresa = in_id_almacen
      AND d.id_tipo = 2
      AND d.status = 1
      AND d.anticipada = 0
      AND d.id_fase IN (7,2,3,4)
      AND COALESCE(d.es_refactura, 0) = 0;

    -- 6. Calcular productos en tránsito:
    -- Documentos activos tipo 0, fase 606, diferenciando si es producto de serie
    SELECT IFNULL(SUM(
        CASE WHEN m.serie = 1 THEN
            (mov.cantidad - (SELECT COUNT(*) FROM movimiento_producto mp WHERE mp.id_movimiento = mov.id)) -- Restar productos ya recibidos si aplica
        ELSE
            (mov.cantidad - mov.cantidad_aceptada)        -- Cantidades no aceptadas aún
        END),0)
    INTO v_transito
    FROM documento d
    JOIN movimiento mov ON d.id = mov.id_documento
    JOIN modelo m ON m.id = mov.id_modelo
    WHERE mov.id_modelo = v_id_modelo
      AND d.id_tipo = 0
      AND d.status = 1
      AND d.id_almacen_principal_empresa = in_id_almacen
      AND d.id_fase = 606;

    -- 7. Calcular cantidades pendientes por pretransferencia:
    -- Documentos activos tipo 9, fases 401-404, hacia almacén secundario
    SELECT IFNULL(SUM(mov.cantidad),0) INTO v_pretransferencia
    FROM documento d
    JOIN movimiento mov ON d.id = mov.id_documento
    WHERE mov.id_modelo = v_id_modelo
      AND d.id_almacen_secundario_empresa = in_id_almacen
      AND d.id_tipo = 9
      AND d.status = 1
      AND d.id_fase IN (401,402,403,404);

    -- 8. Calcular stock disponible restando al stock físico los pendientes de venta
    SET v_disponible = v_stock - v_pendientesVenta - v_pretransferencia ;

    -- 9. Establecer mensaje de éxito
    SET v_error = 0;
    SET v_msg = 'OK';

    -- 10. Devolver todos los valores calculados
    SELECT 
        v_error AS error,
        v_msg AS mensaje,
				v_tipo AS tipo,
        v_stock AS stock,
        v_pendientesVenta AS pendientesVenta,
        v_transito AS transito,
        v_pretransferencia AS pretransferencia,
        v_disponible AS disponible;
END main_block;
