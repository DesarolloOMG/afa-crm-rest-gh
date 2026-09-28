package com.afainnova.crm

import android.graphics.Bitmap
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.lifecycle.ViewModelProvider
import androidx.test.platform.app.InstrumentationRegistry
import com.afainnova.crm.data.*
import com.google.gson.*
import org.junit.*
import java.io.File
import java.util.concurrent.CopyOnWriteArrayList

private class FixtureSession:Sessions {
    var user:User?=User("fixture-only",obj("id" to 7,"nombre" to "Operador Demo","niveles" to listOf(6,8,11,12,16),"subniveles" to obj("6" to listOf(1),"11" to listOf(36))),Long.MAX_VALUE)
    override fun read()=user
    override fun save(token:String)=requireNotNull(user)
    override fun clear(){user=null}
}
private class FixtureTransport:Transport {
    val calls=CopyOnWriteArrayList<Call>()
    val company=obj("id" to 1,"empresa" to "AFA Demo","almacenes" to listOf(obj("id" to 1,"id_almacen" to 1,"almacen" to "Concentro")))
    val sale=obj("id" to 9001,"id_fase" to 5,"id_tipo" to 2,"no_venta" to "200000000000001","cliente" to "CLIENTE DE PRUEBA","rfc" to "XAXX010101000","marketplace" to "TIENDA","almacen" to "CONCENTRO","empresa_razon" to "AFA Demo","comentario" to "200000000000001","correo" to "qa@example.test","total" to 2500,"direccion" to obj("calle" to "Calle de prueba","numero" to 10,"colonia" to "Centro","ciudad" to "Guadalajara","estado" to "Jalisco","codigo_postal" to "44100"),"productos" to (1..12).map{obj("sku" to "SKU-$it","descripcion" to "Producto de prueba $it","cantidad" to 1,"precio" to 100,"series" to listOf(obj("serie" to "SERIE-$it")))},"seguimiento" to listOf(obj("nombre" to "Operador Demo","seguimiento" to "Pedido revisado","created_at" to "2026-09-28 10:00:00")))
    val bill=sale.changed("can_hub",true).changed("can_external",true).changed("billing_series","FML").changed("document_type",2)
    override suspend fun execute(call:Call):JsonElement{
        calls.add(call)
        return when {
            call.path=="general/busqueda/venta/informacion"->obj("code" to 200,"ventas" to listOf(sale),"notas_credito" to listOf(obj("id" to 9100,"cliente" to "CLIENTE DE PRUEBA")))
            call.path=="general/busqueda/venta/nota/informacion"->obj("code" to 200,"nota" to obj("id" to 9100,"cliente" to "CLIENTE DE PRUEBA","total" to 1250,"productos" to listOf(obj("sku" to "SKU-1","cantidad" to 1,"precio" to 1250))))
            call.path=="ticket/informacion-por-estado"->listOf(obj("id" to 44,"titulo" to "Impresora sin conexión","descripcion" to "No imprime etiquetas","estado" to call.payload.s("data").ifBlank{"nuevo"},"creador" to obj("nombre" to "Usuario Demo"),"asignado_a" to 7)).json()
            call.path=="ticket/tecnicos"->listOf(obj("id" to 7,"nombre" to "Técnico Demo")).json()
            call.path=="compra/proveedor/data"->obj("paises" to listOf(obj("pais" to "MEXICO")),"regimenes" to listOf(obj("id" to 1,"codigo" to "601","regimen" to "General de Ley Personas Morales","condicion" to "M"),obj("id" to 2,"codigo" to "616","regimen" to "Sin obligaciones fiscales","condicion" to "F")),"condiciones" to listOf(obj("id" to 1,"periodo" to "Contado")))
            call.path=="compra/producto/gestion/data"->obj("empresas" to listOf(company),"tipos" to listOf(obj("id" to 1,"tipo" to "Producto")),"proveedores" to emptyList<Any>())
            call.path.startsWith("compra/cliente/data/")||call.path.startsWith("compra/proveedor/data/")->obj("data" to listOf(obj("id" to 5,"razon_social" to "CLIENTE DEMO SA DE CV","rfc" to "AAA010101AAA","codigo_postal_fiscal" to "44100","pais" to "MEXICO","regimen" to "601","condicion" to 1,"correo" to "qa@example.test")))
            call.path=="compra/producto/gestion/producto"->obj("productos" to listOf(obj("id" to 1,"sku" to "SKU-001","descripcion" to "Producto de prueba","tipo" to 1,"imagenes_anteriores" to emptyList<Any>(),"precios_empresa" to listOf(obj("id_empresa" to 1,"precio" to 150)))))
            call.path=="configuracion/usuario/gestion/data"->obj("usuarios" to listOf(obj("id" to 7,"nombre" to "Operador Demo","email" to "qa@example.test","celular" to "3300000000","area" to "Sistemas","empresas" to listOf(obj("id_empresa" to 1)),"subniveles" to listOf(obj("id_subnivel_nivel" to 36)))),"empresas" to listOf(company),"niveles" to listOf(obj("nivel" to "Contabilidad","subniveles" to listOf(obj("subnivel" to "Facturación","pivot" to obj("id" to 36))))))
            call.path=="configuracion/sistema/marketplace/data"->obj("marketplaces" to listOf(newRecord(Section.MARKETPLACES).changed("id",2).changed("marketplace.marketplace","TIENDA").changed("area.id",1)),"areas" to listOf(obj("id" to 1,"area" to "AFA")),"empresas" to listOf(company))
            call.path=="configuracion/almacen"->obj("data" to listOf(obj("id" to 1,"almacen" to "Concentro")))
            call.path=="venta/venta/crear/data"->obj("empresas" to listOf(company),"areas" to listOf(obj("id" to 1,"area" to "AFA","marketplaces" to listOf(obj("id" to 2,"marketplace" to "TIENDA")))) ,"periodos" to listOf(obj("id" to 1,"periodo" to "Contado")),"monedas" to listOf(obj("id" to 3,"moneda" to "MXN")),"paqueterias" to listOf(obj("id" to 1,"paqueteria" to "Entrega local")),"usos_venta" to listOf(obj("id" to 23,"descripcion" to "Sin efectos fiscales")),"regimenes" to listOf(obj("id" to 2,"codigo" to "616","regimen" to "Sin obligaciones fiscales","condicion" to "F")))
            call.path=="venta/venta/pedido/pendiente/data"->obj("ventas" to listOf(sale.changed("id_fase",1)))
            call.path.startsWith("venta/venta/editar/documento/")->obj("informacion" to sale.changed("id_entidad",5).changed("id_almacen_principal_empresa",1).changed("id_marketplace_area",2).changed("area",1).changed("id_periodo",1).changed("id_moneda",3).changed("tipo_cambio",1).changed("id_cfdi",23).changed("regimen_id",2).changed("id_fase",1))
            call.path.startsWith("general/busqueda/serie/")->obj("empresas" to listOf(obj("empresa" to "AFA Demo","serie" to listOf(obj("serie" to "SER-1","sku" to "SKU-1")),"movimientos" to listOf(obj("sku" to "SKU-1","descripcion" to "Producto Demo","tipo" to "VENTA","documento" to 9001)))))
            call.path=="general/busqueda/producto/data"->obj("empresas" to listOf(company))
            call.path=="general/busqueda/producto/existencia"->obj("productos" to listOf(obj("codigo" to "SKU-001","descripcion" to "Producto de prueba","almacenes" to listOf(obj("almacen" to "Concentro","inventario" to 12,"disponible" to 9)))))
            call.path.startsWith("venta/venta/facturacion/pendientes")->obj("data" to obj("documents" to listOf(bill,bill.changed("id",9002)),"configured" to true,"pagination" to obj("page" to 1,"last_page" to 1,"total" to 2)))
            call.path=="venta/venta/facturacion/seleccion"->obj("data" to obj("documents" to call.payload["documentos"].arrayOrEmpty().map{bill.changed("id",it.asInt)}))
            call.path.startsWith("venta/venta/facturacion/previsualizar/")->obj("data" to obj("valid" to true,"billing_series" to "FML","payload" to obj("content" to obj("receiver" to obj("rfc" to "XAXX010101000","name" to "PUBLICO GENERAL"),"paymentMethod" to "PUE","paymentForm" to "03"))))
            call.path.startsWith("venta/venta/facturacion/individual/")->obj("code" to 202,"message" to "Solicitud pendiente de timbrado","request" to obj("id" to 99,"status" to "pending","documents_status" to "pending"))
            call.path=="ticket/crear"->obj("message" to "Ticket creado exitosamente con el ID 45")
            else ->obj("code" to 200,"message" to "Operación de prueba completada")
        }
    }
}

class MobileFlowTest {
    private val transport=FixtureTransport()
    init {MainActivity.repositoryFactory={CrmRepository(transport,FixtureSession())}}
    @get:Rule val rule=createAndroidComposeRule<MainActivity>()
    private fun vm()=ViewModelProvider(rule.activity)[CrmViewModel::class.java]
    private fun idle(){rule.waitUntil(10000){!vm().state.value.busy};rule.waitForIdle()}
    private fun open(section:Section){rule.runOnUiThread{vm().open(section)};idle()}
    private fun screenshot(name:String){val inst=InstrumentationRegistry.getInstrumentation();inst.uiAutomation.waitForIdle(700,5000);val bitmap=inst.uiAutomation.takeScreenshot();val dir=File(inst.targetContext.getExternalFilesDir(null),"verification").apply{mkdirs()};File(dir,"$name.png").outputStream().use{bitmap.compress(Bitmap.CompressFormat.PNG,100,it)}}
    @After fun reset(){MainActivity.repositoryFactory=null}
    @Test fun documentsUseCardsAndBackSurvivesLongDetailAndRecreation(){
        screenshot("home");open(Section.DOCUMENTS)
        rule.onNode(hasSetTextAction()).performTextInput("9001");rule.onAllNodesWithText("Buscar").onLast().performClick();idle()
        screenshot("document-search")
        rule.onNodeWithText("Pedido #9001").performScrollTo().performClick();idle()
        screenshot("document-detail")
        rule.onNodeWithContentDescription("Volver").assertIsDisplayed()
        rule.runOnUiThread{rule.activity.requestedOrientation=android.content.pm.ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE}
        rule.waitUntil(10000){rule.activity.resources.configuration.orientation==android.content.res.Configuration.ORIENTATION_LANDSCAPE};rule.waitForIdle();screenshot("document-landscape")
        rule.onNodeWithContentDescription("Volver").assertIsDisplayed()
        rule.runOnUiThread{rule.activity.requestedOrientation=android.content.pm.ActivityInfo.SCREEN_ORIENTATION_PORTRAIT}
        rule.waitUntil(10000){rule.activity.resources.configuration.orientation==android.content.res.Configuration.ORIENTATION_PORTRAIT};rule.waitForIdle()
        rule.activityRule.scenario.recreate();rule.waitForIdle();rule.onNodeWithContentDescription("Volver").assertIsDisplayed()
        rule.onNodeWithText("Pedido #9001").assertExists();rule.onNodeWithContentDescription("Volver").performClick();rule.onNodeWithText("Documentos").assertExists()
    }
    @Test fun creditNoteHasIndependentDetailsAndPdfAction(){
        open(Section.DOCUMENTS);rule.runOnUiThread{vm().filter("campo","nota");vm().filter("criterio","9100");vm().search()};idle()
        rule.onNodeWithText("Nota de crédito #9100").performScrollTo().performClick();idle()
        rule.onNodeWithText("Descargar nota de crédito").assertIsDisplayed();rule.onNodeWithText("Agregar seguimiento o archivo").assertDoesNotExist()
    }
    @Test fun createTicketSubmitsOnlyAfterConfirmation(){
        open(Section.TICKETS);rule.onNodeWithText("Crear ticket").performClick();idle()
        rule.onNodeWithText("Título *").performTextInput("Problema de prueba")
        rule.onNodeWithText("Describe el problema *").performTextInput("No funciona la impresora")
        rule.onNodeWithText("Guardar").performClick();rule.onNodeWithText("Guardar cambios").assertIsDisplayed()
        Assert.assertFalse(transport.calls.any{it.path=="ticket/crear"})
        rule.onNodeWithText("Confirmar").performClick();idle()
        Assert.assertEquals("Problema de prueba",transport.calls.last{it.path=="ticket/crear"}.payload.s("titulo"))
    }
    @Test fun assigningTicketUsesChosenTechnician(){
        open(Section.TICKETS);rule.onNodeWithText("Impresora sin conexión").performScrollTo().performClick();rule.onNodeWithText("Asignar técnico").performClick();idle()
        rule.onNodeWithText("Técnico *").performClick();rule.onAllNodesWithText("Técnico Demo").onLast().performClick();rule.onNodeWithText("Guardar").performClick();rule.onNodeWithText("Confirmar").performClick();idle()
        Assert.assertEquals("7",transport.calls.last{it.path=="ticket/asignar"}.payload.s("asignado_a"))
    }
    @Test fun managementSectionsOpenNativeForms(){
        for(section in listOf(Section.PRODUCTS,Section.CUSTOMERS,Section.SUPPLIERS,Section.USERS,Section.MARKETPLACES)){
            open(section);rule.runOnUiThread{vm().edit()};idle();rule.onNodeWithText("Guardar").assertIsDisplayed();rule.onNodeWithText("Cancelar").performClick();idle();rule.onNodeWithContentDescription("Volver").performClick();idle()
        }
    }
    @Test fun warehouseHasDeleteButNoFakeEditSave(){open(Section.WAREHOUSES);rule.onNodeWithText("Concentro").performClick();rule.onNodeWithText("Eliminar almacén").assertIsDisplayed();rule.onNodeWithText("Guardar").assertDoesNotExist()}
    @Test fun seriesAndStockKeepTheirActions(){
        open(Section.SERIES);rule.runOnUiThread{vm().filter("criterio","SER-1");vm().search()};idle();rule.runOnUiThread{vm().show(vm().state.value.page.rows.first())};idle();rule.onNodeWithText("Imprimir etiqueta de serie").assertIsDisplayed();rule.onNodeWithContentDescription("Volver").performClick();rule.onNodeWithContentDescription("Volver").performClick()
        open(Section.STOCK);rule.runOnUiThread{vm().filter("criterio","SKU-001");vm().search()};idle();rule.runOnUiThread{vm().show(vm().state.value.page.rows.first())};idle();rule.onNodeWithText("Movimientos / kardex y Excel").assertIsDisplayed()
    }
    @Test fun saleWizardKeepsDraftAcrossRecreation(){
        open(Section.SALES);rule.onNodeWithText("Crear venta").performClick();idle();rule.runOnUiThread{vm().change("documento.referencia","REF-DEMO");vm().saleStep(2)};idle();screenshot("sale-products")
        rule.activityRule.scenario.recreate();rule.waitForIdle();Assert.assertEquals("REF-DEMO",vm().state.value.page.draft.s("documento.referencia"));Assert.assertEquals(2,vm().state.value.page.step);rule.onNodeWithText("Continuar").assertIsDisplayed()
    }
    @Test fun billingShowsPendingRatherThanFalseSuccess(){
        open(Section.BILLING);rule.runOnUiThread{vm().show(vm().state.value.page.rows.first())};idle();screenshot("billing-preview")
        rule.onNodeWithText("Solicitar timbrado").performClick();rule.onNodeWithText("Confirmar").performClick();idle()
        rule.onNodeWithText("Estado de facturación").assertExists();Assert.assertEquals("pending",vm().state.value.page.data.s("request.status"));Assert.assertEquals(1,transport.calls.count{it.path=="venta/venta/facturacion/individual/9001"})
    }
}
