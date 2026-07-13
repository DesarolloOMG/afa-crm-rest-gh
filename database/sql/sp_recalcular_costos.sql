-- Recalculo historico de costos promedio por modelo.
-- MySQL 8.x
--
-- Alcances de sp_recalcularCostos:
--   in_id_modelo = 0 e in_id_documento_compra = 0: todos los modelos.
--   in_id_modelo > 0: un modelo.
--   in_id_documento_compra > 0: modelos contenidos en una COMPRA valida.
--
-- in_aplicar = 0: solo simulacion.
-- in_aplicar = 1: actualiza modelo_costo.

SET @existe_indice_modelo_costo := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'modelo_costo'
      AND INDEX_NAME = 'uq_modelo_costo_id_modelo'
);

SET @sql_indice_modelo_costo := IF(
    @existe_indice_modelo_costo = 0,
    'ALTER TABLE modelo_costo ADD UNIQUE KEY uq_modelo_costo_id_modelo (id_modelo)',
    'SELECT 1'
);

PREPARE stmt_indice_modelo_costo FROM @sql_indice_modelo_costo;
EXECUTE stmt_indice_modelo_costo;
DEALLOCATE PREPARE stmt_indice_modelo_costo;

DROP PROCEDURE IF EXISTS sp_recalcularCostosPorCompra;
DROP PROCEDURE IF EXISTS sp_recalcularCostos;

DELIMITER $$

CREATE PROCEDURE sp_recalcularCostos(
    IN in_id_modelo INT,
    IN in_id_documento_compra BIGINT,
    IN in_aplicar TINYINT
)
BEGIN
    DECLARE v_fin TINYINT DEFAULT 0;
    DECLARE v_cursor_abierto TINYINT DEFAULT 0;

    DECLARE v_id_modelo INT;
    DECLARE v_id_modelo_actual INT DEFAULT NULL;
    DECLARE v_tipo_evento VARCHAR(20);
    DECLARE v_delta_stock DECIMAL(25,6);
    DECLARE v_costo_unitario DECIMAL(25,6);
    DECLARE v_es_oficial TINYINT;

    DECLARE v_stock DECIMAL(25,6) DEFAULT 0;
    DECLARE v_costo_inicial DECIMAL(25,6) DEFAULT 0;
    DECLARE v_costo_promedio DECIMAL(25,6) DEFAULT 0;
    DECLARE v_ultimo_costo DECIMAL(25,6) DEFAULT 0;
    DECLARE v_fuente VARCHAR(30) DEFAULT 'SIN_COSTO';

    DECLARE v_documento_existe INT DEFAULT 0;
    DECLARE v_documento_tipo INT DEFAULT NULL;
    DECLARE v_documento_status INT DEFAULT NULL;
    DECLARE v_modelo_existe INT DEFAULT 0;
    DECLARE v_scope_count INT DEFAULT 0;

    DECLARE cur_eventos CURSOR FOR
        SELECT
            e.id_modelo,
            e.tipo_evento,
            e.delta_stock,
            e.costo_unitario,
            e.es_oficial
        FROM tmp_eventos_costo e
        ORDER BY e.id_modelo, e.fecha_evento, e.orden_evento, e.id_evento;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fin = 1;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF v_cursor_abierto = 1 THEN
            CLOSE cur_eventos;
        END IF;
        DROP TEMPORARY TABLE IF EXISTS tmp_scope_costos;
        DROP TEMPORARY TABLE IF EXISTS tmp_eventos_costo;
        DROP TEMPORARY TABLE IF EXISTS tmp_resultados_costo;
        RESIGNAL;
    END;

    SET in_id_modelo = IFNULL(in_id_modelo, 0);
    SET in_id_documento_compra = IFNULL(in_id_documento_compra, 0);
    SET in_aplicar = IF(in_aplicar = 1, 1, 0);

    IF in_id_modelo > 0 AND in_id_documento_compra > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Indique un modelo o una compra, no ambos.';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_scope_costos;
    CREATE TEMPORARY TABLE tmp_scope_costos (
        id_modelo INT PRIMARY KEY
    ) ENGINE=MEMORY;

    IF in_id_documento_compra > 0 THEN
        SELECT COUNT(*), MAX(id_tipo), MAX(status)
          INTO v_documento_existe, v_documento_tipo, v_documento_status
        FROM documento
        WHERE id = in_id_documento_compra;

        IF v_documento_existe = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'El documento de compra indicado no existe.';
        END IF;

        IF v_documento_tipo <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'El ID indicado no corresponde a un documento tipo COMPRA (id_tipo = 1).';
        END IF;

        IF v_documento_status <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'La compra indicada esta cancelada o inactiva.';
        END IF;

        INSERT IGNORE INTO tmp_scope_costos (id_modelo)
        SELECT DISTINCT mov.id_modelo
        FROM movimiento mov
        INNER JOIN modelo m ON m.id = mov.id_modelo
        WHERE mov.id_documento = in_id_documento_compra;
    ELSEIF in_id_modelo > 0 THEN
        SELECT COUNT(*) INTO v_modelo_existe
        FROM modelo
        WHERE id = in_id_modelo;

        IF v_modelo_existe = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'El modelo indicado no existe.';
        END IF;

        INSERT INTO tmp_scope_costos (id_modelo) VALUES (in_id_modelo);
    ELSE
        INSERT INTO tmp_scope_costos (id_modelo)
        SELECT id FROM modelo;
    END IF;

    SELECT COUNT(*) INTO v_scope_count FROM tmp_scope_costos;
    IF v_scope_count = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No se encontraron modelos para recalcular.';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_eventos_costo;
    CREATE TEMPORARY TABLE tmp_eventos_costo (
        id_evento BIGINT NOT NULL,
        id_modelo INT NOT NULL,
        fecha_evento DATETIME NOT NULL,
        orden_evento TINYINT NOT NULL,
        tipo_evento VARCHAR(20) NOT NULL,
        delta_stock DECIMAL(25,6) NOT NULL,
        costo_unitario DECIMAL(25,6) NOT NULL,
        es_oficial TINYINT NOT NULL DEFAULT 0,
        KEY idx_eventos_costo_orden (id_modelo, fecha_evento, orden_evento, id_evento)
    ) ENGINE=InnoDB;

    /* Movimientos que cambian el stock global. Los traspasos se omiten porque
       solo cambian el almacen y no el inventario total del modelo. */
    INSERT INTO tmp_eventos_costo (
        id_evento, id_modelo, fecha_evento, orden_evento,
        tipo_evento, delta_stock, costo_unitario, es_oficial
    )
    SELECT
        mov.id,
        mov.id_modelo,
        mov.created_at,
        CASE
            WHEN d.id_tipo = 3 THEN 10
            WHEN dt.sumaInventario = 1 THEN 25
            ELSE 30
        END,
        CASE WHEN d.id_tipo = 3 THEN 'ENTRADA' ELSE 'MOVIMIENTO' END,
        CASE
            WHEN dt.sumaInventario = 1 AND IFNULL(dt.restaInventario, 0) <> 1
                THEN CAST(mov.cantidad AS DECIMAL(25,6))
            WHEN dt.restaInventario = 1 AND IFNULL(dt.sumaInventario, 0) <> 1
                THEN -CAST(mov.cantidad AS DECIMAL(25,6))
            ELSE 0
        END,
        CASE
            WHEN d.id_tipo = 3
                THEN CAST(mov.precio AS DECIMAL(25,6))
                     * CASE WHEN IFNULL(d.tipo_cambio, 0) > 0 THEN d.tipo_cambio ELSE 1 END
            ELSE 0
        END,
        0
    FROM movimiento mov
    INNER JOIN documento d ON d.id = mov.id_documento
    INNER JOIN documento_tipo dt ON dt.id = d.id_tipo
    INNER JOIN tmp_scope_costos sc ON sc.id_modelo = mov.id_modelo
    WHERE d.status = 1
      AND d.id_fase IN (5, 6, 100, 606, 607)
      AND d.id_tipo NOT IN (0, 1, 5, 9, 12);

    /* Cada recepcion parcial es un evento independiente. Si ya se vinculo una
       compra valida, su costo y tipo de cambio son la fuente oficial. */
    INSERT INTO tmp_eventos_costo (
        id_evento, id_modelo, fecha_evento, orden_evento,
        tipo_evento, delta_stock, costo_unitario, es_oficial
    )
    SELECT
        dr.id,
        mov_odc.id_modelo,
        dr.created_at,
        20,
        'RECEPCION',
        CAST(dr.cantidad AS DECIMAL(25,6)),
        CASE
            WHEN d_compra.id IS NOT NULL AND mov_compra.id IS NOT NULL THEN
                CAST(mov_compra.precio AS DECIMAL(25,6))
                * CASE WHEN IFNULL(d_compra.tipo_cambio, 0) > 0 THEN d_compra.tipo_cambio ELSE 1 END
            ELSE
                CAST(mov_odc.precio AS DECIMAL(25,6))
                * CASE WHEN IFNULL(d_odc.tipo_cambio, 0) > 0 THEN d_odc.tipo_cambio ELSE 1 END
        END,
        CASE
            WHEN d_compra.id IS NOT NULL AND mov_compra.id IS NOT NULL THEN 1
            ELSE 0
        END
    FROM documento_recepcion dr
    INNER JOIN movimiento mov_odc ON mov_odc.id = dr.id_movimiento
    INNER JOIN documento d_odc ON d_odc.id = mov_odc.id_documento
    INNER JOIN tmp_scope_costos sc ON sc.id_modelo = mov_odc.id_modelo
    LEFT JOIN documento d_compra
        ON d_compra.id = CASE
            WHEN dr.documento_erp_compra REGEXP '^[0-9]+$'
                THEN CAST(dr.documento_erp_compra AS UNSIGNED)
            ELSE NULL
        END
       AND d_compra.id_tipo = 1
       AND d_compra.status = 1
    LEFT JOIN movimiento mov_compra
        ON mov_compra.id_documento = d_compra.id
       AND mov_compra.id_modelo = mov_odc.id_modelo
    WHERE d_odc.id_tipo = 0
      AND d_odc.status = 1
      AND d_odc.id_fase IN (5, 6, 100, 606, 607)
      AND dr.cantidad > 0;

    /* Una compra directa sin recepcion vinculada afecta costo, pero no vuelve a
       sumar inventario (documento_tipo COMPRA tiene sumaInventario = 0). */
    INSERT INTO tmp_eventos_costo (
        id_evento, id_modelo, fecha_evento, orden_evento,
        tipo_evento, delta_stock, costo_unitario, es_oficial
    )
    SELECT
        mov.id,
        mov.id_modelo,
        d.created_at,
        21,
        'COMPRA',
        CAST(mov.cantidad AS DECIMAL(25,6)),
        CAST(mov.precio AS DECIMAL(25,6))
            * CASE WHEN IFNULL(d.tipo_cambio, 0) > 0 THEN d.tipo_cambio ELSE 1 END,
        1
    FROM movimiento mov
    INNER JOIN documento d ON d.id = mov.id_documento
    INNER JOIN tmp_scope_costos sc ON sc.id_modelo = mov.id_modelo
    WHERE d.id_tipo = 1
      AND d.status = 1
      AND NOT EXISTS (
          SELECT 1
          FROM documento_recepcion dr
          INNER JOIN movimiento mov_odc ON mov_odc.id = dr.id_movimiento
          WHERE dr.documento_erp_compra = CAST(d.id AS CHAR)
            AND mov_odc.id_modelo = mov.id_modelo
      );

    DROP TEMPORARY TABLE IF EXISTS tmp_resultados_costo;
    CREATE TEMPORARY TABLE tmp_resultados_costo (
        id_modelo INT PRIMARY KEY,
        stock_final DECIMAL(25,6) NOT NULL DEFAULT 0,
        costo_inicial DECIMAL(25,6) NOT NULL DEFAULT 0,
        costo_promedio DECIMAL(25,6) NOT NULL DEFAULT 0,
        ultimo_costo DECIMAL(25,6) NOT NULL DEFAULT 0,
        fuente VARCHAR(30) NOT NULL DEFAULT 'SIN_COSTO',
        tenia_registro TINYINT NOT NULL DEFAULT 0,
        costo_promedio_anterior DECIMAL(25,6) NOT NULL DEFAULT 0,
        ultimo_costo_anterior DECIMAL(25,6) NOT NULL DEFAULT 0
    ) ENGINE=MEMORY;

    OPEN cur_eventos;
    SET v_cursor_abierto = 1;

    bucle_eventos: LOOP
        FETCH cur_eventos
         INTO v_id_modelo, v_tipo_evento, v_delta_stock,
              v_costo_unitario, v_es_oficial;

        IF v_fin = 1 THEN
            LEAVE bucle_eventos;
        END IF;

        IF v_id_modelo_actual IS NULL OR v_id_modelo_actual <> v_id_modelo THEN
            IF v_id_modelo_actual IS NOT NULL THEN
                INSERT INTO tmp_resultados_costo (
                    id_modelo, stock_final, costo_inicial,
                    costo_promedio, ultimo_costo, fuente
                ) VALUES (
                    v_id_modelo_actual, v_stock, v_costo_inicial,
                    v_costo_promedio, v_ultimo_costo, v_fuente
                );
            END IF;

            SET v_id_modelo_actual = v_id_modelo;
            SET v_stock = 0;
            SET v_costo_inicial = 0;
            SET v_costo_promedio = 0;
            SET v_ultimo_costo = 0;
            SET v_fuente = 'SIN_COSTO';
        END IF;

        IF v_tipo_evento = 'ENTRADA'
           AND v_costo_unitario > 0
           AND v_costo_promedio <= 0 THEN
            SET v_costo_inicial = v_costo_unitario;
            SET v_costo_promedio = v_costo_unitario;
            SET v_ultimo_costo = v_costo_unitario;
            SET v_fuente = 'ENTRADA_INICIAL';
        END IF;

        IF v_tipo_evento IN ('RECEPCION', 'COMPRA') AND v_costo_unitario > 0 THEN
            IF v_costo_inicial <= 0 THEN
                SET v_costo_inicial = v_costo_unitario;
            END IF;

            IF v_stock <= 0 OR v_costo_promedio <= 0 THEN
                SET v_costo_promedio = v_costo_unitario;
            ELSE
                SET v_costo_promedio = (
                    (v_stock * v_costo_promedio)
                    + (v_delta_stock * v_costo_unitario)
                ) / (v_stock + v_delta_stock);
            END IF;

            SET v_ultimo_costo = v_costo_unitario;
            SET v_fuente = CASE
                WHEN v_tipo_evento = 'COMPRA' THEN 'COMPRA_DIRECTA'
                WHEN v_es_oficial = 1 THEN 'RECEPCION_OFICIAL'
                ELSE 'RECEPCION_PROVISIONAL'
            END;
        END IF;

        IF v_tipo_evento <> 'COMPRA' THEN
            SET v_stock = v_stock + v_delta_stock;
        END IF;
    END LOOP;

    CLOSE cur_eventos;
    SET v_cursor_abierto = 0;

    IF v_id_modelo_actual IS NOT NULL THEN
        INSERT INTO tmp_resultados_costo (
            id_modelo, stock_final, costo_inicial,
            costo_promedio, ultimo_costo, fuente
        ) VALUES (
            v_id_modelo_actual, v_stock, v_costo_inicial,
            v_costo_promedio, v_ultimo_costo, v_fuente
        );
    END IF;

    /* Agrega modelos sin eventos y usa modelo.costo solo como respaldo. */
    INSERT IGNORE INTO tmp_resultados_costo (id_modelo)
    SELECT id_modelo FROM tmp_scope_costos;

    UPDATE tmp_resultados_costo rc
    INNER JOIN modelo m ON m.id = rc.id_modelo
    SET
        rc.costo_inicial = CASE
            WHEN rc.costo_inicial > 0 THEN rc.costo_inicial
            ELSE IFNULL(m.costo, 0)
        END,
        rc.costo_promedio = CASE
            WHEN rc.costo_promedio > 0 THEN rc.costo_promedio
            ELSE IFNULL(m.costo, 0)
        END,
        rc.ultimo_costo = CASE
            WHEN rc.ultimo_costo > 0 THEN rc.ultimo_costo
            ELSE IFNULL(m.costo, 0)
        END,
        rc.fuente = CASE
            WHEN rc.fuente <> 'SIN_COSTO' THEN rc.fuente
            WHEN IFNULL(m.costo, 0) > 0 THEN 'MODELO_COSTO_BASE'
            ELSE 'SIN_COSTO'
        END;

    UPDATE tmp_resultados_costo rc
    LEFT JOIN modelo_costo mc ON mc.id_modelo = rc.id_modelo
    SET
        rc.tenia_registro = IF(mc.id IS NULL, 0, 1),
        rc.costo_promedio_anterior = IFNULL(mc.costo_promedio, 0),
        rc.ultimo_costo_anterior = IFNULL(mc.ultimo_costo, 0);

    IF in_aplicar = 1 THEN
        INSERT INTO modelo_costo (
            id_modelo, costo_inicial, costo_promedio, ultimo_costo
        )
        SELECT
            id_modelo,
            ROUND(costo_inicial, 3),
            ROUND(costo_promedio, 3),
            ROUND(ultimo_costo, 3)
        FROM tmp_resultados_costo
        ON DUPLICATE KEY UPDATE
            costo_inicial = VALUES(costo_inicial),
            costo_promedio = VALUES(costo_promedio),
            ultimo_costo = VALUES(ultimo_costo),
            updated_at = CURRENT_TIMESTAMP;

        IF in_id_modelo = 0 AND in_id_documento_compra = 0 THEN
            DELETE mc
            FROM modelo_costo mc
            LEFT JOIN modelo m ON m.id = mc.id_modelo
            WHERE m.id IS NULL;
        END IF;
    END IF;

    SELECT
        in_aplicar AS aplicado,
        in_id_modelo AS id_modelo,
        in_id_documento_compra AS id_documento_compra,
        COUNT(*) AS modelos_procesados,
        SUM(
            tenia_registro = 0
            OR ABS(costo_promedio_anterior - ROUND(costo_promedio, 3)) > 0.0005
            OR ABS(ultimo_costo_anterior - ROUND(ultimo_costo, 3)) > 0.0005
        ) AS modelos_con_cambio,
        SUM(tenia_registro = 0) AS modelos_nuevos,
        SUM(fuente = 'RECEPCION_OFICIAL') AS recepcion_oficial,
        SUM(fuente = 'RECEPCION_PROVISIONAL') AS recepcion_provisional,
        SUM(fuente = 'COMPRA_DIRECTA') AS compra_directa,
        SUM(fuente = 'ENTRADA_INICIAL') AS entrada_inicial,
        SUM(fuente = 'MODELO_COSTO_BASE') AS costo_base,
        SUM(fuente = 'SIN_COSTO') AS sin_costo,
        SUM(stock_final < 0) AS stock_historico_negativo,
        CASE WHEN COUNT(*) = 1 THEN MAX(costo_promedio_anterior) ELSE NULL END
            AS costo_promedio_anterior,
        CASE WHEN COUNT(*) = 1 THEN MAX(ultimo_costo_anterior) ELSE NULL END
            AS ultimo_costo_anterior,
        CASE WHEN COUNT(*) = 1 THEN MAX(ROUND(costo_promedio, 3)) ELSE NULL END
            AS costo_promedio_calculado,
        CASE WHEN COUNT(*) = 1 THEN MAX(ROUND(ultimo_costo, 3)) ELSE NULL END
            AS ultimo_costo_calculado
    FROM tmp_resultados_costo;

    DROP TEMPORARY TABLE IF EXISTS tmp_scope_costos;
    DROP TEMPORARY TABLE IF EXISTS tmp_eventos_costo;
    DROP TEMPORARY TABLE IF EXISTS tmp_resultados_costo;
END$$

CREATE PROCEDURE sp_recalcularCostosPorCompra(
    IN in_id_documento_compra BIGINT
)
BEGIN
    /* La validacion estricta del ID vive en sp_recalcularCostos. */
    CALL sp_recalcularCostos(0, in_id_documento_compra, 1);
END$$

DELIMITER ;
