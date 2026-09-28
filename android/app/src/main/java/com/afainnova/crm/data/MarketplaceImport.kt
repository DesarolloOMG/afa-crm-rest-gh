package com.afainnova.crm.data

import com.google.gson.JsonObject
import java.math.BigDecimal

/** The web creation screen connects only Mercado Libre; dormant provider adapters are intentionally omitted. */
fun importMercadoLibre(draft:JsonObject,order:JsonObject,catalog:JsonObject):JsonObject {
    require(order.s("id").isNotBlank()){ "La venta del marketplace no tiene identificador." }
    require(order.s("status") !in setOf("cancelled","invalid")){ "La venta está cancelada o es inválida en Mercado Libre." }
    val result=draft.deepCopy()
    fun put(key:String,value:Any?)=result.put(key,value)
    put("documento.venta",order.s("id"));put("documento.mkt_created_at",order.s("date_created"));put("documento.total",order.money("total_amount"));put("documento.cobro.importe",order.money("total_amount"));put("documento.mkt_fee",order.money("total_amount")*BigDecimal("0.13"));put("documento.mkt_coupon",0)
    put("documento.observacion",order.s("buyer.nickname"));val reference=order.list("payments").lastOrNull()?.s("id").orEmpty();put("documento.referencia",reference);put("documento.cobro.referencia",reference)
    put("cliente.razon_social",(order.s("buyer.first_name")+" "+order.s("buyer.last_name")).trim().uppercase());put("cliente.correo",order.s("buyer.email"))
    for((field,path) in mapOf("telefono" to "phone","telefono_alt" to "alternative_phone"))put("cliente.$field",listOf(order.s("buyer.$path.area_code"),order.s("buyer.$path.number")).filter(String::isNotBlank).joinToString(" - "))
    val shipping=order.o("shipping")
    val warehouses=catalog.list("empresas").find{it.s("id")==draft.s("empresa")}.objectOrEmpty().list("almacenes")
    if(shipping.size()>0){
        put("documento.mkt_shipping",shipping.s("id"));put("documento.info_extra",shipping.o("shipping_option").toString());put("documento.costo_envio",shipping.money("costo"));put("documento.costo_envio_total",shipping.money("shipping_option.list_cost"))
        mapOf("contacto" to "receiver_name","calle" to "street_name","numero" to "street_number","colonia_text" to "neighborhood.name","codigo_postal" to "zip_code").forEach{(to,from)->put("documento.direccion_envio.$to",shipping.s("receiver_address.$from"))}
        val full=shipping.s("logistic_type")=="fulfillment"
        put("documento.fulfillment",if(full)1 else 0)
        val carrier=if(full)"9" else if(shipping.s("tracking_method")=="Express")"2" else catalog.list("paqueterias").find{it.s("paqueteria").equals(shipping.s("tracking_method"),true)||it.s("paqueteria").equals(shipping.s("tracking_method").substringBefore(' '),true)}?.s("id").orEmpty()
        put("documento.paqueteria",carrier)
        if(full)warehouses.find{it.s("almacen").equals("mercadolibre",true)}?.let{put("documento.almacen",it.s("id"))}
        else warehouses.find{it.s("id")=="1"}?.let{put("documento.almacen",it.s("id"))}
    }
    order.list("productos").forEach{product->warehouses.find{it.s("id_almacen")==product.s("id_almacen")}?.let{put("documento.almacen",it.s("id"))}}
    put("documento.productos",emptyList<Any>());put("productos_venta",emptyList<Any>());put("terminar",1)
    return result
}
