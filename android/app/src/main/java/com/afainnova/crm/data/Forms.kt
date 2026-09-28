package com.afainnova.crm.data

import com.google.gson.JsonObject

enum class FieldKind { TEXT, LONG, NUMBER, EMAIL, PHONE, PASSWORD, SELECT, MULTI, BOOL, DATE }
data class Choice(val id: String, val title: String)
data class Field(val key: String,val title: String,val kind: FieldKind=FieldKind.TEXT,val required: Boolean=false,val choices: List<Choice> = emptyList(),val group: String="Información",val enabled: Boolean=true)
fun choices(vararg pairs: Pair<String,String>)=pairs.map { Choice(it.first,it.second) }
fun List<JsonObject>.options(id: String="id",vararg names: String): List<Choice> = map { Choice(it.s(id),it.label(*if(names.isEmpty()) arrayOf("nombre","descripcion","empresa","almacen","marketplace","area","regimen","condicion","periodo","pais","tipo","categoria","colonia","destino","cuenta","uso","metodo_pago","moneda","id") else names)) }
private fun pick(key:String,title:String,data:List<JsonObject>,required:Boolean=true,id:String="id",group:String="Información", vararg names:String)=Field(key,title,FieldKind.SELECT,required,data.options(id,*names),group)
private fun number(key:String,title:String,group:String="Información")=Field(key,title,FieldKind.NUMBER,group=group)
private fun toggle(key:String,title:String,group:String="Información")=Field(key,title,FieldKind.BOOL,group=group)

fun entityFields(section:Section,c:JsonObject,v:JsonObject,unlocked:Boolean=false):List<Field> = when(section) {
    Section.TICKETS -> listOf(Field("titulo","Título",required=true),Field("descripcion","Describe el problema",FieldKind.LONG,true))
    Section.CUSTOMERS,Section.SUPPLIERS -> listOf(
        Field("razon_social","Razón social",required=true),Field("rfc","RFC",required=true),Field("codigo_postal_fiscal","Código postal fiscal",FieldKind.NUMBER,true),
        pick("regimen","Régimen fiscal",c.list("regimenes").filter { it.s("condicion").isBlank() || it.s("condicion").contains(if(v.s("rfc").length<13)"M" else "F") },id=if(section==Section.CUSTOMERS)"codigo" else "id"),
        Field("correo","Correo",FieldKind.EMAIL,true),Field("telefono","Teléfono",FieldKind.PHONE),Field("telefono_alt","Teléfono alternativo",FieldKind.PHONE),
        pick("pais","País",c.list("paises"),id="pais"),pick("condicion","Condición de pago",c.list("condiciones")),
        toggle("alt",if(section==Section.CUSTOMERS)"También es proveedor" else "También es cliente")
    ) + if(section==Section.CUSTOMERS)listOf(number("limite","Límite de crédito")) else emptyList()
    Section.PRODUCTS -> listOf(
        pick("empresa","Empresa",c.list("empresas")),Field("sku","SKU",required=true),Field("descripcion","Descripción",FieldKind.LONG,true),Field("np","Número de parte"),
        pick("tipo","Tipo de producto",c.list("tipos")),toggle("serie","Control por serie"),toggle("refurbished","Reacondicionado"),toggle("caducidad","Control de caducidad"),
        number("costo","Costo"),number("extra","Costo extra"),number("alto","Alto (cm)","Dimensiones"),number("ancho","Ancho (cm)","Dimensiones"),number("largo","Largo (cm)","Dimensiones"),number("peso","Peso (kg)","Dimensiones"),
        Field("clave_sat","Clave SAT",required=true,group="Fiscal"),Field("clave_unidad","Unidad SAT",FieldKind.SELECT,true,choices("H87" to "H87 · Pieza","E48" to "E48 · Unidad de servicio"),"Fiscal"),
        pick("cat1","Tipo de producto",c.list("categorias_uno"),false,id="categoria",group="Categorías"),pick("cat2","Marca",c.list("categorias_dos").filter { it.s("id_categoria_uno").isBlank() || it.s("id_categoria_uno")==v.s("cat1") },false,id="categoria",group="Categorías"),
        pick("cat3","Subtipo",c.list("categorias_tres").filter { it.s("id_categoria_dos").isBlank() || it.s("id_categoria_dos")==v.s("cat2") },false,id="categoria",group="Categorías"),pick("cat4","Vertical",c.list("categorias_cuatro").filter { it.s("id_categoria_tres").isBlank() || it.s("id_categoria_tres")==v.s("cat3") },false,id="categoria",group="Categorías"),
        Field("amazon.codigo","Código Amazon",group="Publicación"),Field("amazon.descripcion","Descripción Amazon",FieldKind.LONG,group="Publicación"),pick("precio.empresa","Empresa del precio",c.list("empresas"),group="Precios"),number("precio.precio","Precio","Precios"))
    Section.USERS -> listOf(Field("nombre","Nombre",required=true),Field("email","Correo",FieldKind.EMAIL,true),Field("celular","Celular",FieldKind.PHONE,true),Field("area","Área",required=true),
        Field("empresas","Empresas",FieldKind.MULTI,true,c.list("empresas").options(),"Permisos"),
        Field("subniveles","Permisos por módulo",FieldKind.MULTI,true,c.list("niveles").flatMap { level->level.list("subniveles").map { Choice(it.s("pivot.id"),level.s("nivel")+" · "+it.s("subnivel")) } },"Permisos"),
        Field("marketplaces","Marketplaces y áreas",FieldKind.MULTI,false,c.list("areas").flatMap { area->area.list("marketplaces").map { Choice(it.s("pivot.id"),area.s("area")+" · "+it.s("marketplace")) } },"Permisos"),
        Field("empresa_almacen","Almacenes autorizados",FieldKind.MULTI,false,c.list("empresa_almacen").flatMap { group->group.list("data").map { Choice(it.s("id"),group.s("nombre")+" · "+it.s("almacen")) } },"Permisos"))
    Section.MARKETPLACES -> listOf(Field("marketplace.marketplace","Nombre del marketplace",required=true),pick("area.id","Área",c.list("areas")),toggle("publico","Marketplace público"),Field("serie","Serie de facturas"),Field("serie_nota","Serie de notas de crédito"),
        pick("empresa.id_empresa","Empresa",c.list("empresas")),number("empresa.utilidad","Utilidad (%)"),toggle("api.guia","El marketplace proporciona la guía"),
        Field("api.extra_1","Configuración adicional 1"),Field("api.extra_2","Configuración adicional 2"),Field("api.app_id","ID de aplicación",group="Credenciales",enabled=unlocked||v.n("api.id")==0),Field("api.secret","Clave secreta",FieldKind.PASSWORD,group="Credenciales",enabled=unlocked||v.n("api.id")==0))
    Section.WAREHOUSES -> listOf(Field("almacen","Nombre del almacén",required=true))
    else -> emptyList()
}

fun normalizeRecord(section:Section,original:JsonObject):JsonObject {
    val v=newRecord(section); original.entrySet().forEach { (k,value)->v.add(k,value.deepCopy()) }
    when(section) {
        Section.MARKETPLACES -> listOf("marketplace","area","empresa","api").forEach { key->
            val defaults=newRecord(section).o(key)
            original.o(key).entrySet().forEach{(k,value)->defaults.add(k,value.deepCopy())}
            v.put(key,defaults)
        }
        Section.USERS -> mapOf("marketplaces" to "id_marketplace_area","subniveles" to "id_subnivel_nivel","empresas" to "id_empresa").forEach { (key,id)->v.put(key,original[key].arrayOrEmpty().map { if(it.isJsonObject)it.asJsonObject.s(id) else it.text() }) }
        Section.PRODUCTS -> { v.put("imagenes_anteriores",original["imagenes_anteriores"].arrayOrEmpty());v.put("imagenes",emptyList<Any>()) }
        Section.CUSTOMERS -> v.put("alt",original.n("tipo") in setOf(1,3))
        Section.SUPPLIERS -> v.put("alt",original.n("tipo") in setOf(2,3))
        else -> Unit
    }
    return v
}

fun validateFields(fields:List<Field>,data:JsonObject):String? {
    fields.filter { it.enabled }.forEach { field ->
        if(field.required && (if(field.kind==FieldKind.MULTI)data.at(field.key).arrayOrEmpty().isEmpty else data.s(field.key).isBlank()))return "Completa ${field.title.lowercase()}."
        if(field.kind==FieldKind.NUMBER && data.s(field.key).isNotBlank() && data.s(field.key).toBigDecimalOrNull()==null)return "${field.title}: escribe un número válido."
        if(field.kind==FieldKind.EMAIL && data.s(field.key).isNotBlank() && !Regex("[^\\s@]+@[^\\s@]+\\.[^\\s@]+").matches(data.s(field.key)))return "Revisa ${field.title.lowercase()}."
    }
    return null
}

fun saleFields(step:Int,c:JsonObject,v:JsonObject):List<Field> {
    val editing=v.n("documento.documento")>0
    val shipping=!editing||v.flag("documento.editar_envio")
    val products=!editing||v.flag("documento.editar_productos")
    val company=c.list("empresas").find{it.s("id")==v.s("empresa")}.objectOrEmpty()
    val marketplaces=c.list("areas").find{it.s("id")==v.s("area")}.objectOrEmpty().list("marketplaces")
    return when(step) {
        0 -> listOf(pick("empresa","Empresa",c.list("empresas")),pick("area","Área",c.list("areas")),pick("documento.marketplace","Marketplace",marketplaces),pick("documento.almacen","Almacén",company.list("almacenes")),Field("documento.venta","Número de venta",required=true),Field("documento.referencia","Referencia"),Field("documento.observacion","Observaciones",FieldKind.LONG),toggle("documento.fulfillment","Fulfillment"),toggle("documento.series_factura","Incluir series en factura"),toggle("documento.anticipada","Factura anticipada"),toggle("documento.cce","Comercio exterior")).map { it.copy(enabled=products||it.key in listOf("documento.referencia","documento.observacion")) }
        1 -> listOf(Field("cliente.razon_social","Razón social",required=true),Field("cliente.rfc","RFC",required=true),Field("cliente.codigo_postal_fiscal","Código postal fiscal",FieldKind.NUMBER,true),pick("cliente.regimen","Régimen fiscal",c.list("regimenes").filter { it.s("condicion").isBlank()||it.s("condicion").contains(if(v.s("cliente.rfc").length<13)"M" else "F") }),Field("cliente.correo","Correo",FieldKind.EMAIL,true),Field("cliente.telefono","Teléfono",FieldKind.PHONE),Field("cliente.telefono_alt","Teléfono alternativo",FieldKind.PHONE),pick("documento.uso_venta","Uso CFDI",c.list("usos_venta"))).map { it.copy(enabled=!editing||v.n("documento.id_fase")<5) }
        2 -> listOf(pick("documento.moneda","Moneda",c.list("monedas")),Field("documento.tipo_cambio","Tipo de cambio",FieldKind.NUMBER,true)).map { it.copy(enabled=products) }
        3 -> listOf(pick("documento.paqueteria","Paquetería de envío",c.list("paqueterias")),Field("documento.direccion_envio.contacto","Contacto",required=true),Field("documento.direccion_envio.codigo_postal","Código postal",FieldKind.NUMBER,true),Field("documento.direccion_envio.calle","Calle",required=true),Field("documento.direccion_envio.numero","Número exterior",required=true),Field("documento.direccion_envio.numero_int","Número interior"),pick("documento.direccion_envio.colonia","Colonia",c.list("colonias"),id="codigo"),Field("documento.direccion_envio.colonia_text","Colonia (texto)"),Field("documento.direccion_envio.ciudad","Ciudad",required=true),Field("documento.direccion_envio.estado","Estado",required=true),Field("documento.direccion_envio.referencia","Referencias de entrega",FieldKind.LONG),Field("documento.direccion_envio.contenido","Contenido"),pick("documento.direccion_envio.tipo_envio","Tipo de envío",c.list("paqueterias").find{it.s("id")==v.s("documento.paqueteria")}.objectOrEmpty().list("tipos"),false,"codigo"),number("documento.costo_envio","Costo de envío al cliente"),number("documento.costo_envio_total","Costo de envío total"),toggle("documento.shipping_null","Sin envío")).map { it.copy(enabled=shipping) }
        4 -> listOf(pick("documento.periodo","Plazo de pago",c.list("periodos")))
        else -> listOf(Field("documento.seguimiento","Seguimiento",FieldKind.LONG),Field("addenda.orden_compra","Orden de compra",group="Addenda"),Field("addenda.solicitud_pago","Solicitud de pago",group="Addenda"),Field("addenda.tipo_documento","Tipo de documento",group="Addenda"),Field("addenda.factura_asociada","Factura asociada",group="Addenda"))
    }
}

val lineFields=listOf(Field("cantidad","Cantidad",FieldKind.NUMBER,true),Field("precio","Precio unitario",FieldKind.NUMBER,true),Field("garantia","Garantía"),Field("comentario","Comentario",FieldKind.LONG),Field("regalo","Regalo",FieldKind.BOOL),Field("ret","Retención",FieldKind.BOOL),Field("addenda","Addenda"))

val paymentForms=choices("01" to "01 · Efectivo","02" to "02 · Cheque","03" to "03 · Transferencia","04" to "04 · Tarjeta de crédito","05" to "05 · Monedero electrónico","06" to "06 · Dinero electrónico","08" to "08 · Vales","12" to "12 · Dación en pago","13" to "13 · Subrogación","14" to "14 · Consignación","15" to "15 · Condonación","17" to "17 · Compensación","23" to "23 · Novación","24" to "24 · Confusión","25" to "25 · Remisión","26" to "26 · Prescripción","27" to "27 · A satisfacción del acreedor","28" to "28 · Tarjeta de débito","29" to "29 · Tarjeta de servicios","30" to "30 · Aplicación de anticipos","31" to "31 · Intermediario","99" to "99 · Por definir")
fun billingFields(v:JsonObject,publicGeneral:Boolean,credit:Boolean):List<Field> = listOf(Field("series","Serie",required=true),Field("folio","Folio (opcional)"),Field("paymentMethod","Método de pago",FieldKind.SELECT,true,if(credit)choices("PUE" to "PUE · Una exhibición") else choices("PUE" to "PUE · Una exhibición","PPD" to "PPD · Parcialidades o diferido")),Field("paymentForm","Forma de pago",FieldKind.SELECT,true,paymentForms)) +
    (if(credit)listOf(Field("relationshipCode","Relación CFDI",FieldKind.SELECT,false,choices("01" to "01 · Nota de crédito","03" to "03 · Devolución"))) else emptyList()) +
    if(publicGeneral&&!credit)listOf(Field("informacionGlobal.periodicity","Periodicidad",FieldKind.SELECT,true,choices("01" to "Diaria","02" to "Semanal","03" to "Quincenal","04" to "Mensual","05" to "Bimestral"),"Información global"),Field("informacionGlobal.months","Mes / bimestre",FieldKind.SELECT,true,(if(v.s("informacionGlobal.periodicity")=="05")13..18 else 1..12).map { Choice(it.toString().padStart(2,'0'),it.toString().padStart(2,'0')) },"Información global"),Field("informacionGlobal.year","Año",FieldKind.NUMBER,true,group="Información global")) else emptyList()

fun validateBilling(values:JsonObject,preview:JsonObject,credit:Boolean) {
    require(preview.flag("valid")) { "La previsualización tiene bloqueos. Corrige el documento antes de timbrar." }
    require(Regex("[a-zA-Z0-9]{1,25}").matches(values.s("series"))) { "La serie debe contener de 1 a 25 letras o números." }
    require(values.s("folio").isEmpty()||Regex("[a-zA-Z0-9_-]{1,40}").matches(values.s("folio"))) { "El folio no es válido." }
    require(values.s("paymentMethod") in listOf("PUE","PPD")) { "Selecciona el método de pago." }
    require(!credit||values.s("paymentMethod")=="PUE") { "Las notas de crédito requieren PUE." }
    require(paymentForms.any {it.id==values.s("paymentForm")}) { "Selecciona una forma de pago válida." }
    if(preview.s("payload.content.receiver.rfc").uppercase()=="XAXX010101000"&&!credit) {
        require(values.n("informacionGlobal.year") in 2021..java.time.LocalDate.now().year) { "Revisa el año de la información global." }
        val months=if(values.s("informacionGlobal.periodicity")=="05")13..18 else 1..12
        require(values.n("informacionGlobal.months") in months) { "El mes no corresponde a la periodicidad." }
    }
    require(preview.s("request.status")!="uncertain" && !preview.flag("request.is_active")) { "La solicitud está activa o requiere conciliación. Actualiza su estado antes de continuar." }
}
