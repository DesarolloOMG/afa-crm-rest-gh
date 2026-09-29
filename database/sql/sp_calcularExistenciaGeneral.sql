-- Ventas en fase 5: salida terminada, pendiente solo de timbrado.
-- Creacion ejecutada por artifacts/existencia-fase5-20260922/deploy.php con respaldo y validacion.
CREATE DEFINER=`afadev`@`%` PROCEDURE `sp_calcularExistenciaGeneral`(
  IN in_criterio       VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_spanish_ci,
  IN in_id_almacen     INT,     -- empresa_almacen; 0 = todos
  IN in_con_existencia TINYINT, -- 0=sin filtro, 1=solo con stock>0
  IN in_version        TINYINT  -- NULL => default 2; 1=movimientos, 2=series disponibles
)
BEGIN
  DECLARE v_version TINYINT DEFAULT 2;

  /* Handler: limpia temporales ante cualquier error */
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    DROP TEMPORARY TABLE IF EXISTS tmp_modelos;
    DROP TEMPORARY TABLE IF EXISTS tmp_mov_agg;
    DROP TEMPORARY TABLE IF EXISTS tmp_pendientes_fases;
    DROP TEMPORARY TABLE IF EXISTS tmp_stock_modelo_ea;

    DROP TEMPORARY TABLE IF EXISTS tmp_ult_precio;
    DROP TEMPORARY TABLE IF EXISTS tmp_ult_costo;

    DROP TEMPORARY TABLE IF EXISTS tmp_pendientes;
    DROP TEMPORARY TABLE IF EXISTS tmp_en_transito;
    DROP TEMPORARY TABLE IF EXISTS tmp_traspaso_entrada;
    DROP TEMPORARY TABLE IF EXISTS tmp_traspaso_salida;
    DROP TEMPORARY TABLE IF EXISTS tmp_tipo9;

    RESIGNAL;
  END;

  -- FORZAR SIEMPRE V1
  SET v_version = 2;

  /*==============================
    0) Universo por criterio
  ==============================*/
  DROP TEMPORARY TABLE IF EXISTS tmp_modelos;
  CREATE TEMPORARY TABLE tmp_modelos (
    id_modelo INT PRIMARY KEY
  ) ENGINE=MEMORY;

  INSERT INTO tmp_modelos(id_modelo)
  SELECT DISTINCT m.id
  FROM modelo m
  WHERE m.sku = in_criterio
     OR m.descripcion LIKE CONCAT('%', in_criterio, '%');

  INSERT IGNORE INTO tmp_modelos(id_modelo)
  SELECT DISTINCT ms.id_modelo
  FROM modelo_sinonimo ms
  WHERE ms.codigo = in_criterio;

  /* Si no encontró nada, regresa vacío y termina */
  IF (SELECT COUNT(1) FROM tmp_modelos) = 0 THEN

    SELECT
      NULL AS id_modelo, NULL AS codigo, NULL AS descripcion, NULL AS costo_extra, NULL AS precio,
      NULL AS costo_promedio, NULL AS ultimo_costo,
      NULL AS almacen, NULL AS id_almacen,
      NULL AS stock, NULL AS pendientes, NULL AS en_transito,
      NULL AS traspaso_entrada, NULL AS traspaso_salida, NULL AS traspaso_neto,
      NULL AS pretransferencia, NULL AS disponible,
      NULL AS tipo_producto, NULL AS marca, NULL AS subtipo, NULL AS vertical,
      NULL AS codigo_sat, NULL AS serie, NULL AS np
    WHERE 1=0;

    DROP TEMPORARY TABLE IF EXISTS tmp_modelos;

  ELSE

    /*=========================================================
      1) STOCK BASE
         v1 = movimientos
         v2 = series disponibles (producto.status=1)
    =========================================================*/
    IF v_version = 1 THEN

      /* ✅ acumulador por modelo+EA */
      DROP TEMPORARY TABLE IF EXISTS tmp_mov_agg;
      CREATE TEMPORARY TABLE tmp_mov_agg (
        id_modelo INT,
        id_ea     INT,
        cant      DECIMAL(18,4),
        PRIMARY KEY (id_modelo, id_ea)
      ) ENGINE=MEMORY;

      /*-------------------------------------------------------
        A) Generales por banderas
        (sin UNION para evitar "Can't reopen table" en temp)
      -------------------------------------------------------*/
      INSERT INTO tmp_mov_agg (id_modelo, id_ea, cant)
      SELECT
        mov.id_modelo,
        d.id_almacen_principal_empresa AS id_ea,
        SUM(
          CASE
            WHEN dt.sumaInventario  = 1 THEN  mov.cantidad
            WHEN dt.restaInventario = 1 THEN -mov.cantidad
            ELSE 0
          END
        ) AS cant
      FROM documento d
      JOIN documento_tipo dt ON dt.id = d.id_tipo
      JOIN movimiento mov ON mov.id_documento = d.id
      JOIN tmp_modelos tmx ON tmx.id_modelo = mov.id_modelo
      WHERE d.status = 1
        AND (d.id_fase IN (6,100,606,607) OR (d.id_tipo = 2 AND d.id_fase = 5))
        AND COALESCE(d.es_refactura, 0) = 0
        AND d.id_tipo NOT IN (12,9,5,0)
        AND (in_id_almacen = 0 OR d.id_almacen_principal_empresa = in_id_almacen)
      GROUP BY mov.id_modelo, d.id_almacen_principal_empresa
      ON DUPLICATE KEY UPDATE cant = cant + VALUES(cant);

      /*-------------------------------------------------------
        B) Compras: solo lo recepcionado suma inventario
      -------------------------------------------------------*/
      INSERT INTO tmp_mov_agg (id_modelo, id_ea, cant)
      SELECT
        mov.id_modelo,
        d.id_almacen_principal_empresa AS id_ea,
        SUM(dr.cantidad) AS cant
      FROM documento d
      JOIN movimiento mov ON mov.id_documento = d.id
      JOIN documento_recepcion dr ON dr.id_movimiento = mov.id
      JOIN tmp_modelos tmx ON tmx.id_modelo = mov.id_modelo
      WHERE d.status = 1
        AND d.id_fase IN (6,100,606,607)
        AND d.id_tipo = 0
        AND (in_id_almacen = 0 OR d.id_almacen_principal_empresa = in_id_almacen)
      GROUP BY mov.id_modelo, d.id_almacen_principal_empresa
      ON DUPLICATE KEY UPDATE cant = cant + VALUES(cant);

      /*-------------------------------------------------------
        C1) Traspaso autorizado: entrada
      -------------------------------------------------------*/
      INSERT INTO tmp_mov_agg (id_modelo, id_ea, cant)
      SELECT
        mov.id_modelo,
        d.id_almacen_principal_empresa AS id_ea,
        SUM(mov.cantidad) AS cant
      FROM documento d
      JOIN movimiento mov ON mov.id_documento = d.id
      JOIN tmp_modelos tmx ON tmx.id_modelo = mov.id_modelo
      WHERE d.status = 1
        AND d.id_fase IN (6,100,606,607)
        AND d.id_tipo = 5
        AND d.autorizado = 1
        AND (in_id_almacen = 0 OR d.id_almacen_principal_empresa = in_id_almacen)
      GROUP BY mov.id_modelo, d.id_almacen_principal_empresa
      ON DUPLICATE KEY UPDATE cant = cant + VALUES(cant);

      /*-------------------------------------------------------
        C2) Traspaso autorizado: salida (resta)
      -------------------------------------------------------*/
      INSERT INTO tmp_mov_agg (id_modelo, id_ea, cant)
      SELECT
        mov.id_modelo,
        d.id_almacen_secundario_empresa AS id_ea,
        SUM(-mov.cantidad) AS cant
      FROM documento d
      JOIN movimiento mov ON mov.id_documento = d.id
      JOIN tmp_modelos tmx ON tmx.id_modelo = mov.id_modelo
      WHERE d.status = 1
        AND d.id_fase IN (6,100,606,607)
        AND d.id_tipo = 5
        AND d.autorizado = 1
        AND (in_id_almacen = 0 OR d.id_almacen_secundario_empresa = in_id_almacen)
      GROUP BY mov.id_modelo, d.id_almacen_secundario_empresa
      ON DUPLICATE KEY UPDATE cant = cant + VALUES(cant);

      /* Pendientes fase 3 con series; fase 5 ya es salida de venta */
      DROP TEMPORARY TABLE IF EXISTS tmp_pendientes_fases;
      CREATE TEMPORARY TABLE tmp_pendientes_fases (
        id_modelo  INT,
        id_ea      INT,
        series_cnt INT,
        PRIMARY KEY (id_modelo, id_ea)
      ) ENGINE=MEMORY;

      INSERT INTO tmp_pendientes_fases (id_modelo, id_ea, series_cnt)
      SELECT
        mov.id_modelo,
        d.id_almacen_principal_empresa AS id_ea,
        COUNT(DISTINCT p.id) AS series_cnt
      FROM documento d
      JOIN documento_tipo dt ON dt.id = d.id_tipo AND dt.restaInventario = 1
      JOIN movimiento mov ON mov.id_documento = d.id
      JOIN movimiento_producto mp ON mp.id_movimiento = mov.id
      JOIN producto p ON p.id = mp.id_producto
      JOIN tmp_modelos tmx ON tmx.id_modelo = mov.id_modelo
      WHERE d.status = 1
        AND d.id_tipo = 2
        AND d.id_fase = 3
        AND COALESCE(d.es_refactura, 0) = 0
        AND TRIM(IFNULL(p.serie, '')) <> ''
        AND NOT EXISTS (
          SELECT 1
          FROM modelo_kardex mk
          WHERE mk.id_documento = d.id
            AND mk.id_modelo    = mov.id_modelo
        )
        AND (in_id_almacen = 0 OR d.id_almacen_principal_empresa = in_id_almacen)
      GROUP BY mov.id_modelo, d.id_almacen_principal_empresa;

      /* stock por EA (v1) */
      DROP TEMPORARY TABLE IF EXISTS tmp_stock_modelo_ea;
      CREATE TEMPORARY TABLE tmp_stock_modelo_ea (
        id_modelo           INT,
        id_empresa_almacen  INT,
        id_almacen          INT,
        id_empresa          INT,
        stock_movimientos   DECIMAL(18,4),
        pendientes_fase3    INT,
        stock_recalculado   DECIMAL(18,4),
        PRIMARY KEY (id_modelo, id_empresa_almacen)
      ) ENGINE=MEMORY;

      INSERT INTO tmp_stock_modelo_ea (
        id_modelo, id_empresa_almacen, id_almacen, id_empresa,
        stock_movimientos, pendientes_fase3, stock_recalculado
      )
      SELECT
        tm.id_modelo,
        ea.id,
        a.id,
        ea.id_empresa,
        IFNULL(ma.cant, 0),
        IFNULL(pf.series_cnt, 0),
        IFNULL(ma.cant, 0) - IFNULL(pf.series_cnt, 0)
      FROM tmp_modelos tm
      JOIN empresa_almacen ea
        ON (in_id_almacen = 0 OR ea.id = in_id_almacen)
      JOIN almacen a
        ON a.id = ea.id_almacen
       AND a.almacen <> 'N/A'
      LEFT JOIN tmp_mov_agg ma
        ON ma.id_modelo = tm.id_modelo AND ma.id_ea = ea.id
      LEFT JOIN tmp_pendientes_fases pf
        ON pf.id_modelo = tm.id_modelo AND pf.id_ea = ea.id;

    ELSE

      /* v2: stock por series disponibles */
      DROP TEMPORARY TABLE IF EXISTS tmp_stock_modelo_ea;
      CREATE TEMPORARY TABLE tmp_stock_modelo_ea (
        id_modelo           INT,
        id_empresa_almacen  INT,
        id_almacen          INT,
        id_empresa          INT,
        stock_movimientos   DECIMAL(18,4),
        pendientes_fase3    INT,
        stock_recalculado   DECIMAL(18,4),
        PRIMARY KEY (id_modelo, id_empresa_almacen)
      ) ENGINE=MEMORY;

      INSERT INTO tmp_stock_modelo_ea (
				id_modelo, id_empresa_almacen, id_almacen, id_empresa,
				stock_movimientos, pendientes_fase3, stock_recalculado
			)
			SELECT
				tm.id_modelo,
				ea.id,
				a.id,
				ea.id_empresa,

				CAST(IFNULL(COUNT(
						CASE 
								WHEN a.id = 3 THEN 
										CASE WHEN p.status != 1 THEN p.id END
								ELSE 
										CASE WHEN p.status = 1 THEN p.id END
						END
				), 0) AS DECIMAL(18,4)),

				0,

				CAST(IFNULL(COUNT(
						CASE 
								WHEN a.id = 3 THEN 
										CASE WHEN p.status != 1 THEN p.id END
								ELSE 
										CASE WHEN p.status = 1 THEN p.id END
						END
				), 0) AS DECIMAL(18,4))

			FROM tmp_modelos tm
			JOIN empresa_almacen ea
				ON (in_id_almacen = 0 OR ea.id = in_id_almacen)
			JOIN almacen a
				ON a.id = ea.id_almacen
			 AND a.almacen <> 'N/A'
			LEFT JOIN producto p
				ON p.id_modelo  = tm.id_modelo
			 AND p.id_almacen = a.id
			 AND TRIM(IFNULL(p.serie,'')) <> ''

			GROUP BY tm.id_modelo, ea.id, a.id, ea.id_empresa;

    END IF;

    /*=========================================================
      2) Último precio / último costo (window functions)
    =========================================================*/
    DROP TEMPORARY TABLE IF EXISTS tmp_ult_precio;
    CREATE TEMPORARY TABLE tmp_ult_precio (
      id_modelo INT PRIMARY KEY,
      precio    DECIMAL(18,4)
    ) ENGINE=MEMORY;

    INSERT INTO tmp_ult_precio (id_modelo, precio)
    SELECT id_modelo, precio
    FROM (
      SELECT
        mp.id_modelo,
        mp.precio,
        ROW_NUMBER() OVER (PARTITION BY mp.id_modelo ORDER BY mp.created_at DESC) AS rn
      FROM modelo_precio mp
      JOIN tmp_modelos tmx ON tmx.id_modelo = mp.id_modelo
    ) t
    WHERE t.rn = 1;

    DROP TEMPORARY TABLE IF EXISTS tmp_ult_costo;
    CREATE TEMPORARY TABLE tmp_ult_costo (
      id_modelo      INT PRIMARY KEY,
      costo_promedio DECIMAL(18,4),
      ultimo_costo   DECIMAL(18,4)
    ) ENGINE=MEMORY;

    INSERT INTO tmp_ult_costo (id_modelo, costo_promedio, ultimo_costo)
    SELECT id_modelo, costo_promedio, ultimo_costo
    FROM (
      SELECT
        mc.id_modelo,
        mc.costo_promedio,
        mc.ultimo_costo,
        ROW_NUMBER() OVER (PARTITION BY mc.id_modelo ORDER BY mc.updated_at DESC) AS rn
      FROM modelo_costo mc
      JOIN tmp_modelos tmx ON tmx.id_modelo = mc.id_modelo
    ) t
    WHERE t.rn = 1;

    /*=========================================================
      3) Agregados operativos
    =========================================================*/

    /* Pendientes ventas */
    DROP TEMPORARY TABLE IF EXISTS tmp_pendientes;
    CREATE TEMPORARY TABLE tmp_pendientes (
      id_modelo INT,
      id_ea     INT,
      pendientes BIGINT,
      PRIMARY KEY (id_modelo, id_ea)
    ) ENGINE=MEMORY;

    INSERT INTO tmp_pendientes (id_modelo, id_ea, pendientes)
    SELECT
      mov.id_modelo,
      d.id_almacen_principal_empresa AS id_ea,
      CAST(IFNULL(SUM(CAST(mov.cantidad AS SIGNED)), 0) AS SIGNED) AS pendientes
    FROM documento d
    JOIN movimiento mov ON mov.id_documento = d.id
    JOIN tmp_modelos tmx ON tmx.id_modelo = mov.id_modelo
    WHERE d.id_tipo = 2
      AND d.status = 1
      AND d.anticipada = 0
      AND d.id_fase IN (7,2,3,4)
      AND COALESCE(d.es_refactura, 0) = 0
      AND (in_id_almacen = 0 OR d.id_almacen_principal_empresa = in_id_almacen)
    GROUP BY mov.id_modelo, d.id_almacen_principal_empresa;

    /* En tránsito (compras 606): ordenado - recepcionado */
    DROP TEMPORARY TABLE IF EXISTS tmp_en_transito;
    CREATE TEMPORARY TABLE tmp_en_transito (
      id_modelo INT,
      id_ea     INT,
      en_transito BIGINT,
      PRIMARY KEY (id_modelo, id_ea)
    ) ENGINE=MEMORY;

    INSERT INTO tmp_en_transito (id_modelo, id_ea, en_transito)
    SELECT
      mov.id_modelo,
      d.id_almacen_principal_empresa AS id_ea,
      CAST(
        IFNULL(SUM(CAST(mov.cantidad AS SIGNED)), 0)
        -
        IFNULL(SUM(CAST(COALESCE(drSum.cant_rec, 0) AS SIGNED)), 0)
      AS SIGNED) AS en_transito
    FROM documento d
    JOIN movimiento mov ON mov.id_documento = d.id
    JOIN tmp_modelos tmx ON tmx.id_modelo = mov.id_modelo
    LEFT JOIN (
      SELECT dr.id_movimiento, SUM(dr.cantidad) AS cant_rec
      FROM documento_recepcion dr
      GROUP BY dr.id_movimiento
    ) drSum ON drSum.id_movimiento = mov.id
    WHERE d.id_tipo = 0
      AND d.status = 1
      AND d.id_fase = 606
      AND (in_id_almacen = 0 OR d.id_almacen_principal_empresa = in_id_almacen)
    GROUP BY mov.id_modelo, d.id_almacen_principal_empresa;

    /* Traspaso pendiente (no autorizado): entrada */
    DROP TEMPORARY TABLE IF EXISTS tmp_traspaso_entrada;
    CREATE TEMPORARY TABLE tmp_traspaso_entrada (
      id_modelo INT,
      id_ea     INT,
      cant BIGINT,
      PRIMARY KEY (id_modelo, id_ea)
    ) ENGINE=MEMORY;

    INSERT INTO tmp_traspaso_entrada (id_modelo, id_ea, cant)
    SELECT
      mov.id_modelo,
      d.id_almacen_principal_empresa AS id_ea,
      CAST(IFNULL(SUM(CAST(mov.cantidad AS SIGNED)), 0) AS SIGNED) AS cant
    FROM documento d
    JOIN movimiento mov ON mov.id_documento = d.id
    JOIN tmp_modelos tmx ON tmx.id_modelo = mov.id_modelo
    WHERE d.id_tipo = 5
      AND d.status = 1
      AND d.autorizado = 0
      AND (in_id_almacen = 0 OR d.id_almacen_principal_empresa = in_id_almacen)
    GROUP BY mov.id_modelo, d.id_almacen_principal_empresa;

    /* Traspaso pendiente (no autorizado): salida */
    DROP TEMPORARY TABLE IF EXISTS tmp_traspaso_salida;
    CREATE TEMPORARY TABLE tmp_traspaso_salida (
      id_modelo INT,
      id_ea     INT,
      cant BIGINT,
      PRIMARY KEY (id_modelo, id_ea)
    ) ENGINE=MEMORY;

    INSERT INTO tmp_traspaso_salida (id_modelo, id_ea, cant)
    SELECT
      mov.id_modelo,
      d.id_almacen_secundario_empresa AS id_ea,
      CAST(IFNULL(SUM(CAST(mov.cantidad AS SIGNED)), 0) AS SIGNED) AS cant
    FROM documento d
    JOIN movimiento mov ON mov.id_documento = d.id
    JOIN tmp_modelos tmx ON tmx.id_modelo = mov.id_modelo
    WHERE d.id_tipo = 5
      AND d.status = 1
      AND d.autorizado = 0
      AND (in_id_almacen = 0 OR d.id_almacen_secundario_empresa = in_id_almacen)
    GROUP BY mov.id_modelo, d.id_almacen_secundario_empresa;

    /* Tipo 9 (401-404) para pretransferencia */
    DROP TEMPORARY TABLE IF EXISTS tmp_tipo9;
    CREATE TEMPORARY TABLE tmp_tipo9 (
      id_modelo INT,
      id_ea     INT,
      cant BIGINT,
      PRIMARY KEY (id_modelo, id_ea)
    ) ENGINE=MEMORY;

    INSERT INTO tmp_tipo9 (id_modelo, id_ea, cant)
    SELECT
      mov.id_modelo,
      d.id_almacen_secundario_empresa AS id_ea,
      CAST(IFNULL(SUM(CAST(mov.cantidad AS SIGNED)), 0) AS SIGNED) AS cant
    FROM documento d
    JOIN movimiento mov ON mov.id_documento = d.id
    JOIN tmp_modelos tmx ON tmx.id_modelo = mov.id_modelo
    WHERE d.id_tipo = 9
      AND d.status = 1
      AND d.id_fase IN (401,402,403)
      AND (in_id_almacen = 0 OR d.id_almacen_secundario_empresa = in_id_almacen)
    GROUP BY mov.id_modelo, d.id_almacen_secundario_empresa;

    /*============================
      4) Resultado final
    ============================*/
    SELECT
      m.id  AS id_modelo,
      m.sku AS codigo,
      m.descripcion,
      m.costo_extra,

      IFNULL(up.precio, 0) AS precio,

      COALESCE(NULLIF(uc.costo_promedio, 0), m.costo, 0) AS costo_promedio,
      COALESCE(NULLIF(uc.ultimo_costo, 0),   m.costo, 0) AS ultimo_costo,

      al.almacen AS almacen,
      ea.id      AS id_almacen,

      CAST(IFNULL(se.stock_recalculado, 0) AS SIGNED) AS stock,
      CAST(IFNULL(pen.pendientes, 0) AS SIGNED) AS pendientes,
      CAST(IFNULL(et.en_transito, 0) AS SIGNED) AS en_transito,

      CAST(IFNULL(te.cant, 0) AS SIGNED) AS traspaso_entrada,
      CAST(IFNULL(ts.cant, 0) AS SIGNED) AS traspaso_salida,
      CAST((IFNULL(te.cant, 0) - IFNULL(ts.cant, 0)) AS SIGNED) AS traspaso_neto,

      CAST((IFNULL(t9.cant, 0) + (IFNULL(te.cant, 0) - IFNULL(ts.cant, 0))) AS SIGNED) AS pretransferencia,

      CAST((
        IFNULL(se.stock_recalculado, 0)
        -
        IFNULL(pen.pendientes, 0)
        -
        IF(v_version = 1, IFNULL(ts.cant, 0), 0)
      ) AS SIGNED) AS disponible,

      m.cat1 AS tipo_producto,
      m.cat2 AS marca,
      m.cat3 AS subtipo,
      m.cat4 AS vertical,
      m.clave_sat AS codigo_sat,
      CASE WHEN m.serie = 1 THEN 'SÍ' ELSE 'NO' END AS serie,
      m.np AS np

    FROM tmp_modelos tm
    JOIN modelo m               ON m.id = tm.id_modelo
    JOIN tmp_stock_modelo_ea se ON se.id_modelo = m.id
    JOIN empresa_almacen ea     ON ea.id = se.id_empresa_almacen
    JOIN almacen al             ON al.id = ea.id_almacen AND al.almacen <> 'N/A'

    LEFT JOIN tmp_ult_precio up ON up.id_modelo = m.id
    LEFT JOIN tmp_ult_costo  uc ON uc.id_modelo = m.id

    LEFT JOIN tmp_pendientes        pen ON pen.id_modelo = m.id AND pen.id_ea = ea.id
    LEFT JOIN tmp_en_transito       et  ON et.id_modelo  = m.id AND et.id_ea  = ea.id
    LEFT JOIN tmp_traspaso_entrada  te  ON te.id_modelo  = m.id AND te.id_ea  = ea.id
    LEFT JOIN tmp_traspaso_salida   ts  ON ts.id_modelo  = m.id AND ts.id_ea  = ea.id
    LEFT JOIN tmp_tipo9             t9  ON t9.id_modelo  = m.id AND t9.id_ea  = ea.id

    WHERE (in_con_existencia = 0 OR CAST(IFNULL(se.stock_recalculado,0) AS SIGNED) > 0)
    ORDER BY m.id, ea.id;

    /* Limpieza */
    DROP TEMPORARY TABLE IF EXISTS tmp_mov_agg;
    DROP TEMPORARY TABLE IF EXISTS tmp_pendientes_fases;
    DROP TEMPORARY TABLE IF EXISTS tmp_stock_modelo_ea;

    DROP TEMPORARY TABLE IF EXISTS tmp_ult_precio;
    DROP TEMPORARY TABLE IF EXISTS tmp_ult_costo;

    DROP TEMPORARY TABLE IF EXISTS tmp_pendientes;
    DROP TEMPORARY TABLE IF EXISTS tmp_en_transito;
    DROP TEMPORARY TABLE IF EXISTS tmp_traspaso_entrada;
    DROP TEMPORARY TABLE IF EXISTS tmp_traspaso_salida;
    DROP TEMPORARY TABLE IF EXISTS tmp_tipo9;

    DROP TEMPORARY TABLE IF EXISTS tmp_modelos;

  END IF;

END;
