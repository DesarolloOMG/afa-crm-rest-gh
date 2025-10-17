<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return date('Y-m-d H:i:s', time());
});

# API Rest Cyberpuerta
$router->group(['prefix' => 'api', 'middleware' => 'throttle'], function () use ($router) {
    $router->group(['prefix' => 'v1'], function () use ($router) {
        $router->group(['prefix' => 'order', 'middleware' => 'jwt.auth'], function () use ($router) {
            $router->post('create', 'APIController@api_v1_order_create');
            $router->post('confirm', 'APIController@api_v1_order_confirm');
            $router->get('status/{documento}', 'APIController@api_v1_order_status');
            $router->get('cancel/{documento}', 'APIController@api_v1_order_cancel');

            $router->group(['prefix' => 'data', 'middleware' => 'jwt.auth'], function () use ($router) {
                $router->get('providers', 'APIController@api_v1_order_data_providers');
                $router->get('currencies', 'APIController@api_v1_order_data_currencies');
            });

            $router->group(['prefix' => 'files', 'middleware' => 'jwt.auth'], function () use ($router) {
                $router->post('add', 'APIController@api_v1_order_files_add');
                $router->get('view/{documento}', 'APIController@api_v1_order_files_view');
            });
        });

        $router->group(['prefix' => 'product', 'middleware' => 'jwt.auth'], function () use ($router) {
            $router->get('availability/{producto}', 'APIController@api_v1_product_availability');
            $router->get('list', 'APIController@api_v1_product_list');
        });

        $router->post('access_token', 'APIController@api_v1_access_token');
    });

    $router->group(['prefix' => 'sandbox'], function () use ($router) {
        $router->group(['prefix' => 'order', 'middleware' => 'jwt.auth'], function () use ($router) {
            $router->post('create', 'APIController@api_sandbox_order_create');
            $router->post('confirm', 'APIController@api_sandbox_order_confirm');
            $router->get('status/{documento}', 'APIController@api_sandbox_order_status');
            $router->get('cancel/{documento}', 'APIController@api_sandbox_order_cancel');

            $router->group(['prefix' => 'data', 'middleware' => 'jwt.auth'], function () use ($router) {
                $router->get('providers', 'APIController@api_sandbox_order_data_providers');
                $router->get('currencies', 'APIController@api_sandbox_order_data_currencies');
            });

            $router->group(['prefix' => 'files', 'middleware' => 'jwt.auth'], function () use ($router) {
                $router->post('add', 'APIController@api_sandbox_order_files_add');
                $router->get('view/{documento}', 'APIController@api_sandbox_order_files_view');
            });
        });

        $router->group(['prefix' => 'product', 'middleware' => 'jwt.auth'], function () use ($router) {
            $router->get('availability/{producto}', 'APIController@api_sandbox_product_availability');
        });
    });
});

$router->group(['prefix' => 'auth'], function () use ($router) {
    $router->post('login', 'AuthController@auth_login');
    $router->post('login2', 'AuthController@auth_login2');
    $router->post('reset', 'AuthController@auth_reset');
    $router->post('auth-code', 'AuthController@auth_code');
    $router->post('encuesta', 'AuthController@auth_encuesta');
});

$router->group(['prefix' => '', 'middleware' => 'jwt.auth'], function () use ($router) {
    $router->get('php', function () use ($router) {
        echo phpinfo();
    });

    # Información del dashboard
    $router->group(['prefix' => 'dashboard'], function () use ($router) {

        $router->group(['prefix' => 'venta'], function () use ($router) {
            $router->get('marketplace', 'DashboardController@dashboard_venta_marketplace');
        });

        $router->group(['prefix' => 'user'], function () use ($router) {
            $router->get('subnivel-nivel/{userid}', 'DashboardController@subnivel_nivel');
        });
    });

    # Menú personal
    $router->group(['prefix' => 'personal'], function () use ($router) {
        $router->group(['prefix' => 'modificacion'], function () use ($router) {
            $router->get('data', 'PersonalController@personal_modificacion_data');

            # Crear petición de modificación
            $router->post('crear', 'PersonalController@personal_modificacion_crear');

            $router->group(['prefix' => 'pendiente'], function () use ($router) {
                $router->get('data', 'PersonalController@personal_modificacion_pendiente_data');
                $router->post('guardar', 'PersonalController@personal_modificacion_pendiente_guardar');
            });

            $router->group(['prefix' => 'historial'], function () use ($router) {
                $router->get('data', 'PersonalController@personal_modificacion_historial_data');
                $router->post('guardar', 'PersonalController@personal_modificacion_historial_guardar');
            });
        });

        $router->group(['prefix' => 'todo'], function () use ($router) {
            # Crear petición de modificación
            $router->post('data', 'PersonalController@personal_todo_data');
            $router->post('crear', 'PersonalController@personal_todo_crear');
            $router->post('actualizar', 'PersonalController@personal_todo_actualizar');
        });
    });

    # Menú general
    $router->group(['prefix' => 'general'], function () use ($router) {
        $router->group(['prefix' => 'busqueda'], function () use ($router) {
            $router->group(['prefix' => 'producto'], function () use ($router) {
                $router->get('data', 'GeneralController@general_busqueda_producto_data');
                $router->post('kardex-crm', 'GeneralController@general_busqueda_producto_kardex_crm');
                $router->get('costo/{producto}', 'GeneralController@general_busqueda_producto_costo');
                $router->get('precio/{producto}/{fecha}', 'GeneralController@general_busqueda_producto_precio');
                $router->post('existencia', 'GeneralController@general_busqueda_producto_existencia');
            });

            $router->group(['prefix' => 'venta'], function () use ($router) {
                $router->post('informacion', 'GeneralController@general_busqueda_venta_informacion');
                $router->get('descargarNota/{nota}', 'GeneralController@general_busqueda_venta_informacion_descargar_nota');
                $router->get('descargarGarantia/{id}', 'GeneralController@general_busqueda_venta_informacion_descargar_garantia');
                $router->post('nota/informacion', 'GeneralController@general_busqueda_venta_nota_informacion');
                $router->post('nota/informacion/pendientes', 'GeneralController@general_busqueda_venta_nota_informacion_pendientes');
                $router->post('nota/informacion/canceladas', 'GeneralController@general_busqueda_venta_nota_informacion_canceladas');
                $router->get('borrar/{dropbox}', 'GeneralController@general_busqueda_venta_borrar');
                $router->post('guardar', 'GeneralController@general_busqueda_venta_guardar');
                $router->post('refacturacion', 'GeneralController@general_busqueda_venta_refacturacion');
                $router->get('nota/{documento}', 'GeneralController@general_busqueda_venta_nota');
                $router->post('nota-credito', 'GeneralController@general_busqueda_venta_crear_nota');
                $router->post('autorizar-sin-venta', 'GeneralController@general_busqueda_sin_venta_autorizar_nota');
                $router->post('autorizar-garantia', 'GeneralController@general_busqueda_venta_autorizar_nota_garantia');
            });

            $router->group(['prefix' => 'serie'], function () use ($router) {
                $router->get('{serie}', 'GeneralController@general_busqueda_serie');
            });
        });

        $router->group(['prefix' => 'reporte'], function () use ($router) {
            #Reportes NDC
            $router->post('notas-autorizadas', 'GeneralController@general_reporte_notas_autorizadas');

            #Reporte de ventas
            $router->group(['prefix' => 'venta'], function () use ($router) {
                $router->get('data', 'GeneralController@general_reporte_venta_data');
                $router->get('diario/{fecha_inicial}/{fecha_final}', 'GeneralController@general_reporte_venta_diario');
                $router->post('historial', 'GeneralController@general_reporte_venta_historial');

                $router->post('mercadolibre', 'GeneralController@general_reporte_venta_mercadolibre');
                $router->post('amazon', 'GeneralController@general_reporte_venta_amazon');
                $router->post('huawei', 'GeneralController@general_reporte_venta_huawei');
                $router->post('devolucion', 'GeneralController@general_reporte_venta_devolucion');

                $router->group(['prefix' => 'mercadolibre'], function () use ($router) {
                    $router->post('venta', 'GeneralController@general_reporte_venta_mercadolibre_venta');
                    $router->post('estatus', 'GeneralController@general_reporte_venta_mercadolibre_estatus');
                    $router->post('estatus-cancelados', 'GeneralController@general_reporte_venta_mercadolibre_estatus_cancelados');
                    $router->post('crm', 'GeneralController@general_reporte_venta_mercadolibre_crm');
                    $router->post('publicacion', 'GeneralController@general_reporte_venta_mercadolibre_publicacion');
                    $router->post('catalogo', 'GeneralController@general_reporte_venta_mercadolibre_catalogo');
                    $router->post('ventas-crm', 'GeneralController@general_reporte_venta_mercadolibre_ventas_crm');
                    $router->post('ventas-ml', 'GeneralController@general_reporte_venta_mercadolibre_ventas_ml');
                    $router->post('comparacion', 'GeneralController@general_reporte_venta_mercadolibre_comparacion');
                    $router->post('revision', 'GeneralController@general_reporte_venta_mercadolibre_revision');
                    $router->post('revision-canceladas', 'GeneralController@general_reporte_venta_mercadolibre_revision_canceladas');
                });

                $router->group(['prefix' => 'api'], function () use ($router) {
                    $router->post('credenciales', 'ReporteController@general_reporte_venta_api_credenciales');
                });

                $router->group(['prefix' => 'producto'], function () use ($router) {
                    $router->post('precio', 'GeneralController@general_reporte_venta_producto_precio');
                    $router->post('utilidad', 'GeneralController@general_reporte_venta_producto_utilidad');

                    $router->group(['prefix' => 'categoria'], function () use ($router) {
                        $router->post('', 'GeneralController@general_reporte_venta_producto_categoria');
                        $router->get('data', 'GeneralController@general_reporte_venta_producto_categoria_data');
                    });

                    $router->group(['prefix' => 'serie'], function () use ($router) {
                        $router->get('productos/{documento}', 'GeneralController@general_reporte_venta_producto_serie_productos');
                        $router->post('reporte', 'GeneralController@general_reporte_venta_producto_serie_reporte');
                    });
                });

                $router->group(['prefix' => 'empresarial'], function () use ($router) {
                    $router->get('detalle/{empresa}/{modulo}/{fecha_inicial}/{fecha_final}', 'GeneralController@general_reporte_venta_empresarial_detalle');
                });
            });

            #Reporte de procesos en logistica
            $router->group(['prefix' => 'logistica'], function () use ($router) {
                $router->group(['prefix' => 'guia'], function () use ($router) {
                    $router->get('data/{fecha_inicial}/{fecha_final}', 'GeneralController@general_reporte_logistica_guia_data');
                    $router->post('decode', 'GeneralController@general_reporte_logistica_guia_decode');
                });

                $router->group(['prefix' => 'manifiesto'], function () use ($router) {
                    $router->get('data', 'GeneralController@general_reporte_logistica_manifiesto_data');
                    $router->get('generar/{paqueteria}/{fecha}', 'GeneralController@general_reporte_logistica_manifiesto_generar');
                });

                $router->group(['prefix' => 'marketplace'], function () use ($router) {
                    $router->get('{fecha_inicial}/{fecha_final}', 'GeneralController@general_reporte_logistica_marketplace');
                });
            });

            #Reporte de procesos en logistica
            $router->group(['prefix' => 'pendientes'], function () use ($router) {
                $router->get('ingresos-egresos', 'GeneralController@general_reporte_pendientes_ingresosegresos');
            });

            #Reporte de procesos en logistica
            $router->group(['prefix' => 'contabilidad'], function () use ($router) {
                $router->post('recibo_almacen', 'ReporteController@reporte_contabilidad_recibo_almacen');
                $router->get('recibo_almacen_futuretec', 'ReporteController@reporte_contabilidad_recibo_almacen_futuretec');
                $router->post('refacturacion', 'GeneralController@general_reporte_contabilidad_refacturacion');
                $router->post('factura-sin-timbre', 'GeneralController@general_reporte_contabilidad_factura_sin_timbre');
                $router->post('costo-sobre-venta', 'GeneralController@general_reporte_contabilidad_costo_sobre_venta');
            });

            $router->get('ventas_canceladas', 'ReporteController@ventas_canceladas');

            # Reportes relacionados a la compra
            $router->group(['prefix' => 'compra'], function () use ($router) {
                $router->post('producto', 'GeneralController@general_reporte_compra_producto');
            });

            $router->group(['prefix' => 'orden-compra'], function () use ($router) {
                $router->post('producto-transito', 'GeneralController@general_orden_compra_producto_transito');
                $router->post('recepciones', 'GeneralController@general_orden_compra_recepciones');
            });

            $router->group(['prefix' => 'producto'], function () use ($router) {
                $router->get('antiguedad/data', 'GeneralController@general_reporte_producto_antiguedad_data');
                $router->get('antiguedad/{almacen}', 'GeneralController@general_reporte_producto_antiguedad');
                $router->post('top-venta', 'GeneralController@general_reporte_producto_top_venta');
                $router->post('costo-precio-promedio', 'GeneralController@general_reporte_producto_costo_precio_promedio');
                $router->get('caducidad/{disponibles}', 'GeneralController@general_reporte_producto_caducidad');

                $router->group(['prefix' => 'incidencia'], function () use ($router) {
                    $router->post('', 'GeneralController@general_reporte_producto_incidencia');
                    $router->post('detalle', 'GeneralController@general_reporte_producto_incidencia_detalle');
                });

                $router->group(['prefix' => 'btob'], function () use ($router) {
                    $router->post('reporte', 'GeneralController@general_reporte_producto_btob_reporte');
                });
            });

            $router->group(['prefix' => 'nota-credito'], function () use ($router) {
                $router->get('data', 'GeneralController@general_reporte_nota_credito_data');
                $router->post('reporte', 'GeneralController@general_reporte_nota_credito_reporte');
            });
        });

        $router->group(['prefix' => 'notificacion'], function () use ($router) {
            $router->get('data', 'GeneralController@general_notificacion_data');
            $router->get('problema', 'GeneralController@general_notificacion_problema');
            $router->post('dismiss', 'GeneralController@general_notificacion_dismiss');
        });
    });

    # Menú compras
    $router->group(['prefix' => 'compra'], function () use ($router) {
        $router->group(['prefix' => 'compra'], function () use ($router) {
            $router->group(['prefix' => 'crear'], function () use ($router) {
                $router->post('', 'CompraController@compra_compra_crear');
                $router->get('data', 'CompraController@compra_compra_crear_data');
                $router->post('producto', 'CompraController@compra_compra_crear_producto');
                $router->post('uuid', 'CompraController@compra_compra_crear_uuid');
                $router->get('usuario/{criterio}', 'CompraController@compra_compra_crear_usuario');
                $router->get('recepcion/{recepcion}', 'CompraController@compra_compra_crear_get_recepcion');
            });

            $router->group(['prefix' => 'editar'], function () use ($router) {
                $router->get('data/{serie}/{folio}', 'CompraController@compra_compra_editar_data');
                $router->post('guardar', 'CompraController@compra_compra_editar_guardar');
            });

            $router->group(['prefix' => 'corroborar'], function () use ($router) {
                $router->get('data', 'CompraController@compra_compra_corroborar_data');
                $router->post('guardar', 'CompraController@compra_compra_corroborar_guardar');
            });

            $router->group(['prefix' => 'autorizar'], function () use ($router) {
                $router->get('data', 'CompraController@compra_compra_autorizar_data');
                $router->post('guardar', 'CompraController@compra_compra_autorizar_guardar');
                $router->get('cancelar/{documento}', 'CompraController@compra_compra_autorizar_cancelar');
            });

            $router->group(['prefix' => 'pendiente'], function () use ($router) {
                $router->get('data', 'CompraController@compra_compra_pendiente_data');
                $router->get('etiqueta/{codigo}/{cantidad}', 'CompraController@compra_compra_pendiente_etiqueta');
                $router->post('confirmar', 'CompraController@compra_compra_pendiente_confirmar');
                $router->post('guardar', 'CompraController@compra_compra_pendiente_guardar');
            });

            $router->group(['prefix' => 'historial'], function () use ($router) {
                $router->post('data', 'CompraController@compra_compra_historial_data');
                $router->post('guardar', 'CompraController@compra_compra_historial_guardar');
                $router->post('saldar', 'CompraController@compra_compra_historial_saldar');
            });

            $router->get('backorder', 'CompraController@compra_compra_backorder');
        });

        $router->group(['prefix' => 'orden'], function () use ($router) {
            $router->group(['prefix' => 'requisicion'], function () use ($router) {
                $router->post('', 'CompraController@compra_orden_requisicion');
                $router->get('data', 'CompraController@compra_orden_requisicion_data');
            });

            $router->group(['prefix' => 'autorizacion-requisicion'], function () use ($router) {
                $router->get('data', 'CompraController@compra_orden_autorizacion_requisicion_data');
                $router->post('guardar', 'CompraController@compra_orden_autorizacion_requisicion_guardar');
                $router->post('cancelar', 'CompraController@compra_orden_autorizacion_requisicion_cancelar');
            });

            $router->group(['prefix' => 'orden'], function () use ($router) {
                $router->get('data', 'CompraController@compra_orden_orden_data');
                $router->post('crear', 'CompraController@compra_orden_orden_crear');
            });

            $router->group(['prefix' => 'modificacion'], function () use ($router) {
                $router->get('data', 'CompraController@compra_orden_modificacion_data');
                $router->get('eliminar/{documento}/{eliminar}', 'CompraController@compra_orden_modificacion_eliminar');
                $router->post('guardar', 'CompraController@compra_orden_modificacion_guardar');
            });

            $router->group(['prefix' => 'recepcion'], function () use ($router) {
                $router->get('data', 'CompraController@compra_orden_recepcion_data');
                $router->post('guardar', 'CompraController@compra_orden_recepcion_guardar');
            });

            $router->group(['prefix' => 'historial'], function () use ($router) {
                $router->post('data', 'CompraController@compra_orden_historial_data');
                $router->get('descargar/{documento}', 'CompraController@compra_orden_historial_descargar');
                $router->get('descargar-recepcion-pdf/{recepcion}', 'CompraController@compra_orden_historial_descargar_recepcion_pdf');
                $router->post('guardar', 'CompraController@compra_orden_historial_guardar');
                $router->post('crear-orden-copia', 'CompraController@compra_orden_historial_crear_orden_copia');
            });
        });

        $router->group(['prefix' => 'producto'], function () use ($router) {
            $router->group(['prefix' => 'gestion'], function () use ($router) {
                $router->get('data', 'CompraController@compra_producto_gestion_data');
                $router->post('producto', 'CompraController@compra_producto_gestion_producto');
                $router->post('productos', 'CompraController@compra_producto_gestion_productos');
                $router->post('crear', 'CompraController@compra_producto_gestion_crear');
                $router->post('codigo/sat', 'CompraController@compra_producto_buscar_codigo_sat');
                $router->get('imagen/{dropbox}', 'CompraController@compra_producto_gestion_imagen');
                $router->post('producto-proveedor', 'CompraController@compra_producto_gestion_producto_proveedor');

                $router->group(['prefix' => 'crear-editar'], function () use ($router) {
                    $router->post('data', 'CompraController@compra_producto_gestion_producto_crear_editar_data');
                });
            });

            $router->group(['prefix' => 'importacion'], function () use ($router) {
                $router->post('crear', 'CompraController@compra_producto_importacion_crear');
            });

            $router->group(['prefix' => 'categoria'], function () use ($router) {
                $router->get('data', 'CompraController@compra_producto_categoria_get_data');
                $router->post('crear', 'CompraController@compra_producto_categoria_post_crear');
            });

            $router->group(['prefix' => 'sinonimo'], function () use ($router) {
                $router->post('producto', 'CompraController@compra_producto_sinonimo_post_producto');
                $router->post('guardar', 'CompraController@compra_producto_sinonimo_post_guardar');
                $router->post('sinonimo', 'CompraController@compra_producto_sinonimo_post_sinonimo');
            });

            $router->get('buscar/{criterio}', 'CompraController@compra_producto_buscar');
        });

        $router->group(['prefix' => 'presupuesto'], function () use ($router) {
            $router->get('data', 'CompraController@compra_presupuesto_data');
            $router->get('guardar/{presupuesto}', 'CompraController@compra_presupuesto_guardar');
        });

        $router->group(['prefix' => 'tipo-cambio'], function () use ($router) {
            $router->get('data', 'CompraController@compra_tipo_cambio_data');
            $router->get('guardar/{tc}', 'CompraController@compra_tipo_cambio_guardar');
        });

        $router->group(['prefix' => 'proveedor'], function () use ($router) {
            $router->get('data', 'CompraController@compra_proveedor_data');
            $router->get('data/{criterio}', 'CompraController@compra_proveedor_get_data');
            $router->post('guardar', 'CompraController@compra_proveedor_post_guardar');
        });

        $router->group(['prefix' => 'cliente'], function () use ($router) {
            $router->get('data/{criterio}/{empresa}', 'CompraController@compra_cliente_get_data');
            $router->post('guardar', 'CompraController@compra_cliente_post_guardar');
        });

        $router->group(['prefix' => 'pedimento'], function () use ($router) {
            $router->group(['prefix' => 'crear'], function () use ($router) {
                $router->get('data', 'CompraController@compra_pedimento_crear_get_data');
            });
        });
    });

    # Menú venta
    $router->group(['prefix' => 'venta'], function () use ($router) {
        $router->group(['prefix' => 'venta'], function () use ($router) {

            $router->group(['prefix' => 'crear'], function () use ($router) {
                $router->post('', 'VentaController@venta_venta_crear');

                $router->get('data', 'VentaController@venta_venta_crear_data');
                $router->get('buscar-cliente/{criterio}', 'VentaController@venta_venta_crear_buscar_cliente');

                $router->group(['prefix' => 'cliente'], function () use ($router) {
                    $router->get('direccion/{rfc}', 'VentaController@venta_venta_crear_cliente_direccion');
                });

                $router->post('informacion', 'VentaController@venta_venta_crear_informacion');

                $router->group(['prefix' => 'producto'], function () use ($router) {
                    $router->get('existencia/{producto}/{almacen}/{cantidad}', 'VentaController@venta_venta_crear_producto_existencia');
                    $router->get('proveedor/existencia/{producto}/{almacen}/{cantidad}/{proveedor}', 'VentaController@venta_venta_crear_producto_proveedor_existencia');
                });

                $router->group(['prefix' => 'envio'], function () use ($router) {
                    $router->post('cotizar', 'VentaController@venta_venta_crear_envio_cotizar');
                });

                $router->get('existe/{venta}/{marketplace}', 'VentaController@venta_venta_crear_existe');
                $router->post('guardar', 'VentaController@venta_autorizar_guardar');
            });

            $router->group(['prefix' => 'autorizar'], function () use ($router) {
                $router->post('', 'VentaController@venta_venta_autorizar');
                $router->get('data', 'VentaController@venta_venta_autorizar_data');
            });

            $router->group(['prefix' => 'editar'], function () use ($router) {
                $router->post('', 'VentaController@venta_venta_editar');
                $router->get('documento/{documento}', 'VentaController@venta_venta_editar_documento');

                $router->group(['prefix' => 'producto'], function () use ($router) {
                    $router->get('borrar/{movimiento}', 'VentaController@venta_venta_editar_producto_borrar');
                });
            });

            $router->group(['prefix' => 'cancelar'], function () use ($router) {
                $router->post('', 'VentaController@venta_venta_cancelar');
                $router->get('data', 'VentaController@venta_venta_cancelar_data');
            });

            $router->group(['prefix' => 'problema'], function () use ($router) {
                $router->post('', 'VentaController@venta_venta_problema');
                $router->get('data', 'VentaController@venta_venta_problema_data');
            });

            $router->post('nota', 'VentaController@venta_venta_nota');

            $router->post('relacionar-pdf-xml', 'VentaController@venta_venta_relacionar_pdf_xml');
            $router->get('descargar-pdf-xml/{type}/{document}', 'VentaController@venta_venta_descargar_pdf_xml');

            $router->group(['prefix' => 'importacion'], function () use ($router) {
                $router->post('', 'VentaController@venta_venta_importacion');
                $router->get('data', 'VentaController@venta_venta_importacion_data');
            });

            $router->group(['prefix' => 'mensaje'], function () use ($router) {
                $router->post('', 'VentaController@venta_venta_mensaje');
                $router->get('data', 'VentaController@venta_venta_mensaje_data');
            });

            $router->group(['prefix' => 'pedido'], function () use ($router) {
                $router->group(['prefix' => 'crear'], function () use ($router) {
                    $router->post('', 'VentaController@venta_venta_pedido_crear');
                });

                $router->group(['prefix' => 'pendiente'], function () use ($router) {
                    $router->get('data', 'VentaController@venta_venta_pedido_pendiente_data');
                    $router->get('convertir/{documento}', 'VentaController@venta_venta_pedido_pendiente_convertir');
                });
            });
        });

        $router->group(['prefix' => 'publicacion'], function () use ($router) {
            $router->get('data', 'VentaController@venta_publicacion_data');
            $router->get('actualizar/{marketplace_id}', 'VentaController@venta_publicacion_actualizar');
            $router->get('competencia/{competencia}', 'VentaController@venta_publicacion_competencia');
            $router->get('oferta/{oferta}', 'VentaController@venta_publicacion_oferta');
            $router->get('ventas-15-dias/{publicacion_id}', 'VentaController@venta_publicacion_15_dias');
            $router->post('guardar', 'VentaController@venta_publicacion_guardar');
            $router->post('pretransferencia', 'VentaController@venta_publicacion_pretransferencia');
        });

        $router->group(['prefix' => 'externo'], function () use ($router) {
            $router->post('crear', 'ExternoController@venta_externo_crear');
        });

        $router->group(['prefix' => 'nota-credito'], function () use ($router) {
            $router->get('data', 'VentaController@venta_nota_credito_get_data');
            $router->post('autorizar/data', 'VentaController@venta_nota_autorizar_get_data');
            $router->post('autorizar/sin-venta/data', 'VentaController@venta_sin_venta_nota_autorizar_get_data');
            $router->post('autorizar/soporte/data', 'VentaController@venta_nota_autorizar_soporte_get_data');
            $router->post('autorizar/autorizado', 'VentaController@venta_nota_autorizar_autorizado');
            $router->post('autorizar/rechazado', 'VentaController@venta_nota_autorizar_rechazado');
            $router->post('autorizar/soporte/autorizado', 'VentaController@venta_nota_autorizar_garantia_autorizado');
            $router->post('autorizar/soporte/rechazado', 'VentaController@venta_nota_autorizar_garantia_rechazado');
            $router->post('autorizar/sin-venta/autorizado', 'VentaController@venta_nota_sin_venta_autorizar_autorizado');
            $router->post('autorizar/sin-venta/rechazado', 'VentaController@venta_nota_sin_venta_autorizar_rechazado');
        });

        $router->group(['prefix' => 'publicaciones'], function () use ($router) {
            $router->get('data', 'VentaController@venta_publicaciones_data');
            $router->post('crear', 'VentaController@venta_publicaciones_crear');
            $router->get('marketplaces/data', 'VentaController@venta_marketplaces_autorizados_data');
            $router->post('marketplaces/gestion', 'VentaController@venta_marketplaces_autorizados_gestion');
        });

        $router->group(['prefix' => 'mercadolibre'], function () use ($router) {

            $router->group(['prefix' => 'api'], function () use ($router) {
                $router->post('listing_types', 'MercadolibreController@api_getListingTypes');
                $router->post('sale_terms', 'MercadolibreController@api_getSaleTerms');
                $router->post('category_variants', 'MercadolibreController@api_getCategoryVariants');
                $router->post('usersMe', 'MercadolibreController@api_usersMe');
                $router->post('userID', 'MercadolibreController@api_userID');
                $router->post('brands', 'MercadolibreController@api_brands');
                $router->post('items', 'MercadolibreController@api_items');
                $router->post('itemsDescription', 'MercadolibreController@api_itemsDescription');
            });

            $router->group(['prefix' => 'token'], function () use ($router) {
                $router->get('data/{marketplace_id}', 'VentaController@venta_mercadolibre_token');
            });

            $router->group(['prefix' => 'pregunta-respuesta'], function () use ($router) {
                $router->get('data', 'VentaController@venta_mercadolibre_pregunta_respuesta_get_data');
                $router->get('preguntas/{marketplace_id}', 'VentaController@venta_mercadolibre_pregunta_respuesta_get_preguntas');
                $router->post('responder', 'VentaController@venta_mercadolibre_pregunta_respuesta_post_responder');
                $router->post('borrar', 'VentaController@venta_mercadolibre_pregunta_respuesta_post_borrar');
                $router->post('bloquear-usuario', 'VentaController@venta_mercadolibre_pregunta_respuesta_post_bloquear_usuario');
            });

            $router->group(['prefix' => 'nueva-publicacion'], function () use ($router) {
                $router->post('', 'VentaController@venta_mercadolibre_nueva_publicacion');
            });

            $router->group(['prefix' => 'publicaciones'], function () use ($router) {
                $router->get('data', 'VentaController@venta_mercadolibre_publicaciones_data');
                $router->get('publicacion-data/{publicacion_id}', 'VentaController@venta_mercadolibre_publicaciones_publicacion_data');
                $router->post('busqueda', 'VentaController@venta_mercadolibre_publicaciones_busqueda');
                $router->post('actualizar', 'VentaController@venta_mercadolibre_publicaciones_actualizar');
                $router->post('guardar', 'VentaController@venta_mercadolibre_publicaciones_guardar');
                $router->post('guardar-marketplace', 'VentaController@venta_mercadolibre_publicaciones_guardar_marketplace');
            });

            $router->post('valida-venta', 'VentaController@venta_mercadolibre_valida_venta');
            $router->post('validar-ventas-data', 'VentaController@venta_mercadolibre_validar_ventas_data');
        });

        $router->group(['prefix' => 'amazon'], function () use ($router) {
            $router->group(['prefix' => 'publicaciones'], function () use ($router) {
                $router->get('data', 'VentaController@venta_amazon_publicaciones_data');
            });
        });

        $router->group(['prefix' => 'claroshop'], function () use ($router) {
            $router->group(['prefix' => 'publicaciones'], function () use ($router) {
                $router->get('data', 'VentaController@venta_claroshop_publicaciones_data');
            });
        });

        $router->group(['prefix' => 'shopify'], function () use ($router) {
            $router->post('importar-ventas', 'VentaController@venta_shopify_importar_ventas');
            $router->post('cotizar-guia', 'VentaController@venta_shopify_cotizar_guia');
        });

        $router->group(['prefix' => 'walmart'], function () use ($router) {
            $router->post('importar-ventas', 'VentaController@venta_walmart_importar_ventas');
        });

        $router->group(['prefix' => 'liverpool'], function () use ($router) {
            $router->post('importar-ventas', 'VentaController@venta_liverpool_importar_ventas');
            $router->get('getData', 'VentaController@venta_liverpool_getData');
        });
    });

    # Menú soporte
    $router->group(['prefix' => 'soporte'], function () use ($router) {
        $router->post('buscar/usuario', 'SoporteController@buscarUsuario');
        $router->group(['prefix' => 'garantia-devolucion'], function () use ($router) {
            $router->get('data', 'SoporteController@soporte_garantia_devolucion_data');
            $router->get('venta/{venta}', 'SoporteController@soporte_garantia_devolucion_venta');
            $router->post('eliminar', 'SoporteController@soporte_garantia_devolucion_eliminar_documento');

            $router->post('crear', 'SoporteController@soporte_garantia_devolucion_crear');

            $router->group(['prefix' => 'devolucion'], function () use ($router) {
                $router->get('data', 'SoporteController@soporte_garantia_devolucion_devolucion_data');
                $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_devolucion_guardar');

                $router->group(['prefix' => 'revision'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_devolucion_revision_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_devolucion_revision_guardar');
                });

                $router->group(['prefix' => 'indemnizacion'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_devolucion_indemnizacion_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_devolucion_indemnizacion_guardar');
                });

                $router->group(['prefix' => 'reclamo'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_devolucion_reclamo_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_devolucion_reclamo_guardar');
                });

                $router->group(['prefix' => 'historial'], function () use ($router) {
                    $router->post('data', 'SoporteController@soporte_garantia_devolucion_devolucion_historial_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_devolucion_historial_guardar');
                });
            });

            $router->group(['prefix' => 'garantia'], function () use ($router) {
                $router->get('producto/{sku}', 'SoporteController@getProductoBySku');

                $router->group(['prefix' => 'recibir'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_garantia_recibir_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_garantia_recibir_guardar');
                });

                $router->group(['prefix' => 'revision'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_garantia_revision_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_garantia_revision_guardar');
                });

                $router->group(['prefix' => 'cambio'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_garantia_cambio_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_garantia_cambio_guardar');
                    $router->post('documento', 'SoporteController@soporte_garantia_devolucion_garantia_cambio_documento');
                });

                $router->group(['prefix' => 'pedido'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_garantia_pedido_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_garantia_pedido_guardar');
                });

                $router->group(['prefix' => 'envio'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_garantia_envio_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_garantia_envio_guardar');
                });

                $router->group(['prefix' => 'historial'], function () use ($router) {
                    $router->post('data', 'SoporteController@soporte_garantia_devolucion_garantia_historial_data');
                    $router->get('documento/{documento}', 'SoporteController@soporte_garantia_devolucion_garantia_historial_documento');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_garantia_historial_guardar');
                });
            });

            $router->group(['prefix' => 'servicio'], function () use ($router) {
                $router->get('data', 'SoporteController@soporte_garantia_devolucion_servicio_data');
                $router->post('crear', 'SoporteController@soporte_garantia_devolucion_servicio_crear');

                $router->group(['prefix' => 'revision'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_servicio_revision_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_servicio_revision_guardar');
                });

                $router->group(['prefix' => 'envio'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_servicio_envio_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_servicio_envio_guardar');
                });

                $router->group(['prefix' => 'historial'], function () use ($router) {
                    $router->get('data/{fecha_inicial}/{fecha_final}', 'SoporteController@soporte_garantia_devolucion_servicio_historial_data');
                    $router->get('documento/{documento}', 'SoporteController@soporte_garantia_devolucion_servicio_historial_documento');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_servicio_historial_guardar');
                });

                $router->group(['prefix' => 'cotizacion'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_servicio_cotizacion_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_servicio_cotizacion_guardar');
                    $router->post('crear', 'SoporteController@soporte_garantia_devolucion_servicio_cotizacion_crear');
                });

                $router->group(['prefix' => 'reparacion'], function () use ($router) {
                    $router->get('data', 'SoporteController@soporte_garantia_devolucion_servicio_reparacion_data');
                    $router->post('guardar', 'SoporteController@soporte_garantia_devolucion_servicio_reparacion_guardar');
                });
            });
        });
    });

    $router->group(['prefix' => 'ensamble'], function () use ($router) {
        // Búsqueda de productos
        $router->get('producto/kit/{sku}',        ['uses' => 'EnsambleController@getProductoKitBySku']);
        $router->get('producto/componente/{sku}', ['uses' => 'EnsambleController@getProductoComponenteBySku']);

        // Series
        $router->get('series/{id_modelo}',  ['uses' => 'EnsambleController@getSeriesPorModelo']);
        $router->post('serie/validar',      ['uses' => 'EnsambleController@validarSerieComponente']);

        // Crear ensamble
        $router->post('crear', ['uses' => 'EnsambleController@crear']);
    });

    # Menú almacén
    $router->group(['prefix' => 'almacen'], function () use ($router) {
        $router->group(['prefix' => 'picking'], function () use ($router) {
            $router->get('data', 'AlmacenController@almacen_picking_data');
            $router->get('venta/{documento}', 'AlmacenController@almacen_picking_venta');
        });

        $router->group(['prefix' => 'packing'], function () use ($router) {
            $router->get('data', 'AlmacenController@almacen_packing_data');
            $router->get('empresa-almacen/{usuario}', 'AlmacenController@almacen_packing_empresa_almacen');
            $router->post('confirmar', 'AlmacenController@almacen_packing_confirmar');
            $router->post('guardar', 'AlmacenController@almacen_packing_guardar');
            $router->post('guardar-v2', 'AlmacenController@almacen_packing_guardar_v2');
            $router->get('documento/{documento}/{usuario}', 'AlmacenController@almacen_packing_documento');
            $router->post('guia', 'AlmacenController@almacen_packing_guardar_guia');

            $router->group(['prefix' => 'v2'], function () use ($router) {
                $router->get('data', 'AlmacenController@almacen_packing_v2_data');
                $router->post('reimprimir', 'AlmacenController@almacen_packing_v2_reimprimir');
            });
        });

        $router->group(['prefix' => 'movimiento'], function () use ($router) {
            $router->group(['prefix' => 'crear'], function () use ($router) {
                $router->get('data', 'AlmacenController@almacen_movimiento_crear_data');
                $router->get('producto/{producto}', 'AlmacenController@almacen_movimiento_crear_producto');
                $router->post('crear', 'AlmacenController@almacen_movimiento_crear_crear');
                $router->post('confirmar', 'AlmacenController@almacen_movimiento_crear_confirmar');
            });

            $router->get('data/producto/{producto}', 'AlmacenController@almacen_movimiento_data_producto');

            $router->group(['prefix' => 'historial'], function () use ($router) {
                $router->get('', 'AlmacenController@almacen_movimiento_historial');
                $router->post('data', 'AlmacenController@almacen_movimiento_historial_data');
                $router->post('afectar', 'AlmacenController@almacen_movimiento_historial_afectar');
                $router->post('interno', 'AlmacenController@almacen_movimiento_historial_interno');
            });

            $router->get('documento/{documento}', 'AlmacenController@almacen_movimiento_documento');
        });

        $router->group(['prefix' => 'pretransferencia'], function () use ($router) {
            $router->group(['prefix' => 'solicitud'], function () use ($router) {
                $router->get('data', 'AlmacenController@almacen_pretransferencia_solicitud_get_data');
                $router->post('crear', 'AlmacenController@almacen_pretransferencia_solicitud_crear');
                $router->post('publicacion/productos', 'AlmacenController@almacen_pretransferencia_solicitud_get_publicacion_productos');
                $router->get('publicacion/{marketplace}/{publicacion}', 'AlmacenController@almacen_pretransferencia_solicitud_get_publicacion');
            });

            $router->group(['prefix' => 'pendiente'], function () use ($router) {
                $router->post('guardar', 'AlmacenController@almacen_pretransferencia_pendiente_guardar');
                $router->post('eliminar', 'AlmacenController@almacen_pretransferencia_pendiente_eliminar');
            });

            $router->group(['prefix' => 'confirmacion'], function () use ($router) {
                $router->get('data', 'AlmacenController@almacen_pretransferencia_confirmacion_data');
                $router->post('guardar', 'AlmacenController@almacen_pretransferencia_confirmacion_guardar');
            });

            $router->group(['prefix' => 'autorizacion'], function () use ($router) {
                $router->get('data', 'AlmacenController@almacen_pretransferencia_autorizacion_data');
                $router->post('guardar', 'AlmacenController@almacen_pretransferencia_autorizacion_guardar');
            });

            $router->group(['prefix' => 'envio'], function () use ($router) {
                $router->get('data', 'AlmacenController@almacen_pretransferencia_envio_data');
                $router->post('guardar', 'AlmacenController@almacen_pretransferencia_envio_guardar');
                $router->get('etiqueta/{documento}/{publicacion}/{etiqueta}', 'AlmacenController@almacen_pretransferencia_envio_etiqueta');
            });

            $router->group(['prefix' => 'finalizar'], function () use ($router) {
                $router->post('guardar', 'AlmacenController@almacen_pretransferencia_finalizar_guardar');
            });

            $router->group(['prefix' => 'con-diferencias'], function () use ($router) {
                $router->post('guardar', 'AlmacenController@almacen_pretransferencia_con_diferencias_guardar');
            });

            $router->group(['prefix' => 'historial'], function () use ($router) {
                $router->post('data', 'AlmacenController@almacen_pretransferencia_historial_data');
                $router->get('factura/{documento}', 'AlmacenController@almacen_pretransferencia_historial_factura');
                $router->get('nc/{documento}', 'AlmacenController@almacen_pretransferencia_historial_nc');
                $router->post('guardar', 'AlmacenController@almacen_pretransferencia_historial_guardar');
            });

            /* Common routes */
            $router->get('documentos/{fase}', 'AlmacenController@almacen_pretransferencia_get_documentos');
        });

        $router->group(['prefix' => 'etiqueta'], function () use ($router) {
            $router->post('', 'AlmacenController@almacen_etiqueta');
            $router->get('data', 'AlmacenController@almacen_etiqueta_get_data');
            $router->post('raw', 'AlmacenController@almacen_etiqueta_raw');
            $router->post('serie-qr', 'AlmacenController@almacen_etiqueta_serie_qr');
        });
    });

    # Menú PDA
    $router->group(['prefix' => 'pda'], function () use ($router) {
        $router->group(['prefix' => 'recepcion'], function () use ($router) {
            $router->get('data', 'PDAController@pda_recepcion_data');
        });
        $router->group(['prefix' => 'picking'], function () use ($router) {
            $router->get('data', 'PDAController@pda_picking_data');
        });
        $router->group(['prefix' => 'inventario'], function () use ($router) {
            $router->get('data', 'PDAController@pda_inventario_data');
        });
    });

    $router->group(['prefix' => 'dropbox'], function () use ($router) {
        $router->post('get-link', 'DropboxController@getTemporaryLink');  // POST /dropbox/get-link
        $router->post('download', 'DropboxController@downloadFile');      // POST /dropbox/download
        $router->post('delete', 'DropboxController@deleteFile');        // POST /dropbox/delete
        $router->post('upload', 'DropboxController@uploadFile');        // POST /dropbox/upload
    });

    #Catalogos
    $router->group(['prefix' => 'catalogo'], function () use ($router) {
        $router->get('buscar/cp/{cp}', 'CatalogoController@buscar_CP');
        $router->post('busqueda/producto', 'CatalogoController@buscar_producto');
    });

    # Menú logistica
    $router->group(['prefix' => 'logistica'], function () use ($router) {
        $router->group(['prefix' => 'envio'], function () use ($router) {
            $router->group(['prefix' => 'pendiente'], function () use ($router) {
                $router->get('data', 'LogisticaController@logistica_envio_pendiente_data');
                $router->get('guia/{guia}', 'LogisticaController@logistica_envio_pendiente_guia');
                $router->get('documento/{documento}/{marketplace}/{zpl}', 'LogisticaController@logistica_envio_pendiente_documento');
                $router->get('paqueteria/{documento}/{paqueteria}', 'LogisticaController@logistica_envio_pendiente_paqueteria');
                $router->get('regresar/{documento}', 'LogisticaController@logistica_envio_pendiente_regresar');

                $router->post('guardar', 'LogisticaController@logistica_envio_pendiente_guardar');
            });

            $router->group(['prefix' => 'firma'], function () use ($router) {
                $router->get('detalle/{documento}', 'LogisticaController@logistica_envio_firma_detalle');
                $router->post('guardar', 'LogisticaController@logistica_envio_firma_guardar');
            });
        });

        $router->group(['prefix' => 'manifiesto'], function () use ($router) {
            $router->group(['prefix' => 'manifiesto'], function () use ($router) {
                $router->get('data', 'LogisticaController@logistica_manifiesto_manifiesto_data');
                $router->post('agregar', 'LogisticaController@logistica_manifiesto_manifiesto_agregar');
                $router->post('eliminar', 'LogisticaController@logistica_manifiesto_manifiesto_eliminar');
            });

            $router->group(['prefix' => 'manifiesto-salida'], function () use ($router) {
                $router->get('data', 'LogisticaController@logistica_manifiesto_manifiesto_salida_data');
                $router->post('agregar', 'LogisticaController@logistica_manifiesto_manifiesto_salida_agregar');
                $router->post('imprimir', 'LogisticaController@logistica_manifiesto_manifiesto_salida_imprimir');
                $router->post('generar', 'LogisticaController@logistica_manifiesto_manifiesto_salida_generar');
            });
        });

        $router->group(['prefix' => 'control-paqueteria'], function () use ($router) {
            $router->group(['prefix' => 'crear'], function () use ($router) {
                $router->post('', 'LogisticaController@logistica_control_paqueteria_crear');
                $router->get('data', 'LogisticaController@logistica_control_paqueteria_crear_data');
            });

            $router->group(['prefix' => 'historial'], function () use ($router) {
                $router->get('data/{fecha_inicial}/{fecha_final}', 'LogisticaController@logistica_control_paqueteria_historial_data');
            });
        });

        $router->group(['prefix' => 'guia'], function () use ($router) {
            $router->group(['prefix' => 'crear'], function () use ($router) {
                $router->get('data', 'LogisticaController@logistica_guia_crear_data');
                $router->get('data/{documento}', 'LogisticaController@logistica_guia_crear_data_documento');
                $router->post('', 'LogisticaController@logistica_guia_crear');
                $router->post('cotizar', 'LogisticaController@logistica_guia_crear_cotizar');
            });
        });

        $router->group(['prefix' => 'seguro'], function () use ($router) {
            $router->get('data', 'LogisticaController@logistica_seguro_data');
            $router->get('documento', 'LogisticaController@logistica_seguro_documento');
            $router->post('cambiar/{guia}/{total}', 'LogisticaController@logistica_seguro_guia');
        });
    });

    # Menú contabilidad
    $router->group(['prefix' => 'contabilidad'], function () use ($router) {
        $router->post('cliente/buscar', 'ContabilidadController@cliente_buscar');
        $router->post('proveedor/buscar', 'ContabilidadController@proveedor_buscar');

        $router->group(['prefix' => 'facturas'], function () use ($router) {
            $router->group(['prefix' => 'pendiente'], function () use ($router) {
                $router->get('data', 'ContabilidadController@contabilidad_facturas_pendiente_data');
                $router->post('guardar', 'ContabilidadController@contabilidad_facturas_pendiente_guardar');
            });

            $router->group(['prefix' => 'saldar'], function () use ($router) {
                $router->get('data/entidad/{documento}', 'ContabilidadController@contabilidad_facturas_saldar_data');
                $router->post('guardar', 'ContabilidadController@contabilidad_facturas_saldar_guardar');
            });

            $router->group(['prefix' => 'dessaldar'], function () use ($router) {
                $router->post('data', 'ContabilidadController@contabilidad_facturas_dessaldar_buscar');
                $router->get('documento/{id_documento}', 'ContabilidadController@contabilidad_facturas_dessaldar_buscar_documento');
                $router->post('guardar', 'ContabilidadController@contabilidad_facturas_dessaldar_guardar');
                $router->post('guardar-movimientos', 'ContabilidadController@contabilidad_facturas_dessaldar_guardar_movimientos');
            });
        });

        $router->group(['prefix' => 'compras-gastos'], function () use ($router) {
            $router->group(['prefix' => 'gasto'], function () use ($router) {
                $router->post('proveedor/buscar', 'ContabilidadController@proveedor_buscar');
                $router->get('data', 'ContabilidadController@compra_gasto_data');
                $router->post('crear', 'ContabilidadController@compras_gasto_crear');
            });
            $router->group(['prefix' => 'compras'], function () use ($router) {
                $router->get('data/entidad/{entidad}', 'ContabilidadController@contabilidad_compras_saldar_data');
                $router->post('aplicar', 'ContabilidadController@compras_aplicar_egreso');
            });
        });

        $router->group(['prefix' => 'estado'], function () use ($router) {
            $router->group(['prefix' => 'factura'], function () use ($router) {
                $router->post('reporte', 'ContabilidadController@contabilidad_estado_factura_reporte');
            });

            $router->group(['prefix' => 'ingreso'], function () use ($router) {
                $router->post('reporte', 'ContabilidadController@contabilidad_estado_ingreso_reporte');
            });
        });

        $router->group(['prefix' => 'ingreso'], function () use ($router) {
            $router->group(['prefix' => 'generar'], function () use ($router) {
                $router->get('data', 'ContabilidadController@contabilidad_ingreso_generar_data');
                $router->post('crear', 'ContabilidadController@contabilidad_ingreso_crear');
            });

            $router->group(['prefix' => 'editar'], function () use ($router) {
                $router->post('cliente', 'ContabilidadController@contabilidad_ingreso_editar_cliente');
            });

            $router->group(['prefix' => 'historial'], function () use ($router) {
                $router->get('data', 'ContabilidadController@contabilidad_ingreso_historial_data');
                $router->post('buscar', 'ContabilidadController@contabilidad_historial_filtrado');
            });

            $router->group(['prefix' => 'eliminar'], function () use ($router) {
                $router->post('data', 'ContabilidadController@contabilidad_ingreso_eliminar_data');
                $router->delete('eliminar/{id}', 'ContabilidadController@contabilidad_ingreso_eliminar_eliminar');
            });
        });

        $router->group(['prefix' => 'globalizar'], function () use ($router) {
            $router->post('globalizar', 'ContabilidadController@contabilidad_globalizar_globalizar');
            $router->post('desglobalizar', 'ContabilidadController@contabilidad_globalizar_desglobalizar');
        });

        $router->group(['prefix' => 'tesoreria'], function () use ($router) {
            $router->get('data', 'ContabilidadController@contabilidad_tesoreria_data');
            $router->post('bancos/buscar', 'ContabilidadController@contabilidad_tesoreria_buscar_banco');

            $router->post('cuenta/crear', 'ContabilidadController@contabilidad_tesoreria_cuenta_crear');
            $router->post('cuenta/editar', 'ContabilidadController@contabilidad_tesoreria_cuenta_editar');
            $router->get('cuentas-bancarias', 'ContabilidadController@contabilidad_tesoreria_cuentas_bancarias');
            $router->delete('cuenta/eliminar/{id}', 'ContabilidadController@contabilidad_tesoreria_cuenta_eliminar');

            $router->get('cajas-chicas', 'ContabilidadController@contabilidad_tesoreria_cajas_chicas');
            $router->post('caja-chica/crear', 'ContabilidadController@contabilidad_tesoreria_caja_chica_crear');
            $router->post('caja-chica/editar', 'ContabilidadController@contabilidad_tesoreria_caja_chica_editar');
            $router->delete('caja-chica/eliminar/{id}', 'ContabilidadController@contabilidad_tesoreria_caja_chica_eliminar');

            $router->get('acreedores', 'ContabilidadController@contabilidad_tesoreria_acreedores');
            $router->post('acreedor/crear', 'ContabilidadController@contabilidad_tesoreria_acreedor_crear');
            $router->post('acreedor/editar', 'ContabilidadController@contabilidad_tesoreria_acreedor_editar');
            $router->delete('acreedor/eliminar/{id}', 'ContabilidadController@contabilidad_tesoreria_acreedor_eliminar');

            $router->get('deudores', 'ContabilidadController@contabilidad_tesoreria_deudores');
            $router->post('deudor/crear', 'ContabilidadController@contabilidad_tesoreria_deudor_crear');
            $router->post('deudor/editar', 'ContabilidadController@contabilidad_tesoreria_deudor_editar');
            $router->delete('deudor/eliminar/{id}', 'ContabilidadController@contabilidad_tesoreria_deudor_eliminar');

            $router->get('bancos', 'ContabilidadController@contabilidad_tesoreria_bancos');
            $router->post('banco/crear', 'ContabilidadController@contabilidad_tesoreria_banco_crear');
            $router->post('banco/editar', 'ContabilidadController@contabilidad_tesoreria_banco_editar');
            $router->delete('banco/eliminar/{id}', 'ContabilidadController@contabilidad_tesoreria_banco_eliminar');
        });
    });

    # Menú configuracion
    $router->group(['prefix' => 'configuracion'], function () use ($router) {
        $router->group(['prefix' => 'usuario'], function () use ($router) {
            $router->group(['prefix' => 'gestion'], function () use ($router) {
                $router->get('data', 'ConfiguracionController@configuracion_usuario_gestion_data');
                $router->get('desactivar/{usuario}', 'ConfiguracionController@configuracion_usuario_gestion_desactivar');
                $router->post('registrar', 'ConfiguracionController@configuracion_usuario_gestion_registrar');
            });

            $router->group(['prefix' => 'configuracion'], function () use ($router) {
                $router->get('data', 'ConfiguracionController@configuracion_usuario_configuarcion_data');
                $router->post('area', 'ConfiguracionController@configuracion_usuario_configuracion_area');
                $router->post('nivel', 'ConfiguracionController@configuracion_usuario_configuracion_nivel');
                $router->post('subnivel', 'ConfiguracionController@configuracion_usuario_configuracion_subnivel');
            });
        });

        $router->group(['prefix' => 'sistema'], function () use ($router) {
            $router->group(['prefix' => 'marketplace'], function () use ($router) {
                $router->get('data', 'ConfiguracionController@configuracion_sistema_marketplace_data');
                $router->post('ver-credenciales', 'ConfiguracionController@configuracion_sistema_marketplace_ver_credenciales');
                $router->post('guardar', 'ConfiguracionController@configuracion_sistema_marketplace_guardar');
            });

            $router->group(['prefix' => 'impresora'], function () use ($router) {
                $router->post('/create', 'ConfiguracionController@configuracion_sistema_impresora_create');
                $router->get('', 'ConfiguracionController@configuracion_sistema_impresora_retrive');
                $router->post('/update', 'ConfiguracionController@configuracion_sistema_impresora_update');
                $router->delete('/{impresora_id}', 'ConfiguracionController@configuracion_sistema_impresora_delete');
            });
        });
        # Almacenes
        $router->get('almacen', 'ConfiguracionController@getAlmacenes');
        $router->post('almacen/guardar', 'ConfiguracionController@guardar_almacen');
        $router->post('almacen/eliminar', 'ConfiguracionController@eliminar_almacen');

        # Paqueterias
        $router->get('paqueteria', 'ConfiguracionController@paqueteria');
        $router->post('paqueteria/guardar', 'ConfiguracionController@guardar_paqueteria');

        $router->post('logout', 'ConfiguracionController@configuracion_logout');
    });

    # Menú del usuario
    $router->group(['prefix' => 'usuario'], function () use ($router) {
        $router->post('actualizar', 'AuthController@usuario_actualizar');
        $router->get('notificacion/{offset}', 'AuthController@usuario_notificacion');
    });

    $router->group(['prefix' => 'rawinfo'], function () use ($router) {
        $router->group(['prefix' => 'mercadolibre'], function () use ($router) {
            $router->group(['prefix' => 'pseudonimo'], function () use ($router) {
                $router->group(['middleware' => 'firewall'], function () use ($router) {
                    $router->get('{pseudonimo}', 'MercadolibreController@rawinfo_pseudonimo');
                });

                $router->get('{pseudonimo}/venta/{venta}', 'MercadolibreController@rawinfo');
                $router->get('{pseudonimo}/importar', 'MercadolibreController@rawinfo_importar');
                $router->get('{pseudonimo}/importar/publicacion/{publicacion}', 'MercadolibreController@rawinfo_importar_publicacion');
                $router->get('{pseudonimo}/importar/publicaciones-ful', 'MercadolibreController@rawinfo_importar_publicaciones_fulfillment');
                $router->get('{pseudonimo}/ventas', 'MercadolibreController@rawinfo_ventas');
                $router->post('{pseudonimo}/notificacion', 'MercadolibreController@rawinfo_notificacion');
            });

            $router->post('importar-publicaciones-fecha', 'MercadolibreController@rawinfo_importar_publicaciones_fecha');
            $router->get('factura', 'MercadolibreController@rawinfo_mercadolibre_factura');
            $router->get('huawei', 'MercadolibreController@rawinfo_mercadolibre_huawei');

            $router->group(['prefix' => 'whatsapp'], function () use ($router) {
                $router->post('recibe', 'MercadolibreController@rawinfo_whatsapp_recibe');
                $router->post('callback', 'MercadolibreController@rawinfo_whatsapp_callback');
                $router->get('publicacion/{publicacion}', 'MercadolibreController@rawinfo_whatsapp_publicacion');
            });
        });

        $router->group(['prefix' => 'amazon'], function () use ($router) {
            $router->group(['prefix' => 'appid'], function () use ($router) {
                $router->group(['prefix' => '{appid}'], function () use ($router) {
                    $router->get('importar', 'RawInfoController@rawinfo_amazon_appid_importar');
                    $router->get('venta/{venta}', 'RawInfoController@rawinfo_amazon_appid_venta');
                });
            });
        });

        $router->group(['prefix' => 'claroshop'], function () use ($router) {
            $router->group(['prefix' => 'appid'], function () use ($router) {
                $router->group(['prefix' => '{appid}'], function () use ($router) {
                    $router->get('importar', 'RawInfoController@rawinfo_amazon_appid_importar');
                    $router->get('venta/{venta}', 'RawInfoController@rawinfo_amazon_appid_venta');
                });
            });
        });

        $router->get('productos', 'GeneralController@rawinfo_productos');

        $router->group(['prefix' => 'elektra'], function () use ($router) {
            $router->get('fase/{venta}', 'RawInfoController@rawinfo_elektra_fase');
        });

        $router->group(['prefix' => 'compra'], function () use ($router) {
            $router->get('uuid', 'CompraController@rawinfo_compra_uuid');
            $router->get('huawei', 'CompraController@rawinfo_compra_huawei');
        });

        $router->group(['prefix' => 'logistica'], function () use ($router) {
            $router->group(['prefix' => 'envia'], function () use ($router) {
                $router->get('cotizar', 'LogisticaController@rawinfo_logistica_envia_cotizar');
            });
        });

        $router->group(['prefix' => 'almacen'], function () use ($router) {
            $router->get('picking', 'AlmacenController@rawinfo_almacen_picking');
        });

        $router->group(['prefix' => 'ws'], function () use ($router) {
            $router->group(['prefix' => 'exel'], function () use ($router) {
                $router->get('producto', 'RawInfoController@rawinfo_ws_exel_producto');
                $router->get('existencia', 'RawInfoController@rawinfo_ws_exel_existencia');
                $router->get('guia/{documento}', 'RawInfoController@rawinfo_ws_exel_guia_documento');
            });

            $router->group(['prefix' => 'ct'], function () use ($router) {
                $router->get('producto', 'RawInfoController@rawinfo_ws_ct_producto');
                $router->get('almacen', 'RawInfoController@rawinfo_ws_ct_almacen');
                $router->get('pedido-prueba/{documento}', 'RawInfoController@rawinfo_ws_ct_pedido_prueba');
                $router->get('adjuntar-guia-pedidos', 'RawInfoController@rawinfo_ws_ct_adjuntar_guia_pedidos');
            });

            $router->group(['prefix' => 'arroba'], function () use ($router) {
                $router->get('producto', 'RawInfoController@rawinfo_ws_arroba_producto');
            });
        });

        $router->get('importar-productos', 'RawInfoController@rawinfo_importar_productos');

        $router->get('str_random_32', function () {
            return response()->json([str_random(32)]);
        });
        $router->get('str_random_50', function () {
            return response()->json([str_random(50)]);
        });
    });

    $router->group(['prefix' => 'whatsapp'], function () use ($router) {
        $router->get('sendWhatsApp', 'WhatsAppController@whatsapp_send');
        $router->get('validateWhatsApp/{code}', 'WhatsAppController@whatsapp_validate');
        $router->post('sendWhatsAppWithOption', 'WhatsAppController@whatsapp_send_with_option');
        $router->post('validateWhatsAppWithOption', 'WhatsAppController@whatsapp_validate_with_option');
    });

    $router->group(['prefix' => 'developer'], function () use ($router) {
        $router->post('recalculaCosto', 'DeveloperController@recalcularCosto');
        $router->post('aplicarCosto', 'DeveloperController@aplicarCosto');
        $router->post('recalcularInventario', 'DeveloperController@getInventarioPorAlmacen');
        $router->post('getDocumentosPendientes',  'DeveloperController@getDocumentosPendientes');
        $router->post('aplicarPendientes',  'DeveloperController@aplicarPendientes');
        $router->post('afectarInventario', 'DeveloperController@afectarInventario');
    });

    $router->group(['prefix' => 'print'], function () use ($router) {

        $router->get('/impresoras', 'PrintController@impresoras');


        $router->group(['prefix' => 'etiquetas'], function () use ($router) {
            $router->get('/data', 'PrintController@data');

            $router->post('/', 'PrintController@etiquetas');
            $router->post('/serie', 'PrintController@serie');
            $router->post('/busqueda', 'PrintController@busqueda');
        });

        $router->group(['prefix' => 'tickets'], function () use ($router) {
            $router->get('/', 'PrintController@tickets');
        });

        $router->group(['prefix' => 'guias'], function () use ($router) {
            $router->get('/print/{documentoId}/{impresoraNombre}', 'PrintController@guias');
        });
    });
});

$router->post('rawinfo/mercadolibre/notificaciones/{marketplace_id}', 'NotificacionesController@notificacion');
$router->get('pruebaPicking', 'AlmacenController@rawinfo_almacen_picking');
$router->post('mercadolibre/notificaciones/callbacks', 'MercadolibreControllerV2@mercadolibre_notificaciones_callbacks');

$router->group(['prefix' => 'developer'], function () use ($router) {
    $router->post('busquedaSerieVsSku', 'AlmacenController@almacen_busqueda_serie_vs_sku');
    $router->post('serieVsAlmacen', 'AlmacenController@almacen_busqueda_serie_vs_almacen');
    $router->get('test', 'DeveloperController@test');
    $router->get('recalcularInventario', 'DeveloperController@recalcularInventario');
});

$router->get('getToken', 'DropboxController@getDropboxToken');
$router->get('cron/actualizarToken', 'DropboxController@actualizarTokenDropbox');
