package com.afainnova.crm

import com.afainnova.crm.data.*
import com.google.gson.*
import kotlinx.coroutines.test.runTest
import okhttp3.FormBody
import okio.Buffer
import org.junit.Assert.*
import org.junit.Test
import java.math.BigDecimal

private class TestSessions:Sessions {
    var user:User?=User("fixture.jwt.not-a-real-token",obj("id" to 7,"nombre" to "Operador de prueba","niveles" to listOf(6,11),"subniveles" to obj("6" to listOf(1),"11" to listOf(36))),Long.MAX_VALUE)
    override fun read()=user
    override fun save(token:String)=requireNotNull(user)
    override fun clear(){user=null}
}
private class RecordingTransport(var response:JsonElement=obj("code" to 200)):Transport {
    val calls=mutableListOf<Call>()
    override suspend fun execute(call:Call):JsonElement{calls.add(call);return response.deepCopy()}
}
class ContractTest {
    @Test fun dataEncodingKeepsLegacyEnvelopeAndQueryAuthentication(){
        val client=ApiClient(TestSessions(),"https://example.test/")
        val r=client.requestFor(Call("ticket/crear","POST",obj("titulo" to "Impresora & almacén + 1")))
        assertEquals("fixture.jwt.not-a-real-token",r.url.queryParameter("token"))
        val body=r.body as FormBody
        assertEquals("data",body.name(0));assertEquals("Impresora & almacén + 1",parseObject(body.value(0)).s("titulo"))
    }
    @Test fun fiscalCodeCannotReplaceSessionAuthentication(){
        val client=ApiClient(TestSessions(),"https://example.test/")
        val r=client.requestFor(Call("general/busqueda/venta/refacturacion","POST",obj("token" to "123456"),Encoding.JSON))
        val buffer=Buffer();r.body!!.writeTo(buffer)
        assertEquals("123456",parseObject(buffer.readUtf8()).s("token"));assertEquals("fixture.jwt.not-a-real-token",r.url.queryParameter("token"))
    }
    @Test fun ticketStateUsesRawFieldNotJsonString(){
        val r=ApiClient(TestSessions(),"https://example.test/").requestFor(Call("ticket/informacion-por-estado","POST",obj("data" to "en_revision"),Encoding.FIELDS))
        assertEquals("en_revision",(r.body as FormBody).value(0))
    }
    @Test fun loginNeverUsesExpiredSession(){
        val s=TestSessions();s.clear();val request=ApiClient(s,"https://example.test/").requestFor(Call("auth/login","POST",obj("email" to "qa@example.test"),authenticated=false))
        assertNull(request.url.queryParameter("token"))
    }
    @Test fun unsafeBaseAndCallCannotLeakSession(){
        assertThrows(IllegalArgumentException::class.java){ApiClient(TestSessions(),"http://example.test/")}
        assertThrows(IllegalArgumentException::class.java){ApiClient(TestSessions(),"https://example.test/").requestFor(Call("https://attacker.test/"))}
    }
    @Test fun searchSeparatesSaleAndCreditEvenWhenTheirIdsMatch()=runTest {
        val t=RecordingTransport(obj("ventas" to listOf(obj("id" to 12)),"notas_credito" to listOf(obj("id" to 12))))
        val rows=CrmRepository(t,TestSessions()).search(Section.DOCUMENTS,obj("campo" to "nota","criterio" to "12"),obj())
        assertEquals(listOf("sale","credit"),rows.map{it.s("_kind")});assertEquals("nota",t.calls.single().payload.s("campo"))
    }
    @Test fun allDocumentCriteriaAreAvailable(){assertEquals(listOf("id","no_venta","nota","rfc","razon_social","correo","referencia","observacion","comentario","guia"),documentCriteria.map{it.first})}
    @Test fun closedTicketLimitIsNotShownAsSuccess()=runTest{
        val repo=CrmRepository(RecordingTransport(obj("message" to "Ya alcanzaste el limite de tickets abiertos (5)")),TestSessions())
        try{repo.save(Section.TICKETS,newRecord(Section.TICKETS));fail("Expected rejection")}catch(e:IllegalArgumentException){assertTrue(e.message!!.contains("limite"))}
    }
    @Test fun ticketResolutionMergesOnlyAssignedStates()=runTest{
        val t=RecordingTransport(JsonArray());CrmRepository(t,TestSessions()).search(Section.TICKETS,obj("estado" to "resolucion"),obj())
        assertEquals(listOf("asignado","en_revision"),t.calls.map{it.payload.s("data")});assertTrue(t.calls.all{it.encoding==Encoding.FIELDS})
    }
    @Test fun userSavePreservesLegacyAreaAndWarehouseArrays()=runTest{
        val t=RecordingTransport();val record=newRecord(Section.USERS).changed("area","Sistemas").changed("empresa_almacen",listOf(2,3))
        CrmRepository(t,TestSessions()).save(Section.USERS,record)
        assertEquals("\"Sistemas\"",t.calls.single().payload.s("area"));assertEquals("[2,3]",t.calls.single().payload["uea"].toString())
    }
    @Test fun productEditRetainsExistingImagesAndPriceData(){
        val source=obj("id" to 1,"imagenes_anteriores" to listOf(obj("dropbox" to "id:fixture")),"precios_empresa" to listOf(obj("id_empresa" to 2,"precio" to 45)))
        val edit=normalizeRecord(Section.PRODUCTS,source)
        assertEquals("id:fixture",edit.list("imagenes_anteriores").single().s("dropbox"));assertEquals(45,edit.list("precios_empresa").single().n("precio"));assertTrue(edit.list("imagenes").isEmpty())
    }
    @Test fun userRelationsBecomePermissionIdsNotModelIds(){
        val user=normalizeRecord(Section.USERS,obj("marketplaces" to listOf(obj("id" to 88,"id_marketplace_area" to 4)),"subniveles" to listOf(obj("id_subnivel_nivel" to 36)),"empresas" to listOf(obj("id_empresa" to 1))))
        assertEquals("4",user["marketplaces"].asJsonArray[0].asString);assertEquals("36",user["subniveles"].asJsonArray[0].asString)
    }
    @Test fun adminDoesNotBypassStrictBillingPermission(){val user=User("",obj("niveles" to listOf(6),"subniveles" to obj()),Long.MAX_VALUE);assertTrue(user.visible(Section.USERS));assertFalse(user.visible(Section.BILLING))}
    @Test fun excludedCarrierModuleIsNotInNavigation(){assertFalse(Section.entries.any{it.name=="CARRIERS"})}
    @Test fun marketplaceWithoutApiCanBeConfiguredWithoutTryingToUnlockNonexistentSecret(){
        val record=normalizeRecord(Section.MARKETPLACES,obj("id" to 20,"api" to null,"empresa" to null))
        assertEquals(0,record.n("api.id"));assertTrue(record.o("api").has("secret"));assertTrue(entityFields(Section.MARKETPLACES,obj(),record).first{it.key=="api.app_id"}.enabled)
    }
    @Test fun editUsesRequestedDocumentNotJoinedAddressId(){
        val s=saleFromDocument(obj("id" to 900,"_documentId" to 37802,"id_entidad" to 72,"id_fase" to 5,"id_tipo" to 2,"area" to 2))
        assertEquals(37802,s.n("documento.documento"));assertEquals("72",s.s("cliente.select"));assertEquals(2,s.n("area"));assertFalse(s.flag("documento.editar_envio"));assertFalse(s.flag("documento.editar_productos"))
    }
    @Test fun pendingOrderIsIdentifiedByPhase(){assertTrue(saleFromDocument(obj("id" to 1,"id_fase" to 1,"id_tipo" to 2)).flag("documento.pedido"))}
    private fun sale()=newSale().apply{put("cliente.rfc","XAXX010101000");put("documento.marketplace","2");put("documento.almacen","1");put("documento.productos",listOf(newLine(obj("sku" to "A","tipo" to 1,"ultimo_costo" to 100)).changed("cantidad",2).changed("precio",100)))}
    @Test fun lowMarginRequiresAuthorizationAndDoesNotOverwriteMarketplaceTotal()=runTest{
        val t=RecordingTransport(obj("code" to 200,"existencia" to 10));val data=sale().changed("documento.total",199)
        CrmRepository(t,TestSessions()).saveSale(data);val payload=t.calls.last().payload
        assertEquals(BigDecimal("200"),payload.money("documento.total_user"));assertEquals(199,payload.n("documento.total"));assertTrue(payload.flag("documento.baja_utilidad"))
    }
    @Test fun aggregateStockBlocksDuplicateSkuLines()=runTest{
        val t=RecordingTransport(obj("code" to 200,"existencia" to 3));val d=sale();d.put("documento.productos",d.list("documento.productos")+d.list("documento.productos"))
        try{CrmRepository(t,TestSessions()).saveSale(d);fail("Expected insufficient stock")}catch(_:IllegalArgumentException){}
        assertEquals(1,t.calls.size);assertTrue(t.calls.single().path.endsWith("/4"));assertEquals("GET",t.calls.single().method)
    }
    @Test fun invalidQuantityNeverMutatesBackend()=runTest{
        val t=RecordingTransport();val d=sale();d.put("documento.productos",listOf(newLine(obj("sku" to "A")).changed("cantidad",0)))
        try{CrmRepository(t,TestSessions()).saveSale(d);fail("Expected validation")}catch(_:IllegalArgumentException){}
        assertTrue(t.calls.isEmpty())
    }
    @Test fun canceledMarketplaceOrderCannotBeImported(){assertThrows(IllegalArgumentException::class.java){importMercadoLibre(newSale(),obj("id" to 3,"status" to "cancelled"),obj())}}
    @Test fun importingMarketplaceKeepsItsTotalAndRequiresManualLineReview(){
        val imported=importMercadoLibre(newSale(),obj("id" to "20000000000001","status" to "paid","total_amount" to "130.50","buyer" to obj("first_name" to "Cliente","last_name" to "Prueba")),obj())
        assertEquals("20000000000001",imported.s("documento.venta"));assertEquals(BigDecimal("130.50"),imported.money("documento.total"));assertEquals("CLIENTE PRUEBA",imported.s("cliente.razon_social"));assertTrue(imported.list("documento.productos").isEmpty());assertEquals(1,imported.n("terminar"))
    }
    private fun fiscalPreview()=obj("valid" to true,"payload" to obj("content" to obj("receiver" to obj("rfc" to "XAXX010101000"))))
    private fun fiscalValues()=obj("series" to "FML","folio" to "ABC-1","paymentMethod" to "PUE","paymentForm" to "03","informacionGlobal" to obj("periodicity" to "04","months" to "09","year" to 2026))
    @Test fun publicCfdiUsesBackendEnglishGlobalKeys(){val fields=billingFields(fiscalValues(),true,false);assertTrue(fields.any{it.key=="informacionGlobal.periodicity"});validateBilling(fiscalValues(),fiscalPreview(),false)}
    @Test fun uncertainInvoiceMustBeReconciled(){assertThrows(IllegalArgumentException::class.java){validateBilling(fiscalValues(),fiscalPreview().changed("request.status","uncertain"),false)}}
    @Test fun creditNoteCannotUseDeferredPayment(){assertThrows(IllegalArgumentException::class.java){validateBilling(fiscalValues().changed("paymentMethod","PPD"),fiscalPreview(),true)}}
    @Test fun invalidBimonthlyPeriodIsRejected(){assertThrows(IllegalArgumentException::class.java){validateBilling(fiscalValues().changed("informacionGlobal.periodicity","05"),fiscalPreview(),false)}}
    @Test fun invalidSeriesCannotReachProvider(){assertThrows(IllegalArgumentException::class.java){validateBilling(fiscalValues().changed("series","F ML/"),fiscalPreview(),false)}}
    @Test fun cfdiExtractionPreservesFiscalIdentity(){val xml="""<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Serie="FML" Folio="4" TipoDeComprobante="I"><cfdi:Complemento><t:TimbreFiscalDigital xmlns:t="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="12345678-1234-1234-1234-123456789abc"/></cfdi:Complemento></cfdi:Comprobante>""";val id=FileRules.cfdi(FileRules.dataUrl(xml.toByteArray(),"application/xml"));assertEquals("12345678-1234-1234-1234-123456789ABC",id.s("uuid"));assertEquals("FML",id.s("serie"));assertEquals("I",id.s("tipo"))}
    @Test fun xmlExternalEntitiesAreRejected(){val malicious="""<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///nonexistent">]><foo>&xxe;</foo>""";assertThrows(Exception::class.java){FileRules.cfdi(FileRules.dataUrl(malicious.toByteArray(),"application/xml"))}}
    @Test fun falselyNamedPdfIsRejected(){assertThrows(IllegalArgumentException::class.java){FileRules.requirePdf("not a pdf".toByteArray())}}
    @Test fun attachmentReadIsBoundedEvenWithoutKnownSize(){assertThrows(IllegalArgumentException::class.java){FileRules.readLimited(java.io.ByteArrayInputStream(ByteArray(12)),10)}}
    @Test fun dateAndFilesAreNotRequiredInDormantPaymentBlock(){assertEquals(listOf("documento.periodo"),saleFields(4,obj(),newSale()).map{it.key})}
}
