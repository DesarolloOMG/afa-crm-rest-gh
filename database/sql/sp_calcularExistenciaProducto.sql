-- Ventas en fase 5: salida terminada, pendiente solo de timbrado.
-- Creacion ejecutada por artifacts/existencia-fase5-20260922/deploy.php con respaldo y validacion.
CREATE DEFINER=`afadev`@`%` PROCEDURE `sp_calcularExistenciaProducto`(
    IN in_sku VARCHAR(255),
    IN in_id_almacen INT,
    IN in_version TINYINT
)
main_block: BEGIN
    DECLARE v_version TINYINT DEFAULT 2;
    DECLARE v_id_modelo INT DEFAULT NULL;
    DECLARE v_id_almacen_real INT DEFAULT NULL;

    DECLARE v_stock_movimientos DECIMAL(18,4) DEFAULT 0;
    DECLARE v_pendientes_fase3 INT DEFAULT 0;
    DECLARE v_stock_recalculado INT DEFAULT 0;
    DECLARE v_pendientes_venta INT DEFAULT 0;
    DECLARE v_traspaso_salida INT DEFAULT 0;
    DECLARE v_disponible INT DEFAULT 0;

    SET v_version = IFNULL(in_version, 2);

    SELECT id INTO v_id_modelo
    FROM modelo
    WHERE sku = in_sku
    LIMIT 1;

    IF v_id_modelo IS NULL THEN
        SELECT 1 AS error, CONCAT('No se encontró el SKU ', in_sku) AS mensaje, NULL AS stock, NULL AS disponible;
        LEAVE main_block;
    END IF;

    IF v_version = 1 THEN
        SELECT IFNULL(SUM(
            CASE
                WHEN dt.sumaInventario  = 1 THEN  m.cantidad
                WHEN dt.restaInventario = 1 THEN -m.cantidad
                ELSE 0
            END
        ), 0)
        INTO v_stock_movimientos
        FROM movimiento m
        JOIN documento d       ON d.id = m.id_documento
        JOIN documento_tipo dt ON dt.id = d.id_tipo
        WHERE d.status = 1
          AND (d.id_fase IN (6,100,606,607) OR (d.id_tipo = 2 AND d.id_fase = 5))
          AND COALESCE(d.es_refactura, 0) = 0
          AND d.id_tipo NOT IN (12,9,5,0)
          AND m.id_modelo = v_id_modelo
          AND d.id_almacen_principal_empresa = in_id_almacen;

        SELECT v_stock_movimientos + IFNULL(SUM(dr.cantidad), 0)
        INTO v_stock_movimientos
        FROM movimiento m
        JOIN documento_recepcion dr ON dr.id_movimiento = m.id
        JOIN documento d            ON d.id = m.id_documento
        WHERE d.status = 1
          AND d.id_fase IN (6,100,606,607)
          AND d.id_tipo = 0
          AND m.id_modelo = v_id_modelo
          AND d.id_almacen_principal_empresa = in_id_almacen;

        SELECT v_stock_movimientos + IFNULL(SUM(m.cantidad), 0)
        INTO v_stock_movimientos
        FROM movimiento m
        JOIN documento d ON d.id = m.id_documento
        WHERE d.status = 1
          AND d.id_fase IN (6,100,606,607)
          AND d.id_tipo = 5
          AND d.autorizado = 1
          AND m.id_modelo = v_id_modelo
          AND d.id_almacen_principal_empresa = in_id_almacen;

        SELECT v_stock_movimientos - IFNULL(SUM(m.cantidad), 0)
        INTO v_stock_movimientos
        FROM movimiento m
        JOIN documento d ON d.id = m.id_documento
        WHERE d.status = 1
          AND d.id_fase IN (6,100,606,607)
          AND d.id_tipo = 5
          AND d.autorizado = 1
          AND m.id_modelo = v_id_modelo
          AND d.id_almacen_secundario_empresa = in_id_almacen;

        SELECT IFNULL(COUNT(DISTINCT p2.id), 0)
        INTO v_pendientes_fase3
        FROM documento d2
        JOIN documento_tipo dt2       ON dt2.id = d2.id_tipo AND dt2.restaInventario = 1
        JOIN movimiento m2            ON m2.id_documento = d2.id
        JOIN movimiento_producto mp2  ON mp2.id_movimiento = m2.id
        JOIN producto p2              ON p2.id = mp2.id_producto
        WHERE d2.status = 1
          AND d2.id_tipo = 2
          AND d2.id_fase = 3
          AND COALESCE(d2.es_refactura, 0) = 0
          AND TRIM(IFNULL(p2.serie, '')) <> ''
          AND NOT EXISTS (
              SELECT 1 FROM modelo_kardex mk
              WHERE mk.id_documento = d2.id
                AND mk.id_modelo    = m2.id_modelo
          )
          AND m2.id_modelo = v_id_modelo
          AND d2.id_almacen_principal_empresa = in_id_almacen;

        SET v_stock_recalculado = CAST(v_stock_movimientos - v_pendientes_fase3 AS SIGNED);

    ELSE
        SELECT ea.id_almacen
        INTO v_id_almacen_real
        FROM empresa_almacen ea
        WHERE ea.id = in_id_almacen
        LIMIT 1;

        IF v_id_almacen_real IS NULL THEN
            SELECT 1 AS error,
                   CONCAT('No se encontró el empresa_almacen ', in_id_almacen) AS mensaje,
                   NULL AS stock,
                   NULL AS disponible;
            LEAVE main_block;
        END IF;
        IF v_id_almacen_real = 3 THEN
            SELECT IFNULL(COUNT(p.id), 0)
            INTO v_stock_recalculado
            FROM producto p
            WHERE p.id_modelo = v_id_modelo
              AND p.id_almacen = v_id_almacen_real
              AND p.status != 1
              AND TRIM(IFNULL(p.serie,'')) <> '';

        ELSE
            SELECT IFNULL(COUNT(p.id), 0)
            INTO v_stock_recalculado
            FROM producto p
            WHERE p.id_modelo = v_id_modelo
              AND p.id_almacen = v_id_almacen_real
              AND p.status = 1
              AND TRIM(IFNULL(p.serie,'')) <> '';

        END IF;

        SET v_stock_movimientos = v_stock_recalculado;
        SET v_pendientes_fase3 = 0;

    END IF;

    SELECT IFNULL(SUM(mov.cantidad), 0)
    INTO v_pendientes_venta
    FROM documento d
    JOIN movimiento mov ON mov.id_documento = d.id
    WHERE mov.id_modelo = v_id_modelo
      AND d.id_almacen_principal_empresa = in_id_almacen
      AND d.id_tipo = 2
      AND d.status = 1
      AND d.anticipada = 0
      AND d.id_fase IN (7,2,3,4)
      AND COALESCE(d.es_refactura, 0) = 0;

    SELECT IFNULL(SUM(mov.cantidad), 0)
    INTO v_traspaso_salida
    FROM documento d
    JOIN movimiento mov ON mov.id_documento = d.id
    WHERE mov.id_modelo = v_id_modelo
      AND d.id_tipo = 5
      AND d.status = 1
      AND d.autorizado = 0
      AND d.id_almacen_secundario_empresa = in_id_almacen;

    SET v_disponible =
        v_stock_recalculado
      - v_pendientes_venta
      - IF(v_version = 1, v_traspaso_salida, 0);

    SELECT
        0 AS error,
        'OK' AS mensaje,
        CAST(v_stock_recalculado AS SIGNED) AS stock,
        CAST(v_disponible AS SIGNED) AS disponible,
        v_version AS version,
        v_id_modelo AS id_modelo,
        v_id_almacen_real AS id_almacen_real;

END;
