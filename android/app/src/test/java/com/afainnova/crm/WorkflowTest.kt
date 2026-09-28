package com.afainnova.crm

import com.afainnova.crm.data.*
import com.google.gson.*
import kotlinx.coroutines.*
import kotlinx.coroutines.test.*
import org.junit.*
import org.junit.Assert.*

@OptIn(ExperimentalCoroutinesApi::class)
class WorkflowTest {
    private val dispatcher=StandardTestDispatcher()
    private val calls=mutableListOf<Call>()
    private var handler:suspend(Call)->JsonElement={obj("code" to 200)}
    private val sessions=object:Sessions {
        var value:User?=User("fixture",obj("id" to 7,"niveles" to listOf(6,11),"subniveles" to obj("11" to listOf(36))),Long.MAX_VALUE)
        override fun read()=value
        override fun save(token:String)=requireNotNull(value)
        override fun clear(){value=null}
    }
    private fun model()=CrmViewModel(CrmRepository(object:Transport{override suspend fun execute(call:Call):JsonElement{calls.add(call);return handler(call)}},sessions))
    @Before fun setUp(){Dispatchers.setMain(dispatcher)}
    @After fun tearDown(){Dispatchers.resetMain()}
    @Test fun aSlowReadCanBeClosedWithoutWaitingForNetwork()=runTest(dispatcher){
        handler={delay(100000);obj()};val vm=model();vm.open(Section.USERS);runCurrent();assertTrue(vm.state.value.busy)
        vm.back();runCurrent();assertEquals(PageKind.HOME,vm.state.value.page.kind);assertFalse(vm.state.value.busy);assertNull(vm.state.value.error)
    }
    @Test fun expiredSessionReturnsToLoginAndDropsNavigation()=runTest(dispatcher){
        handler={throw ApiException(401,"Sesión expirada")};val vm=model();vm.open(Section.USERS);advanceUntilIdle()
        assertNull(vm.state.value.user);assertEquals(PageKind.HOME,vm.state.value.page.kind)
    }
    @Test fun invalidFiscalPreflightCannotCreateSecondRequest()=runTest(dispatcher){
        val doc=obj("id" to 12,"can_hub" to true,"can_external" to true,"rfc" to "AAA010101AAA","billing_series" to "FML")
        handler={call->when {
            call.path.startsWith("venta/venta/facturacion/pendientes")->obj("data" to obj("configured" to true,"documents" to listOf(doc)))
            call.path.startsWith("venta/venta/facturacion/previsualizar")->obj("data" to obj("valid" to true,"payload" to obj("content" to obj("receiver" to obj("rfc" to "AAA010101AAA"),"paymentMethod" to "PUE","paymentForm" to "03"))))
            call.path.endsWith("/seleccion")->obj("data" to obj("documents" to listOf(doc.changed("request.status","uncertain"))))
            else->obj("code" to 200)
        }}
        val vm=model();vm.open(Section.BILLING);advanceUntilIdle();vm.billingForm("individual",listOf(doc));advanceUntilIdle();vm.sendBilling();vm.acceptConfirm();advanceUntilIdle()
        assertNotNull(vm.state.value.error);assertTrue(calls.none{it.path.contains("/individual/")});assertEquals(PageKind.BILL,vm.state.value.page.kind)
    }
    @Test fun authorizationFailureNeverCallsSaleDeletion()=runTest(dispatcher){
        handler={call->if(call.path=="authenticator/validate-with-option")throw ApiException(422,"Código inválido")else obj()}
        val vm=model();vm.action("Eliminar venta","cancel-sale",listOf(Field("token","Código",required=true)),obj("documento" to 45,"usuario" to 7,"token" to "123456","motivo" to "Prueba"));vm.saveAction();vm.acceptConfirm();advanceUntilIdle()
        assertEquals("Código inválido",vm.state.value.error);assertFalse(calls.any{it.path=="venta/venta/cancelar"})
    }
    @Test fun doubleTapDoesNotSubmitTwoTickets()=runTest(dispatcher){
        handler={call->if(call.path=="ticket/crear"){delay(1000);obj("message" to "Creado")}else JsonArray()}
        val vm=model();vm.open(Section.TICKETS);advanceUntilIdle();vm.edit();vm.change("titulo","Impresora");vm.change("descripcion","Sin conexión");vm.saveEntity();vm.acceptConfirm();runCurrent();vm.acceptConfirm();vm.back();assertEquals(PageKind.FORM,vm.state.value.page.kind);advanceUntilIdle()
        assertEquals(1,calls.count{it.path=="ticket/crear"})
    }
    @Test fun billingTypeChangeDropsCrossTypeSelection()=runTest(dispatcher){val vm=model();vm.open(Section.BILLING);advanceUntilIdle();vm.selectBilling(obj("id" to 1));vm.filter("document_type",6);assertTrue(vm.state.value.page.selected.isEmpty())}
    @Test fun aSearchErrorLeavesTheScreenRetryable()=runTest(dispatcher){var failed=false;handler={if(!failed){failed=true;throw ApiException(0,"Sin conexión")}else obj("ventas" to listOf(obj("id" to 1)))};val vm=model();vm.open(Section.DOCUMENTS);advanceUntilIdle();vm.filter("criterio","1");vm.search();advanceUntilIdle();assertEquals("Sin conexión",vm.state.value.error);vm.search();advanceUntilIdle();assertEquals(1,vm.state.value.page.rows.single().n("id"));assertNull(vm.state.value.error)}
}
