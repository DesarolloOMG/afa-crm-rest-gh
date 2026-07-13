DROP PROCEDURE IF EXISTS sp_recalcular_status_series_por_sku;

DELIMITER $$

CREATE PROCEDURE sp_recalcular_status_series_por_sku(
    IN p_sku VARCHAR(100),
    IN p_aplicar TINYINT
)
main: BEGIN
    DECLARE v_id_modelo INT DEFAULT NULL;
    DECLARE v_id_almacen_mercadolibre INT DEFAULT 3;

    SET p_sku = TRIM(p_sku);
    SET p_aplicar = IFNULL(p_aplicar, 0);

    SELECT id
      INTO v_id_modelo
      FROM modelo
     WHERE sku = p_sku
     LIMIT 1;

    IF v_id_modelo IS NULL THEN
        SELECT
            1 AS error,
            CONCAT('No existe el SKU ', p_sku) AS mensaje;
        LEAVE main;
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_series_status_sku;

    CREATE TEMPORARY TABLE tmp_series_status_sku AS
    SELECT
        p.id AS id_producto,
        p.serie,
        m.sku,
        p.status AS status_actual,
        p.id_almacen AS id_almacen_actual,
        a.almacen AS almacen_actual,
        d.id AS id_documento_ultimo,
        d.created_at AS fecha_documento_ultimo,
        ult.fecha_asignacion_serie,
        ult.id_movimiento_producto_ultimo,
        d.id_tipo,
        dt.tipo,
        COALESCE(dt.sumaInventario, 0) AS sumaInventario,
        COALESCE(dt.restaInventario, 0) AS restaInventario,
        eap.id_almacen AS id_almacen_destino,
        ap.almacen AS almacen_destino,
        eas.id_almacen AS id_almacen_origen,
        aa.almacen AS almacen_origen,
        CASE
            WHEN d.id_tipo = 5 THEN COALESCE(NULLIF(eap.id_almacen, 0), p.id_almacen)
            WHEN d.id_tipo IN (2, 4, 11) THEN COALESCE(NULLIF(eas.id_almacen, 0), NULLIF(eap.id_almacen, 0), p.id_almacen)
            WHEN COALESCE(dt.sumaInventario, 0) = 1 THEN COALESCE(NULLIF(eap.id_almacen, 0), p.id_almacen)
            ELSE p.id_almacen
        END AS id_almacen_nuevo,
        CASE
            WHEN d.id_tipo IN (2, 4, 11) THEN 0
            WHEN d.id_tipo = 5 AND COALESCE(eap.id_almacen, 0) = v_id_almacen_mercadolibre THEN 0
            WHEN COALESCE(dt.sumaInventario, 0) = 1 THEN 1
            ELSE 0
        END AS status_nuevo
    FROM producto p
    INNER JOIN modelo m ON m.id = p.id_modelo
    INNER JOIN (
        SELECT
            mp2.id_producto,
            mp2.id AS id_movimiento_producto_ultimo,
            mp2.created_at AS fecha_asignacion_serie,
            mo2.id AS id_movimiento_ultimo
        FROM movimiento_producto mp2
        INNER JOIN movimiento mo2 ON mo2.id = mp2.id_movimiento
        INNER JOIN documento d2 ON d2.id = mo2.id_documento
        INNER JOIN producto p2 ON p2.id = mp2.id_producto
        WHERE p2.id_modelo = v_id_modelo
          AND d2.status = 1
          AND (d2.id_tipo <> 5 OR COALESCE(d2.autorizado, 0) = 1)
          AND NOT EXISTS (
              SELECT 1
              FROM movimiento_producto mp3
              INNER JOIN movimiento mo3 ON mo3.id = mp3.id_movimiento
              INNER JOIN documento d3 ON d3.id = mo3.id_documento
              WHERE mp3.id_producto = mp2.id_producto
                AND d3.status = 1
                AND (d3.id_tipo <> 5 OR COALESCE(d3.autorizado, 0) = 1)
                AND (
                    mp3.created_at > mp2.created_at
                    OR (mp3.created_at = mp2.created_at AND mp3.id > mp2.id)
                )
          )
    ) ult ON ult.id_producto = p.id
    INNER JOIN movimiento mo ON mo.id = ult.id_movimiento_ultimo
    INNER JOIN documento d ON d.id = mo.id_documento
    INNER JOIN documento_tipo dt ON dt.id = d.id_tipo
    LEFT JOIN empresa_almacen eap ON eap.id = d.id_almacen_principal_empresa
    LEFT JOIN almacen ap ON ap.id = eap.id_almacen
    LEFT JOIN empresa_almacen eas ON eas.id = d.id_almacen_secundario_empresa
    LEFT JOIN almacen aa ON aa.id = eas.id_almacen
    LEFT JOIN almacen a ON a.id = p.id_almacen
    WHERE p.id_modelo = v_id_modelo;

    ALTER TABLE tmp_series_status_sku
        ADD COLUMN requiere_update_status TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN requiere_update_almacen TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN requiere_update TINYINT(1) NOT NULL DEFAULT 0;

    UPDATE tmp_series_status_sku
       SET requiere_update_status = IF(
               COALESCE(status_actual, -1) <> COALESCE(status_nuevo, -1),
               1,
               0
           ),
           requiere_update_almacen = IF(
               COALESCE(id_almacen_actual, -1) <> COALESCE(id_almacen_nuevo, -1),
               1,
               0
           );

    UPDATE tmp_series_status_sku
       SET requiere_update = IF(requiere_update_status = 1 OR requiere_update_almacen = 1, 1, 0);

    IF p_aplicar = 1 THEN
        UPDATE producto p
        INNER JOIN tmp_series_status_sku t ON t.id_producto = p.id
           SET p.status = t.status_nuevo,
               p.id_almacen = COALESCE(t.id_almacen_nuevo, p.id_almacen)
         WHERE t.requiere_update = 1;
    END IF;

    SELECT
        0 AS error,
        IF(p_aplicar = 1, 'APLICADO', 'PREVIEW') AS modo,
        p_sku AS sku,
        COUNT(*) AS series_revisadas,
        SUM(requiere_update) AS series_con_cambio,
        SUM(requiere_update_status) AS series_con_cambio_status,
        SUM(requiere_update_almacen) AS series_con_cambio_almacen
    FROM tmp_series_status_sku;

    SELECT
        t.id_producto,
        t.serie,
        t.sku,
        t.status_actual,
        t.status_nuevo,
        t.id_almacen_actual,
        t.almacen_actual,
        t.id_almacen_nuevo,
        an.almacen AS almacen_nuevo,
        t.id_documento_ultimo,
        t.fecha_documento_ultimo,
        t.fecha_asignacion_serie,
        t.id_movimiento_producto_ultimo,
        t.id_tipo,
        t.tipo,
        t.sumaInventario,
        t.restaInventario,
        t.almacen_destino,
        t.almacen_origen,
        t.requiere_update_status,
        t.requiere_update_almacen,
        t.requiere_update
    FROM tmp_series_status_sku t
    LEFT JOIN almacen an ON an.id = t.id_almacen_nuevo
    WHERE t.requiere_update = 1
    ORDER BY t.fecha_asignacion_serie DESC, t.id_movimiento_producto_ultimo DESC, t.serie;
END$$

DELIMITER ;

-- Vista previa:
-- CALL sp_recalcular_status_series_por_sku('715023808280', 0);
-- CALL sp_recalcular_status_series_por_sku('7500938008169', 0);

-- Aplicar:
-- CALL sp_recalcular_status_series_por_sku('715023808280', 1);
-- CALL sp_recalcular_status_series_por_sku('7500938008169', 1);
