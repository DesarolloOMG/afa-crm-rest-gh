package com.afainnova.crm.data

import com.google.gson.*
import java.math.BigDecimal

class CrmRepository(val transport: Transport, val sessions: Sessions) {
    suspend fun get(path: String): JsonElement = transport.execute(Call(path))
    suspend fun post(path: String, data: JsonObject, encoding: Encoding = Encoding.DATA): JsonElement = transport.execute(Call(path,"POST",data,encoding))
    suspend fun login(email: String, password: String, code: String): JsonObject {
        val data=obj("email" to email.trim(),"password" to password)
        if(code.isNotBlank())data.put("totp_code",code)
        val result=transport.execute(Call("auth/login","POST",data,authenticated=false)).objectOrEmpty()
        if(result.s("token").isNotBlank())sessions.save(result.s("token"))
        return result
    }
    suspend fun reset(email: String, code: String): JsonObject = transport.execute(Call("auth/reset","POST",obj("email" to email,"totp_code" to code),authenticated=false)).objectOrEmpty()
    suspend fun catalog(section: Section): JsonObject = when(section) {
        Section.PRODUCTS -> get("compra/producto/gestion/data").objectOrEmpty()
        Section.CUSTOMERS,Section.SUPPLIERS -> get("compra/proveedor/data").objectOrEmpty()
        Section.USERS -> get("configuracion/usuario/gestion/data").objectOrEmpty()
        Section.MARKETPLACES -> get("configuracion/sistema/marketplace/data").objectOrEmpty()
        Section.WAREHOUSES -> get("configuracion/almacen").objectOrEmpty()
        Section.SALES -> get("venta/venta/crear/data").objectOrEmpty()
        Section.STOCK -> get("general/busqueda/producto/data").objectOrEmpty()
        else -> obj()
    }
    suspend fun search(section: Section, filters: JsonObject, catalog: JsonObject): List<JsonObject> = when(section) {
        Section.DOCUMENTS -> {
            val r=post("general/busqueda/venta/informacion",obj("campo" to filters.s("campo").ifBlank { "id" },"criterio" to filters.s("criterio"))).objectOrEmpty()
            r.list("ventas").map { it.changed("_kind","sale") } + r.list("notas_credito").map { it.changed("_kind","credit") }
        }
        Section.PRODUCTS -> post("compra/producto/gestion/producto",filters).objectOrEmpty().list("productos")
        Section.CUSTOMERS -> get("compra/cliente/data/${segment(filters.s("criterio"))}/${segment(filters.s("empresa").ifBlank{"1"})}").objectOrEmpty().list("data")
        Section.SUPPLIERS -> get("compra/proveedor/data/${segment(filters.s("criterio"))}").objectOrEmpty().list("data")
        Section.USERS -> catalog.list("usuarios").filter { filters.s("criterio").isBlank() || (it.s("nombre")+it.s("email")).contains(filters.s("criterio"),true) }
        Section.MARKETPLACES -> catalog.list("marketplaces").filter { filters.s("criterio").isBlank() || it.toString().contains(filters.s("criterio"),true) }
        Section.WAREHOUSES -> catalog.list("data").filter { it.s("almacen").contains(filters.s("criterio"),true) }
        Section.TICKETS -> {
            val state=filters.s("estado")
            if(state=="resolucion") tickets("asignado")+tickets("en_revision") else tickets(state)
        }
        Section.SALES -> get("venta/venta/pedido/pendiente/data").objectOrEmpty().list("ventas").filter { it.toString().contains(filters.s("criterio"),true) && (filters.s("fulfillment").isBlank() || it.flag("fulfillment")==filters.flag("fulfillment")) }
        Section.SERIES -> get("general/busqueda/serie/${segment(filters.s("criterio"))}").objectOrEmpty().list("empresas").reversed()
        Section.STOCK -> {
            require(filters.s("criterio").isNotBlank() || filters.n("almacen")>0) { "Escribe un producto o selecciona un almacén." }
            val response=post("general/busqueda/producto/existencia",filters.changed("etiquetas",filters.s("etiquetas").split(',').map(String::trim).filter(String::isNotBlank))).objectOrEmpty()
            val products=response["productos"]
            if(products?.isJsonArray==true)products.objects() else products.objectOrEmpty().entrySet().map { (sku,value) ->
                if(value.isJsonObject)value.asJsonObject.changed("_sku",sku) else obj("codigo" to sku,"existencias" to value)
            }
        }
        Section.BILLING -> emptyList()
    }
    suspend fun tickets(state: String): List<JsonObject> = post("ticket/informacion-por-estado",obj("data" to state),Encoding.FIELDS).objects()
    suspend fun save(section: Section, data: JsonObject): JsonElement = when(section) {
        Section.TICKETS -> post("ticket/crear",data).also { require(!it.objectOrEmpty().s("message").contains("limite",true)) { it.objectOrEmpty().s("message") } }
        Section.PRODUCTS -> post("compra/producto/gestion/crear",obj("data" to data,"empresa" to data.s("empresa").ifBlank{"1"}),Encoding.FIELDS)
        Section.CUSTOMERS,Section.SUPPLIERS -> post("compra/${if(section==Section.CUSTOMERS)"cliente" else "proveedor"}/guardar",data)
        Section.USERS -> post("configuracion/usuario/gestion/registrar",obj("data" to data,"area" to gson.toJson(data.s("area")),"uea" to data["empresa_almacen"].arrayOrEmpty()),Encoding.FIELDS)
        Section.MARKETPLACES -> post("configuracion/sistema/marketplace/guardar",data)
        Section.WAREHOUSES -> post("configuracion/almacen/guardar",obj("almacen" to data.s("almacen")),Encoding.JSON)
        else -> error("Esta sección utiliza su propio flujo de guardado")
    }
    suspend fun document(id: String): JsonObject = post("general/busqueda/venta/informacion",obj("campo" to "id","criterio" to id)).objectOrEmpty().list("ventas").firstOrNull() ?: error("No se encontró el documento.")
    suspend fun credit(id: String): JsonObject = post("general/busqueda/venta/nota/informacion",obj("documento" to id)).objectOrEmpty().o("nota")
    suspend fun fileLink(path: String): String = post("dropbox/get-link",obj("path" to path),Encoding.JSON).objectOrEmpty().s("link")
    suspend fun billing(filters: JsonObject): JsonObject = get("venta/venta/facturacion/pendientes?fulfillment=${filters.n("fulfillment")}&document_type=${filters.n("document_type")}&page=${filters.n("page").coerceAtLeast(1)}&per_page=25&search=${segment(filters.s("criterio"))}").objectOrEmpty().o("data")
    suspend fun billingPreview(id: String): JsonObject = get("venta/venta/facturacion/previsualizar/${segment(id)}").objectOrEmpty().o("data")
    suspend fun fiscalPreview(id: String, stamped: Boolean): JsonObject = get("general/busqueda/venta/${if(stamped)"refacturacion" else "cliente-fiscal"}/${segment(id)}").objectOrEmpty().o("data")
    suspend fun loadSale(id: String): JsonObject = saleFromDocument(get("venta/venta/editar/documento/${segment(id)}").objectOrEmpty().o("informacion").changed("_documentId",id))
    suspend fun saveSale(data: JsonObject): JsonElement {
        val payload=data.deepCopy(); val doc=payload.o("documento")
        val lines=doc.list("productos")
        require(lines.isNotEmpty()) { "Agrega al menos un producto." }
        require(payload.s("cliente.rfc").isNotBlank()) { "Selecciona un cliente." }
        require(doc.s("almacen").isNotBlank() && doc.s("marketplace").isNotBlank()) { "Selecciona almacén y marketplace." }
        lines.forEach { require(it.money("cantidad")>BigDecimal.ZERO && it.money("precio")>=BigDecimal.ZERO) { "Revisa cantidades y precios." } }
        if(!doc.has("documento") || doc.flag("editar_productos")) {
            lines.filter { it.n("tipo")==1 }.groupBy { it.s("codigo") }.forEach { (sku, grouped) ->
                val quantity=grouped.fold(BigDecimal.ZERO){sum,line->sum+line.money("cantidad")}
                val stock=get("venta/venta/crear/producto/existencia/${segment(sku)}/${segment(doc.s("almacen"))}/${quantity.toPlainString()}").objectOrEmpty()
                require(!stock.has("existencia")||quantity<=stock.money("existencia")){ "Existencia insuficiente para $sku." }
            }
        }
        val total=lines.fold(BigDecimal.ZERO){sum,line->sum+line.money("cantidad")*line.money("precio")}
        val converted=total*doc.money("tipo_cambio")
        require(doc.money("tipo_cambio")>BigDecimal.ZERO) { "El tipo de cambio debe ser mayor a cero." }
        require(doc.money("cobro.importe")<=converted) { "El cobro supera el total de la venta." }
        if(doc.s("periodo")!="1" && !doc.has("documento")) {
            val external=sessions.read()?.profile?.o("subniveles")?.entrySet()?.any { it.value.arrayOrEmpty().any { sub->sub.text()=="12" } }==true
            require(external || converted<=payload.money("cliente.credito_disponible")) { "El crédito disponible del cliente es insuficiente." }
        }
        payload.put("documento.total_user",converted)
        val cost=lines.filter{doc.has("documento")||it.n("tipo")==1}.fold(BigDecimal.ZERO){sum,line->sum+line.money("costo")*line.money("cantidad")*(if(doc.has("documento"))BigDecimal.ONE else BigDecimal("1.16"))}
        payload.put("documento.baja_utilidad",!payload.flag("_promotion")&&cost.divide(BigDecimal("0.95"),8,java.math.RoundingMode.HALF_UP)>=converted)
        payload.entrySet().filter{it.key.startsWith("_")}.map{it.key}.forEach(payload::remove)
        if(payload.s("cliente.rfc") in setOf("XAXX010101000","XEXX010101000"))payload.put("documento.uso_venta",23)
        return post("venta/venta/${if(doc.has("documento"))"editar" else "crear"}",payload)
    }
}

fun defaultFilters(section: Section) = obj("criterio" to "","campo" to "id","empresa" to "1","tipo" to 0,"almacen" to 0,"con_existencia" to 1,"etiquetas" to "","sku" to "","producto" to "","excel" to "","estado" to "","fulfillment" to if(section==Section.BILLING)"0" else "","document_type" to 2,"page" to 1)

fun newRecord(section: Section): JsonObject = when(section) {
    Section.TICKETS -> obj("titulo" to "","descripcion" to "","archivos" to emptyList<Any>())
    Section.CUSTOMERS,Section.SUPPLIERS -> obj("id" to 0,"empresa" to "1","pais" to "MEXICO","regimen" to "","razon_social" to "","rfc" to "","correo" to "","telefono" to "","telefono_alt" to "","condicion" to 1,"limite" to 0,"codigo_postal_fiscal" to "","fiscal" to "","alt" to false)
    Section.PRODUCTS -> obj("id" to 0,"empresa" to "1","sku" to "","descripcion" to "","np" to "","serie" to 1,"refurbished" to 0,"costo" to 0,"extra" to 0,"alto" to 0,"ancho" to 0,"largo" to 0,"peso" to 0,"tipo" to 1,"codigo_text" to "","clave_sat" to "","clave_unidad" to "H87","cat1" to "","cat2" to "","cat3" to "","cat4" to "","proveedores" to emptyList<Any>(),"imagenes" to emptyList<Any>(),"imagenes_anteriores" to emptyList<Any>(),"amazon" to obj("codigo" to "","descripcion" to ""),"precio" to obj("empresa" to "1","precio" to 0,"productos" to emptyList<Any>()),"caducidad" to 0,"tipo_text" to "")
    Section.USERS -> obj("id" to 0,"nombre" to "","email" to "","celular" to "","imagen" to "","area" to "","marketplaces" to emptyList<Any>(),"subniveles" to emptyList<Any>(),"empresas" to emptyList<Any>(),"empresa_almacen" to emptyList<Any>())
    Section.MARKETPLACES -> obj("id" to 0,"publico" to false,"marketplace" to obj("marketplace" to ""),"area" to obj("id" to ""),"serie" to "","serie_nota" to "","empresa" to obj("id" to 0,"id_marketplace_area" to 0,"id_empresa" to 0,"utilidad" to 0),"api" to obj("id" to 0,"id_marketplace_area" to 0,"extra_1" to "","extra_2" to "","app_id" to "","secret" to "","guia" to false))
    Section.WAREHOUSES -> obj("almacen" to "")
    else -> obj()
}

fun newSale(): JsonObject = obj("empresa" to "1","empresa_externa" to "","area" to "","area_text" to "","terminar" to 1,"terminar_producto" to 1,"terminar_producto_sku" to "","desactivar_periodo_metodo" to 0,"productos_venta" to emptyList<Any>(),
    "cliente" to obj("input" to "","select" to "","codigo" to "","razon_social" to "","rfc" to "","telefono" to "","telefono_alt" to "","correo" to "","credito_disponible" to 0,"regimen" to "","codigo_postal_fiscal" to ""),
    "addenda" to obj("orden_compra" to "","solicitud_pago" to "","tipo_documento" to "","factura_asociada" to ""),
    "documento" to obj("almacen" to "","series_factura" to 0,"anticipada" to 0,"fecha_inicio" to java.time.LocalDateTime.now().format(java.time.format.DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")),"proveedor" to "","marketplace" to "","venta" to ".","uso_venta" to "","moneda" to 3,"tipo_cambio" to 1,"referencia" to "","observacion" to "","costo_envio" to 0,"costo_envio_total" to 0,"status_envio" to "","mkt_coupon" to 0,"mkt_fee" to 0,"mkt_created_at" to "","mkt_shipping" to "N/A","info_extra" to "","fulfillment" to 0,"periodo" to "1","paqueteria" to "","seguimiento" to "","total" to 0,"total_user" to 0,"total_paid" to 0,"baja_utilidad" to false,"shipping_null" to 0,"cce" to 0,"productos" to emptyList<Any>(),"archivos" to emptyList<Any>(),
        "cobro" to obj("generar_ingreso" to 1,"metodo_pago" to "","importe" to 0,"entidad_destino" to 1,"destino" to "","referencia" to "","clave_rastreo" to "","numero_aut" to "","cuenta_cliente" to "","fecha_cobro" to java.time.LocalDate.now().toString()),
        "direccion_envio" to obj("contacto" to "","calle" to "","numero" to "","numero_int" to "","colonia" to "","colonia_text" to "","ciudad" to "","estado" to "","id_direccion" to 0,"codigo_postal" to "","referencia" to "","contenido" to "","tipo_envio" to "","remitente_cord_found" to 0,"destino_cord_found" to 0,"remitente_cord" to obj(),"destino_cord" to obj())))

fun saleFromDocument(source: JsonObject): JsonObject {
    require(source.n("_documentId")>0||source.n("id")>0) { "El servidor no devolvió la venta." }
    val data=newSale()
    data.put("empresa",source.s("id_empresa").ifBlank{"1"});data.put("area",source.s("area").ifBlank{source.s("id_area")})
    val map=mapOf("documento" to "id","marketplace" to "id_marketplace_area","almacen" to "id_almacen_principal_empresa","venta" to "no_venta","uso_venta" to "id_cfdi","moneda" to "id_moneda","referencia" to "referencia_documento","costo_envio" to "mkt_shipping_total","costo_envio_total" to "mkt_shipping_total_cost","mkt_shipping" to "mkt_shipping_id","periodo" to "id_periodo","paqueteria" to "id_paqueteria","total" to "mkt_total","total_user" to "mkt_user_total","total_paid" to "mkt_total","refacturacion" to "solicitar_refacturacion")
    map.forEach { (to,from)->data.put("documento.$to",source[from]) }
    data.put("documento.documento",source.s("_documentId").ifBlank{source.s("id")})
    listOf("series_factura","tipo_cambio","observacion","mkt_coupon","mkt_fee","mkt_created_at","info_extra","fulfillment","productos","id_fase","shipping_null","zoom_guia").forEach{ if(source.has(it))data.put("documento.$it",source[it]) }
    data.put("documento.pedido",if(source.n("id_fase")==1)1 else 0)
    data.put("documento.editar_envio",source.n("id_fase")<5);data.put("documento.editar_productos",source.n("id_fase")<4)
    listOf("contacto","calle","numero","numero_int","ciudad","estado","id_direccion","codigo_postal","referencia","contenido","tipo_envio").forEach{ if(source.has(it))data.put("documento.direccion_envio.$it",source[it]) }
    data.put("documento.direccion_envio.colonia",source["id_direccion_pro"]);data.put("documento.direccion_envio.colonia_text",source["colonia"])
    listOf("codigo","razon_social","rfc","telefono","telefono_alt","correo","codigo_postal_fiscal").forEach { data.put("cliente.$it",source[it]) }
    data.put("cliente.regimen",source["regimen_id"]);data.put("cliente.credito_disponible",source["limite"]);data.put("cliente.id",source["id_entidad"])
    data.put("cliente.input",source.s("rfc"))
    data.put("cliente.select",source.s("id_entidad"))
    data.put("_previousAttachments",source["archivos"].arrayOrEmpty())
    data.put("_previousFollowup",source["seguimiento"].arrayOrEmpty())
    return data
}

fun newLine(product: JsonObject): JsonObject = obj("id" to 0,"tipo" to product.n("tipo"),"codigo" to product.label("sku","codigo"),"codigo_text" to product.label("sku","codigo"),"descripcion" to product.s("descripcion"),"cantidad" to 1,"precio" to 0,"costo" to product.money("ultimo_costo"),"garantia" to "","regalo" to 0,"modificacion" to "","comentario" to "","ancho" to product.money("ancho"),"alto" to product.money("alto"),"largo" to product.money("largo"),"peso" to product.money("peso"),"bajo_costo" to 0,"ret" to 0,"addenda" to "")
