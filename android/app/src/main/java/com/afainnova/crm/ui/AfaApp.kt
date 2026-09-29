@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class,androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
package com.afainnova.crm.ui

import android.graphics.Bitmap
import android.view.WindowManager
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.*
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.grid.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.*
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.*
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.afainnova.crm.*
import com.afainnova.crm.data.*
import com.google.gson.JsonObject
import com.google.zxing.BarcodeFormat
import com.google.zxing.MultiFormatWriter

@Composable fun AfaApp(vm:CrmViewModel,onFile:(String)->Unit){AfaTheme{
    val state by vm.state.collectAsStateWithLifecycle()
    val p=state.page
    val navigationTitle=if(p.kind==PageKind.HOME)"AFA Móvil" else if(p.kind==PageKind.DETAIL&&p.section==Section.DOCUMENTS)"Detalle del documento" else p.title
    val activity=androidx.activity.compose.LocalActivity.current
    SideEffect{activity?.window?.let{androidx.core.view.WindowCompat.getInsetsController(it,it.decorView).isAppearanceLightStatusBars=state.user==null}}
    DisposableEffect(state.user==null,state.unlocked){if(state.user==null||state.unlocked)activity?.window?.addFlags(WindowManager.LayoutParams.FLAG_SECURE) else activity?.window?.clearFlags(WindowManager.LayoutParams.FLAG_SECURE);onDispose{activity?.window?.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)}}
    BackHandler(enabled=state.user!=null&&p.kind!=PageKind.HOME){vm.back()}
    val snack=remember{SnackbarHostState()}
    LaunchedEffect(state.notice){state.notice?.let{snack.showSnackbar(plain(it));vm.clearNotice()}}
    Scaffold(
        topBar={if(state.user!=null)TopAppBar(
            colors=TopAppBarDefaults.topAppBarColors(containerColor=Ink,titleContentColor=Color.White,navigationIconContentColor=Color.White,actionIconContentColor=Color.White),
            title={Column{Text(navigationTitle,maxLines=1,overflow=androidx.compose.ui.text.style.TextOverflow.Ellipsis,style=MaterialTheme.typography.titleLarge);if(p.kind==PageKind.HOME)Text("TU CENTRO DE OPERACIONES",style=MaterialTheme.typography.labelSmall,color=Color(0xFFBECEEA))}},
            navigationIcon={if(p.kind!=PageKind.HOME)IconButton(onClick=vm::back,enabled=!state.saving){Icon(Icons.AutoMirrored.Rounded.ArrowBack,"Volver")}},
            actions={if(p.kind==PageKind.HOME)IconButton(onClick=vm::logout){Icon(Icons.AutoMirrored.Rounded.Logout,"Cerrar sesión")};if(p.kind==PageKind.LIST)IconButton(onClick=vm::refresh,enabled=!state.busy){Icon(Icons.Rounded.Refresh,"Actualizar")}})},
        snackbarHost={SnackbarHost(snack)},
        bottomBar={if(state.user!=null)when(p.kind){
            PageKind.FORM,PageKind.ACTION,PageKind.BILL->Surface(shadowElevation=8.dp,color=Color.White){Row(Modifier.navigationBarsPadding().imePadding().padding(16.dp).fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(12.dp)){
                OutlinedButton(vm::back,Modifier.weight(1f).heightIn(min=54.dp),shape=MaterialTheme.shapes.medium,contentPadding=PaddingValues(horizontal=10.dp,vertical=12.dp),enabled=!state.busy){Text("Cancelar")}
                Button(onClick={when(p.kind){PageKind.FORM->vm.saveEntity();PageKind.BILL->vm.sendBilling();else->if(p.action=="invoice-cancel")vm.submitInvoiceCancellation() else if(p.action=="dev-nexfira")vm.resetDevNexfira() else vm.saveAction()}},Modifier.weight(1.6f).heightIn(min=54.dp),shape=MaterialTheme.shapes.medium,contentPadding=PaddingValues(horizontal=12.dp,vertical=12.dp),colors=ButtonDefaults.buttonColors(containerColor=if(p.action=="cancel-sale"||p.action=="invoice-cancel")MaterialTheme.colorScheme.error else Blue),enabled=!state.busy&&(p.action!="invoice-cancel"||p.data.s("factura.status")=="stamped")&&(p.action!="dev-nexfira"||p.data.flag("can_reset")||p.data.flag("can_manual_reset"))){
                    Icon(if(p.kind==PageKind.BILL)Icons.Rounded.ReceiptLong else if(p.action=="cancel-sale")Icons.Rounded.DeleteOutline else Icons.Rounded.Check,null,Modifier.size(19.dp));Spacer(Modifier.width(8.dp))
                    Text(if(p.kind==PageKind.BILL)if(p.action=="external")"Registrar CFDI" else if(p.step==0)"Ver factura" else "Enviar a Nexfira" else if(p.kind==PageKind.ACTION)when(p.action){"kardex"->"Consultar";"print-series"->"Imprimir";"line"->"Aplicar";"provider"->"Vincular";"unlock-marketplace"->"Validar";"cancel-sale"->"Eliminar";"invoice-cancel"->"Solicitar cancelación";"dev-nexfira"->"Liberar intento";else->"Guardar"}else "Guardar")
                }
            }}
            PageKind.SALE->Surface(shadowElevation=8.dp,color=Color.White){Row(Modifier.navigationBarsPadding().imePadding().padding(16.dp).fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(12.dp)){OutlinedButton(onClick={if(p.step==0)vm.back() else vm.saleStep(p.step-1)},Modifier.weight(1f).heightIn(min=54.dp),shape=MaterialTheme.shapes.medium,contentPadding=PaddingValues(horizontal=10.dp,vertical=12.dp),enabled=!state.busy){Text(if(p.step==0)"Cancelar" else "Anterior")};Button(onClick={if(p.step==5)vm.saveSale() else vm.saleStep(p.step+1)},Modifier.weight(1.3f).heightIn(min=54.dp),shape=MaterialTheme.shapes.medium,contentPadding=PaddingValues(horizontal=10.dp,vertical=12.dp),enabled=!state.busy){Text(if(p.step==5)"Guardar venta" else "Continuar");Spacer(Modifier.width(8.dp));Icon(if(p.step==5)Icons.Rounded.Check else Icons.AutoMirrored.Rounded.ArrowForward,null,Modifier.size(18.dp))}}}
            else->Unit
        }}
    ){padding->Column(Modifier.fillMaxSize().padding(padding)){
        if(state.busy)LinearProgressIndicator(Modifier.fillMaxWidth())
        if(state.user==null)LoginScreen(state,vm) else key(p.kind,p.section,p.title){when(p.kind){
            PageKind.HOME->Home(state.user!!,vm)
            PageKind.LIST->Listing(state,vm)
            PageKind.DETAIL->Detail(state,vm)
            PageKind.FORM->EntityForm(state,vm,onFile)
            PageKind.ACTION->ActionForm(state,vm,onFile)
            PageKind.SALE->SaleForm(state,vm,onFile)
            PageKind.BILL->BillingForm(state,vm,onFile)
            PageKind.LOOKUP->Lookup(state,vm)
            PageKind.INFO->InfoScreen(state,vm)
            PageKind.MERGE->MergeScreen(state,vm)
        }}
    }}
    state.error?.let{AlertDialog(onDismissRequest=vm::clearError,title={Text("Revisa la operación")},text={Text(plain(it))},confirmButton={TextButton(vm::clearError){Text("Entendido")}})}
    state.confirmation?.let{c->AlertDialog(onDismissRequest=vm::cancelConfirm,title={Text(c.title)},text={Text(c.message)},confirmButton={Button(vm::acceptConfirm){Text("Confirmar")}},dismissButton={TextButton(vm::cancelConfirm){Text("Volver")}})}
}}

@Composable private fun LoginScreen(state:UiState,vm:CrmViewModel){
    var data by remember{mutableStateOf(obj("email" to "","password" to "","code" to ""))}
    val mfa=state.mfa
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(horizontal=28.dp,vertical=40.dp),verticalArrangement=Arrangement.Center){
        Surface(color=Teal,shape=MaterialTheme.shapes.large,modifier=Modifier.size(66.dp)){Box(contentAlignment=Alignment.Center){Icon(Icons.Rounded.Business,null,tint=MaterialTheme.colorScheme.onPrimary,modifier=Modifier.size(36.dp))}}
        Spacer(Modifier.height(24.dp));Text("Tu operación,\na la mano.",style=MaterialTheme.typography.headlineLarge,fontWeight=FontWeight.Bold)
        Text("AFA · CRM móvil",Modifier.padding(top=10.dp,bottom=32.dp),color=Teal)
        FormFields(listOf(Field("email","Correo",FieldKind.EMAIL,true),Field("password","Contraseña",FieldKind.PASSWORD,true)),data){k,v->data=data.changed(k,v)}
        if(mfa.flag("mfa_setup")){
            Notice("Vincula tu cuenta en una aplicación de autenticación y escribe su código para continuar.")
            val uri=mfa.s("otpauth_uri")
            if(uri.isNotBlank()){
                val qr=remember(uri){val matrix=MultiFormatWriter().encode(uri,BarcodeFormat.QR_CODE,400,400);Bitmap.createBitmap(400,400,Bitmap.Config.ARGB_8888).apply{for(y in 0 until 400)for(x in 0 until 400)setPixel(x,y,if(matrix[x,y])android.graphics.Color.BLACK else android.graphics.Color.WHITE)}}
                Image(qr.asImageBitmap(),"Código QR para configurar autenticación",Modifier.size(230.dp).align(Alignment.CenterHorizontally))
            }
        }
        if(mfa.flag("mfa_setup")||mfa.flag("mfa_required"))FormField(Field("code","Código de autenticación",FieldKind.NUMBER,true),data){k,v->data=data.changed(k,v)}
        Button(onClick={vm.signIn(data.s("email"),data.s("password"),data.s("code"))},shape=MaterialTheme.shapes.medium,enabled=!state.busy,modifier=Modifier.fillMaxWidth().padding(top=20.dp).heightIn(min=54.dp)){Text(if(state.busy)"Validando…" else "Iniciar sesión");Spacer(Modifier.width(10.dp));Icon(Icons.AutoMirrored.Rounded.ArrowForward,null)}
        TextButton(onClick={vm.resetPassword(data.s("email"),data.s("code"))},enabled=data.s("email").isNotBlank()&&!state.busy,modifier=Modifier.align(Alignment.CenterHorizontally)){Text("Recuperar contraseña")}
    }
}

fun sectionIcon(section:Section):ImageVector=when(section){Section.DOCUMENTS->Icons.Rounded.Description;Section.TICKETS->Icons.Rounded.SupportAgent;Section.SERIES->Icons.Rounded.QrCode;Section.STOCK->Icons.Rounded.Inventory2;Section.PRODUCTS->Icons.Rounded.Category;Section.CUSTOMERS->Icons.Rounded.People;Section.SUPPLIERS->Icons.Rounded.LocalShipping;Section.SALES->Icons.Rounded.ShoppingBag;Section.BILLING->Icons.Rounded.ReceiptLong;Section.CANCELLATION->Icons.Rounded.Cancel;Section.USERS->Icons.Rounded.ManageAccounts;Section.MARKETPLACES->Icons.Rounded.Storefront;Section.WAREHOUSES->Icons.Rounded.Warehouse;Section.DEV->Icons.Rounded.Build}
@Composable private fun Home(user:User,vm:CrmViewModel){
    val groups=listOf("Tu operación" to listOf(Section.DOCUMENTS,Section.SALES,Section.TICKETS,Section.BILLING,Section.CANCELLATION),"Inventario y catálogo" to listOf(Section.SERIES,Section.STOCK,Section.PRODUCTS,Section.WAREHOUSES),"Personas y configuración" to listOf(Section.CUSTOMERS,Section.SUPPLIERS,Section.USERS,Section.MARKETPLACES,Section.DEV))
    LazyVerticalGrid(GridCells.Adaptive(155.dp),Modifier.fillMaxSize(),contentPadding=PaddingValues(18.dp),horizontalArrangement=Arrangement.spacedBy(12.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        item(span={GridItemSpan(maxLineSpan)}){
            Box(Modifier.fillMaxWidth().background(Brush.linearGradient(listOf(Ink,Color(0xFF294F83))),RoundedCornerShape(22.dp))){
                Column(Modifier.padding(22.dp)){
                    Text("ESPACIO DE TRABAJO",style=MaterialTheme.typography.labelSmall,color=Color(0xFFB7CDF2))
                    Text("Hola, ${user.name.substringBefore(' ')}",Modifier.padding(top=10.dp),style=MaterialTheme.typography.headlineMedium,color=Color.White)
                    Text("Todo listo para tu día.\nElige un módulo para comenzar.",Modifier.padding(top=8.dp),style=MaterialTheme.typography.bodyMedium,color=Color(0xFFD7E5FB))
                }
            }
        }
        groups.forEach{(title,sections)->
            val visible=sections.filter{user.visible(it)}
            if(visible.isNotEmpty()){
                item(span={GridItemSpan(maxLineSpan)}){Text(title,Modifier.padding(top=10.dp,bottom=2.dp),style=MaterialTheme.typography.titleMedium)}
                items(visible){section->
                    val accent=sectionColor(section)
                    Card(onClick={vm.open(section)},shape=MaterialTheme.shapes.large,border=BorderStroke(1.dp,Line),elevation=CardDefaults.cardElevation(1.dp),colors=CardDefaults.cardColors(containerColor=Color.White)){
                        Column(Modifier.fillMaxWidth().heightIn(min=174.dp).padding(16.dp)){
                            Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.SpaceBetween){
                                Surface(color=accent.copy(alpha=.11f),shape=RoundedCornerShape(12.dp)){Icon(sectionIcon(section),null,tint=accent,modifier=Modifier.padding(10.dp).size(25.dp))}
                                Icon(Icons.Rounded.ChevronRight,null,tint=Muted,modifier=Modifier.size(20.dp))
                            }
                            Text(section.title,Modifier.padding(top=14.dp),style=MaterialTheme.typography.titleMedium)
                            Text(section.subtitle,Modifier.padding(top=4.dp),style=MaterialTheme.typography.bodySmall,color=Muted)
                        }
                    }
                }
            }
        }
    }
}

@Composable private fun Listing(state:UiState,vm:CrmViewModel){val p=state.page;var ids by remember{mutableStateOf("")};var saleId by remember{mutableStateOf("")}
    val focus=LocalFocusManager.current
    val keyboard=LocalSoftwareKeyboardController.current
    val search:()->Unit={focus.clearFocus();keyboard?.hide();vm.filter("page",1);vm.search()}
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp)){
        item{
            PageIntro(if(p.section==Section.DOCUMENTS)"Buscar documentos" else p.section.title,p.section.subtitle,sectionIcon(p.section))
            if(p.section in setOf(Section.TICKETS,Section.PRODUCTS,Section.CUSTOMERS,Section.SUPPLIERS,Section.USERS,Section.MARKETPLACES,Section.WAREHOUSES,Section.SALES))Button(onClick={vm.edit()},shape=MaterialTheme.shapes.medium,enabled=!state.busy,modifier=Modifier.fillMaxWidth().padding(bottom=12.dp).heightIn(min=54.dp)){Icon(Icons.Rounded.Add,null);Text(if(p.section==Section.TICKETS)"Crear ticket" else if(p.section==Section.SALES)"Crear venta" else "Crear nuevo",Modifier.padding(start=8.dp))}
            if(p.section==Section.SALES){
                Row(Modifier.fillMaxWidth().padding(bottom=10.dp),verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(8.dp)){OutlinedTextField(saleId,{saleId=it},Modifier.weight(1f),colors=inputColors(),label={Text("Pedido a editar")},singleLine=true);FilledIconButton(onClick={vm.findSale(saleId)},shape=MaterialTheme.shapes.medium,modifier=Modifier.size(52.dp),enabled=!state.busy){Icon(Icons.Rounded.Edit,"Editar venta")}}
                ActionButton("Eliminar venta",Icons.Rounded.DeleteOutline,!state.busy,vm::cancelSale);SectionLabel("Pedidos pendientes")
            }
            Surface(color=Color.White,shape=MaterialTheme.shapes.large,border=BorderStroke(1.dp,Line),modifier=Modifier.fillMaxWidth()){
            Column(Modifier.padding(16.dp)){
            if(p.section==Section.DOCUMENTS)FormField(Field("campo","Buscar por",FieldKind.SELECT,choices=documentCriteria.map{Choice(it.first,it.second)}),p.filters,vm::filter)
            if(p.section==Section.TICKETS){
                val options=choices("" to "Mis tickets / historial","resuelto" to "Resueltos","cerrado" to "Cerrados")+if(state.user?.admin==true)choices("nuevo" to "Pendientes de asignación","resolucion" to "Pendientes de resolución")else emptyList()
                FormField(Field("estado","Bandeja",FieldKind.SELECT,choices=options),p.filters){k,v->vm.filter(k,v);vm.search()}
            }
            if(p.section in setOf(Section.PRODUCTS,Section.CUSTOMERS,Section.STOCK)){
                val companies=p.catalog.list("empresas").ifEmpty{state.user?.profile?.list("empresas")?:emptyList()}
                if(companies.isNotEmpty())FormField(Field("empresa","Empresa",FieldKind.SELECT,choices=companies.options()),p.filters){k,v->vm.filter(k,v);vm.filter("almacen",0)}
                if(p.section==Section.STOCK){val warehouses=p.catalog.list("empresas").find{it.s("id")==p.filters.s("empresa")}.objectOrEmpty().list("almacenes");FormField(Field("almacen","Almacén",FieldKind.SELECT,choices=listOf(Choice("0","Todos"))+warehouses.options()),p.filters,vm::filter);FormField(Field("con_existencia","Sólo con existencia",FieldKind.BOOL),p.filters,vm::filter);FormField(Field("etiquetas","Etiquetas, separadas por coma"),p.filters,vm::filter)}
            }
            if(p.section in setOf(Section.SALES,Section.BILLING))FormField(Field("fulfillment","Operación",FieldKind.SELECT,choices=(if(p.section==Section.SALES)listOf(Choice("","Todas"))else emptyList())+choices("0" to "Drop","1" to "Full")),p.filters){k,v->vm.filter(k,v);vm.filter("page",1);vm.search()}
            if(p.section==Section.BILLING){
                ActionButton("Cancelar factura Nexfira",Icons.Rounded.Cancel,!state.busy,vm::invoiceCancellation)
                FormField(Field("document_type","Tipo de documento",FieldKind.SELECT,choices=choices("2" to "Ventas","6" to "Notas de crédito")),p.filters){k,v->vm.filter(k,v);vm.filter("page",1);vm.search()}
                if(p.catalog.has("configured")&&!p.catalog.flag("configured"))Notice("El timbrado aún no está configurado en el servidor.")
                DetailGroup("Selección por números de pedido"){OutlinedTextField(ids,{ids=it},Modifier.fillMaxWidth(),colors=inputColors(),label={Text("Ej. 37802, 37803")});ActionButton("Agregar a selección",Icons.Rounded.Add,!state.busy){vm.billingIds(ids)}}
                if(p.selected.isNotEmpty()){Notice("${p.selected.size} documentos seleccionados");FlowRow(horizontalArrangement=Arrangement.spacedBy(8.dp)){if(p.filters.n("document_type")!=6)Button({vm.billingForm("global")},enabled=!state.busy){Text("Factura global")};OutlinedButton({vm.billingForm("external")},enabled=!state.busy){Text("CFDI externo")}}}
            }
            if(p.section!=Section.TICKETS){OutlinedTextField(p.filters.s("criterio"),{vm.filter("criterio",it)},Modifier.fillMaxWidth().padding(top=8.dp),colors=inputColors(),label={Text(if(p.section==Section.SERIES)"Número de serie" else "Buscar",style=MaterialTheme.typography.labelLarge)},placeholder={Text(if(p.section==Section.SERIES)"Escribe la serie" else "Escribe tu búsqueda")},singleLine=true,keyboardOptions=KeyboardOptions(imeAction=ImeAction.Search),keyboardActions=KeyboardActions(onSearch={if(!state.busy)search()}),leadingIcon={Icon(Icons.Rounded.Search,null)});Button(search,shape=MaterialTheme.shapes.medium,enabled=!state.busy,modifier=Modifier.fillMaxWidth().padding(top=14.dp).heightIn(min=52.dp)){Icon(Icons.Rounded.Search,null,Modifier.size(20.dp));Spacer(Modifier.width(8.dp));Text("Buscar")}}
            }}
            if(p.section==Section.STOCK&&p.rows.isNotEmpty())ActionButton("Exportar existencias a Excel",Icons.Rounded.Download,!state.busy,vm::exportStock)
            if(p.loaded)SectionLabel("${if(p.section==Section.BILLING)p.catalog.s("pagination.total") else p.rows.size} resultados")
        }
        if(p.rows.isEmpty()&&!state.busy)item{EmptyState(if(p.loaded)"Sin resultados" else "Comienza una búsqueda",if(p.loaded)"Prueba con otro criterio o filtro." else "Los resultados aparecerán aquí.")}
        items(p.rows){row->RecordCard(p.section,row,{if(p.section==Section.WAREHOUSES)vm.edit(row)else vm.show(row)},p.selected.any{it.s("id")==row.s("id")},if(p.section==Section.BILLING){{vm.selectBilling(row)}}else null){
            if(p.section==Section.BILLING){val active=row.flag("request.is_active")||row.s("request.status")=="uncertain";if(row.s("request.id").isNotBlank())TextButton({vm.syncRequest(row.s("request.id"))},enabled=!state.busy){Text("Actualizar estado")};if(active)Text("Solicitud en proceso · ${row.s("request.status")}",color=Teal,style=MaterialTheme.typography.bodySmall);if(!row.flag("can_hub"))Text("Sin timbrado individual disponible",style=MaterialTheme.typography.bodySmall)}
        }}
        if(p.section==Section.BILLING)item{Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){TextButton({vm.filter("page",p.filters.n("page")-1);vm.search()},enabled=!state.busy&&p.filters.n("page")>1){Text("Anterior")};Text("${p.filters.n("page")} / ${p.catalog.n("pagination.last_page").coerceAtLeast(1)}",Modifier.padding(top=16.dp));TextButton({vm.filter("page",p.filters.n("page")+1);vm.search()},enabled=!state.busy&&p.filters.n("page")<p.catalog.n("pagination.last_page")){Text("Siguiente")}}}
    }
}

@Composable private fun Detail(state:UiState,vm:CrmViewModel){val p=state.page;val d=p.data
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)){
        if(p.section==Section.DOCUMENTS){
            Surface(color=Color.White,shape=MaterialTheme.shapes.large,border=BorderStroke(1.dp,Line),modifier=Modifier.fillMaxWidth()){
                Column(Modifier.padding(20.dp)){
                    Row(verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(8.dp)){Icon(Icons.Rounded.Description,null,Modifier.size(20.dp),tint=Blue);Text("DOCUMENTO DE VENTA",style=MaterialTheme.typography.labelSmall,color=Blue)}
                    Text("${if(d.s("_kind")=="credit")"Nota de crédito" else "Pedido"} #${d.s("id")}",Modifier.padding(top=12.dp),style=MaterialTheme.typography.headlineSmall)
                    Text(d.label("cliente","razon_social"),Modifier.padding(top=8.dp),style=MaterialTheme.typography.bodyMedium,color=Muted)
                    if(d.s("total").isNotBlank()){
                        HorizontalDivider(Modifier.padding(vertical=14.dp),color=Line)
                        Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically){Text("Importe total",Modifier.weight(1f),style=MaterialTheme.typography.labelLarge,color=Muted);Text(amount(d.s("total")),style=MaterialTheme.typography.titleLarge,color=Teal)}
                    }
                }
            }
            SectionLabel("Acciones disponibles")
            if(d.s("_kind")=="credit")ActionButton("Descargar nota de crédito",Icons.Rounded.Download,!state.busy){vm.download("general/busqueda/venta/descargarNota/${segment(d.s("id"))}")}
            else {
                ActionButton("Agregar seguimiento o archivo",Icons.Rounded.EditNote,!state.busy,vm::followup)
                if(d.s("marketplace")=="MERCADOLIBRE")ActionButton("Más información del marketplace",enabled=!state.busy,click=vm::marketplaceInfo)
                if(state.user?.permission(11,36)==true&&d.n("id_fase") in setOf(5,6))ActionButton(if(d.n("id_fase")==6)"Refacturación / cliente fiscal" else "Editar cliente fiscal",enabled=!state.busy,click=vm::fiscal)
                if(d.s("uuid").isNotBlank())FlowRow(horizontalArrangement=Arrangement.spacedBy(8.dp)){listOf("pdf","xml").forEach{type->OutlinedButton({vm.download("venta/venta/descargar-pdf-xml/$type/${segment(d.s("id"))}")},enabled=!state.busy){Text("Descargar ${type.uppercase()}")}}}
                if(d.n("nota_de_credito.id")>0)ActionButton("Ver nota de crédito #${d.s("nota_de_credito.id")}",enabled=!state.busy){vm.show(obj("id" to d.s("nota_de_credito.id"),"_kind" to "credit"))}
                if(d.n("garantia_devolucion.id")>0)ActionButton("Descargar garantía / devolución",Icons.Rounded.Download,!state.busy){vm.download("general/busqueda/venta/descargarGarantia/${segment(d.s("garantia_devolucion.id"))}")}
            }
        }
        if(p.section==Section.TICKETS){
            PageIntro(d.s("titulo"),"Ticket de soporte",Icons.Rounded.SupportAgent);StatusBadge(d.s("estado"));SectionLabel("Acciones disponibles")
            if(state.user?.admin==true&&d.s("estado")=="nuevo")ActionButton("Asignar técnico",Icons.Rounded.PersonAdd,!state.busy,vm::ticketAssign)
            if(d.n("asignado_a")==state.user?.id&&d.s("estado")=="asignado")ActionButton("Iniciar revisión",Icons.Rounded.PlayArrow,!state.busy,vm::startTicket)
            if(d.n("asignado_a")==state.user?.id&&d.s("estado")=="en_revision")ActionButton("Resolver ticket",Icons.Rounded.CheckCircle,!state.busy,vm::ticketResolve)
        }
        if(p.section==Section.SERIES)ActionButton("Imprimir etiqueta de serie",Icons.Rounded.Print,!state.busy,vm::printSeries)
        if(p.section==Section.WAREHOUSES)ActionButton("Eliminar almacén",Icons.Rounded.DeleteOutline,!state.busy,vm::deleteEntity)
        if(p.section==Section.STOCK){ActionButton("Movimientos / kardex y Excel",Icons.AutoMirrored.Rounded.List,!state.busy,vm::stockMovements);ActionButton("Últimos movimientos y antigüedad",enabled=!state.busy,click=vm::lastMovements);ActionButton("Consultar sinónimos",enabled=!state.busy,click=vm::synonyms);if(state.user?.admin==true)ActionButton("Previsualizar cálculo de costo",enabled=!state.busy){vm.recalcCost()}}
        SectionLabel("Detalle");if(p.section==Section.DOCUMENTS)DataDetails(documentDisplay(d))else DataDetails(d)
        AttachmentList(d.list("archivos")+d.list("imagenes")+d.list("archivos_factura"),vm,canDelete=p.section==Section.DOCUMENTS&&d.s("_kind")!="credit")
        Spacer(Modifier.height(24.dp))
    }
}

@Composable private fun EntityForm(state:UiState,vm:CrmViewModel,onFile:(String)->Unit){val p=state.page
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(16.dp)){
        PageIntro(if(p.draft.n("id")>0)"Editar información" else "Nuevo registro","Los campos con * son obligatorios.",sectionIcon(p.section))
        if(p.section==Section.MARKETPLACES&&p.draft.n("api.id")>0&&!state.unlocked)ActionButton("Autorizar acceso a credenciales",Icons.Rounded.Lock,!state.busy,vm::unlockMarketplace)
        FormFields(entityFields(p.section,p.catalog,p.draft,state.unlocked),p.draft,vm::change)
        if(p.section==Section.PRODUCTS){
            ActionButton("Buscar clave SAT",Icons.Rounded.Search,!state.busy){vm.lookup("sat")}
            SectionLabel("Proveedores")
            val providers=p.draft.list("proveedores").ifEmpty{p.catalog.list("proveedores")}
            providers.forEach{provider->Card(onClick={vm.productProvider(provider)},Modifier.fillMaxWidth().padding(vertical=5.dp),colors=CardDefaults.cardColors(containerColor=Color.White),border=BorderStroke(1.dp,Line)){Row(Modifier.padding(16.dp),verticalAlignment=Alignment.CenterVertically){Column(Modifier.weight(1f)){Text(provider.label("razon_social","nombre"),style=MaterialTheme.typography.titleSmall);Text(if(provider.s("producto").isBlank())"Sin producto vinculado" else "Producto vinculado: ${provider.s("producto")}",Modifier.padding(top=5.dp),style=MaterialTheme.typography.bodySmall,color=Muted)};Icon(Icons.Rounded.ChevronRight,null,tint=Blue)}}}
            SectionLabel("Precios por archivo")
            ActionButton("Cargar Excel de códigos y precios",Icons.Rounded.UploadFile,!state.busy){onFile("prices")}
            p.draft.list("precio.productos").forEach{Text("${it.s("codigo")} · ${amount(it.s("precio"))}",Modifier.padding(vertical=5.dp))}
            SectionLabel("Imágenes")
            AttachmentList(p.draft.list("imagenes_anteriores"),vm,true)
            LocalAttachments(p.draft,"imagenes",vm)
            ActionButton("Agregar imagen",Icons.Rounded.AddPhotoAlternate,!state.busy){onFile("image")}
        }
        if(p.section==Section.TICKETS){SectionLabel("Adjuntos");LocalAttachments(p.draft,"archivos",vm);ActionButton("Adjuntar archivo",Icons.Rounded.AttachFile,!state.busy){onFile("")}}
        if(p.draft.n("id")>0&&p.section in setOf(Section.USERS,Section.WAREHOUSES))ActionButton(if(p.section==Section.USERS)"Desactivar usuario" else "Eliminar almacén",Icons.Rounded.DeleteOutline,!state.busy,vm::deleteEntity)
        Spacer(Modifier.height(24.dp))
    }
}

@Composable private fun ActionForm(state:UiState,vm:CrmViewModel,onFile:(String)->Unit){val p=state.page
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(16.dp)){
        if(p.action=="line"){Text(p.draft.s("descripcion"),style=MaterialTheme.typography.titleLarge);Text(p.draft.s("codigo"),color=Teal)}
        if(p.action=="unlock-marketplace"||(p.action=="refacturacion"&&p.fields.any{it.key=="token"}))ActionButton("Solicitar código de autorización",Icons.Rounded.Lock,!state.busy,vm::prepareCode)
        if(p.action=="refacturacion"){Notice("Revisa el receptor fiscal. La refacturación genera documentos vinculados que se timbran desde Facturación.");DataDetails(p.data)}
        FormFields(p.fields,p.draft,vm::change)
        if(p.action=="invoice-cancel"){
            ActionButton("Buscar y ver factura",Icons.Rounded.Search,!state.busy,vm::previewInvoiceCancellation)
            if(p.data.has("factura")){
                DetailGroup("Factura",true){DataDetails(p.data.o("factura"))}
                p.data.list("ventas").forEach{sale->DetailGroup("Pedido #${sale.s("id")}",true){DataDetails(sale.deepCopy().apply{remove("pdf");remove("xml")})}}
                if(p.data.has("cancelacion")&&p.data.at("cancelacion")?.isJsonNull==false){DetailGroup("Cancelación",true){DataDetails(p.data.o("cancelacion"))};ActionButton("Actualizar aprobación",Icons.Rounded.Refresh,!state.busy,vm::refreshInvoiceCancellation)
                    if(state.user?.permission(6,1)==true&&p.data.s("cancelacion.status")=="pending_approval")ActionButton("DEV · Simular aprobación",Icons.Rounded.Check,!state.busy,vm::simulateInvoiceCancellation)}
            }
        }
        if(p.action=="dev-nexfira"){
            ActionButton("Revisar intento",Icons.Rounded.Search,!state.busy,vm::inspectDevNexfira)
            if(p.data.has("request_id")){
                DetailGroup("Intento local",true){DataDetails(p.data)}
                if(p.data.flag("can_manual_reset")){
                    Notice("Nexfira aún no confirma el rechazo. Un nuevo envío podría duplicar la factura.")
                    FormField(Field("rejected_confirmed","Nexfira confirmó que el intento fue rechazado",FieldKind.BOOL),p.draft,vm::change)
                    FormField(Field("duplicate_risk_accepted","Acepto el riesgo de duplicidad",FieldKind.BOOL),p.draft,vm::change)
                    FormField(Field("manual_confirmation","Escribe LIBERAR ${p.draft.s("documento")}"),p.draft,vm::change)
                } else if(!p.data.flag("can_reset"))Notice("Este intento no puede liberarse. Revisa sus bloqueos y el estado en Nexfira.")
                if(p.data.flag("can_reset")||p.data.flag("can_manual_reset"))FormField(Field("confirmation","Escribe LIBERAR ${p.draft.s("documento")}"),p.draft,vm::change)
            }
        }
        if(p.action=="cancel-sale")ActionButton("Solicitar autorización",Icons.Rounded.Lock,!state.busy,vm::prepareCancel)
        if(p.action=="provider")ActionButton("Buscar productos del proveedor",Icons.Rounded.Search,!state.busy,vm::searchProvider)
        if(p.action=="followup"){LocalAttachments(p.draft,"archivos",vm);ActionButton("Adjuntar archivo",Icons.Rounded.AttachFile,!state.busy){onFile("")}}
    }
}

@Composable private fun SaleForm(state:UiState,vm:CrmViewModel,onFile:(String)->Unit){val p=state.page;val steps=listOf("Pedido","Cliente","Productos","Envío","Condiciones","Revisión")
    Column(Modifier.fillMaxSize()){
        ScrollableTabRow(p.step,edgePadding=8.dp,containerColor=Color.White,contentColor=Blue){steps.forEachIndexed{i,title->Tab(p.step==i,{vm.saleStep(i)},selectedContentColor=Blue,unselectedContentColor=Muted,text={Text(title,style=MaterialTheme.typography.labelLarge)},icon={Surface(color=if(p.step==i)Blue else Canvas,shape=RoundedCornerShape(10.dp)){Box(Modifier.size(30.dp),contentAlignment=Alignment.Center){Text("${i+1}",color=if(p.step==i)Color.White else Muted,style=MaterialTheme.typography.labelLarge)}}})}}
        key(p.step){Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp)){
            Text("PASO ${p.step+1} DE 6",style=MaterialTheme.typography.labelSmall,color=Blue)
            Text(steps[p.step],Modifier.padding(top=4.dp,bottom=12.dp),style=MaterialTheme.typography.headlineSmall)
            if(p.step==1&&(p.draft.n("documento.documento")==0||p.draft.n("documento.id_fase")<5))ActionButton("Buscar cliente",Icons.Rounded.PersonSearch,!state.busy){vm.lookup("client")}
            FormFields(saleFields(p.step,p.catalog,p.draft),p.draft,vm::change)
            when(p.step){
                0->{if(p.draft.s("documento.marketplace")=="1"&&p.draft.n("documento.documento")==0)ActionButton("Consultar pedido de Mercado Libre",Icons.Rounded.CloudDownload,!state.busy,vm::marketplaceInfo);if(p.draft.flag("documento.pedido"))ActionButton("Convertir pedido a venta",Icons.Rounded.CheckCircle,!state.busy,vm::convertOrder)}
                2->{
                    val editable=p.draft.n("documento.documento")==0||p.draft.flag("documento.editar_productos")
                    if(editable)ActionButton("Agregar producto",Icons.Rounded.Add,!state.busy){vm.lookup("product")}
                    p.draft.list("documento.productos").forEachIndexed{i,line->Card(Modifier.fillMaxWidth().padding(vertical=6.dp),colors=CardDefaults.cardColors(containerColor=Color.White),border=BorderStroke(1.dp,Line)){Column(Modifier.padding(16.dp)){Text(line.s("codigo"),style=MaterialTheme.typography.labelMedium,color=Blue);Text(line.s("descripcion"),Modifier.padding(top=6.dp),style=MaterialTheme.typography.titleMedium);Text("${line.s("cantidad")} × ${amount(line.s("precio"))}",Modifier.padding(top=8.dp),color=Muted);if(editable)Row(horizontalArrangement=Arrangement.spacedBy(10.dp),modifier=Modifier.padding(top=10.dp)){FilledTonalButton({vm.editLine(i)},shape=MaterialTheme.shapes.small){Icon(Icons.Rounded.Edit,null,Modifier.size(18.dp));Spacer(Modifier.width(8.dp));Text("Editar")};OutlinedButton({vm.removeLine(i)},shape=MaterialTheme.shapes.small,colors=ButtonDefaults.outlinedButtonColors(contentColor=MaterialTheme.colorScheme.error)){Text("Quitar")}}}}}
                    p.catalog.list("promociones").forEach{promotion->ActionButton("Agregar promoción · ${promotion.label("nombre","descripcion")}",enabled=!state.busy){vm.addPromotion(promotion)}}
                    val total=p.draft.list("documento.productos").fold(java.math.BigDecimal.ZERO){s,l->s+l.money("cantidad")*l.money("precio")}
                    Surface(color=Ink,shape=MaterialTheme.shapes.large,modifier=Modifier.fillMaxWidth().padding(vertical=16.dp)){Column(Modifier.padding(20.dp)){Text("TOTAL DE LA VENTA",style=MaterialTheme.typography.labelSmall,color=Color(0xFFBECEEA));Text(amount(total.toPlainString()),Modifier.padding(top=6.dp),style=MaterialTheme.typography.headlineMedium,color=Color.White)}}
                }
                3->{ActionButton("Consultar código postal",Icons.Rounded.LocationOn,!state.busy,vm::postal);if(p.draft.n("documento.documento")>0&&p.draft.flag("documento.editar_envio"))ActionButton("Cotizar envío",Icons.Rounded.LocalShipping,!state.busy,vm::quoteShipping)}
                5->{
                    SectionLabel("Adjuntos")
                    FormField(Field("guia","Tipo de archivo",FieldKind.SELECT,choices=choices("1" to "Información","2" to "Guía de envío")),p.draft,vm::change)
                    FormField(Field("impresora","Impresora de la guía",FieldKind.SELECT,choices=p.catalog.list("impresoras").options()),p.draft,vm::change)
                    LocalAttachments(p.draft,"documento.archivos",vm);ActionButton("Adjuntar archivo",Icons.Rounded.AttachFile,!state.busy){onFile("")}
                    if(p.draft.list("_previousFollowup").isNotEmpty())DetailGroup("Seguimiento anterior"){p.draft.list("_previousFollowup").forEach{DataDetails(it)}}
                    AttachmentList(p.draft.list("_previousAttachments"),vm)
                    SectionLabel("Revisa antes de guardar");DataDetails(p.draft.o("cliente"));DataDetails(p.draft.o("documento"))
                }
            }
            if(p.step==0&&p.draft.n("documento.documento")>0){
                val market=p.catalog.list("areas").flatMap{it.list("marketplaces")}.find{it.s("id")==p.draft.s("documento.marketplace")}.objectOrEmpty()
                if(market.s("marketplace").startsWith("MERCADOLIBRE",true))ActionButton("Información de Mercado Libre",enabled=!state.busy,click=vm::marketplaceInfo)
                if(p.draft.n("documento.id_fase")>=6)FormField(Field("documento.refacturacion","Solicitar refacturación",FieldKind.BOOL),p.draft,vm::change)
            }
        }}
    }
}

@Composable private fun BillingForm(state:UiState,vm:CrmViewModel,onFile:(String)->Unit){val p=state.page;val credit=p.data.n("document_type")==6
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(16.dp)){
        PageIntro("${p.selected.size} ${if(credit)if(p.selected.size==1)"nota de crédito" else "notas de crédito" else if(p.selected.size==1)"venta" else "ventas"}",p.selected.joinToString{"#${it.s("id")}"},Icons.Rounded.ReceiptLong)
        if(p.action=="external"){
            Notice("Selecciona el XML timbrado y su PDF. El UUID se obtiene del XML y el servidor valida la relación con los documentos.")
            listOf("xml","pdf").forEach{type->ActionButton("${if(p.draft.s(type).isBlank())"Adjuntar" else "Cambiar"} ${type.uppercase()}",Icons.Rounded.AttachFile,!state.busy){onFile(type)};if(p.draft.s("_${type}Name").isNotBlank())Text(p.draft.s("_${type}Name"),Modifier.padding(8.dp))}
            if(p.draft.s("uuid").isNotBlank()){SectionLabel("CFDI seleccionado");DataDetails(p.draft.o("_identity"))}
        }else{
            DetailGroup("Receptor fiscal",true){DataDetails(p.data.o("payload.content.receiver"))}
            if(!p.data.flag("valid"))Notice("El documento tiene bloqueos de facturación.",true)
            p.data.at("blockers").arrayOrEmpty().forEach{Notice(if(it.isJsonObject)it.asJsonObject.label("message","description","code")else it.text(),true)}
            if(p.action=="global")FormField(Field("agrupacion","Agrupar conceptos por",FieldKind.SELECT,choices=choices("ventas" to "Ventas","productos" to "Productos")),p.draft,vm::change)
            FormFields(billingFields(p.draft,p.data.s("payload.content.receiver.rfc").uppercase()=="XAXX010101000",credit),p.draft,vm::change)
            if(p.step==1){
                val content=p.data.o("payload.content")
                SectionLabel("Vista previa de factura")
                Notice("Serie ${p.draft.s("series")} · Folio ${p.draft.s("folio").ifBlank{"automático"}} · Pago ${content.s("paymentMethod")} / ${content.s("paymentForm")}")
                DetailGroup("Comprobante",true){DataDetails(obj("emisor" to p.data.s("payload.issuerId"),"emision" to content.s("issuedAtLocal"),"moneda" to content.s("currency"),"codigo_postal_expedicion" to content.s("expeditionPostalCode"),"uso_cfdi" to content.s("receiver.cfdiUse"),"regimen_receptor" to content.s("receiver.fiscalRegime")))}
                if(content.has("globalInformation"))DetailGroup("Periodo global",true){DataDetails(content.o("globalInformation"))}
                content.list("items").forEach{item->DetailGroup(item.s("description"),true){DataDetails(item)}}
                DetailGroup("Totales",true){DataDetails(content.o("expectedTotals"))}
                if(!credit)Notice("Precio y descuento incluyen IVA. Guardar actualiza el pedido; después vuelve a revisar la factura.")
                (if(credit)emptyList() else p.data.list("editable_lines")).forEach{line->
                    DetailGroup("Pedido #${line.s("id_documento")} · ${line.s("descripcion")}",true){
                        Text("Cantidad ${line.s("cantidad")}")
                        FormField(Field("lineEdits.${line.s("id")}.precio","Precio",FieldKind.NUMBER),p.draft,vm::change)
                        FormField(Field("lineEdits.${line.s("id")}.descuento","Descuento",FieldKind.NUMBER),p.draft,vm::change)
                        ActionButton("Guardar importe",Icons.Rounded.Check,!state.busy){vm.saveBillingLine(line)}
                        TextButton({vm.sale(line.s("id_documento"))},enabled=!state.busy){Text("Editar cliente y otros datos del pedido")}
                    }
                }
                ActionButton("Actualizar vista previa",Icons.Rounded.Refresh,!state.busy,vm::reviewBilling)
            }
            Notice("La solicitud permanece pendiente hasta que el servidor recupere el UUID, XML y PDF.")
        }
    }
}

@Composable private fun Lookup(state:UiState,vm:CrmViewModel){val p=state.page
    val focus=LocalFocusManager.current
    val keyboard=LocalSoftwareKeyboardController.current
    val search:()->Unit={focus.clearFocus();keyboard?.hide();vm.searchLookup()}
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp)){
        item{OutlinedTextField(p.filters.s("criterio"),{vm.filter("criterio",it)},Modifier.fillMaxWidth(),colors=inputColors(),leadingIcon={Icon(Icons.Rounded.Search,null)},label={Text("Buscar")},singleLine=true,keyboardOptions=KeyboardOptions(imeAction=ImeAction.Search),keyboardActions=KeyboardActions(onSearch={if(!state.busy)search()}));Button(search,shape=MaterialTheme.shapes.medium,enabled=!state.busy,modifier=Modifier.fillMaxWidth().padding(vertical=12.dp).heightIn(min=52.dp)){Text("Buscar")}}
        items(p.rows){row->RecordCard(if(p.action=="client")Section.CUSTOMERS else Section.PRODUCTS,row,{vm.show(row)})}
        if(p.loaded&&p.rows.isEmpty())item{EmptyState("Sin resultados","Prueba otro código o descripción.")}
    }
}
@Composable private fun InfoScreen(state:UiState,vm:CrmViewModel){val p=state.page
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)){
        if(p.action=="dev-home"){
            PageIntro("Herramientas Dev","Acciones para administradores",Icons.Rounded.Build)
            ActionButton("Conciliar entidades",Icons.Rounded.People,!state.busy){vm.openMerge("entidades")}
            ActionButton("Conciliar productos",Icons.Rounded.Category,!state.busy){vm.openMerge("productos")}
            ActionButton("Liberar intento Nexfira",Icons.Rounded.LockOpen,!state.busy,vm::devNexfira)
            ActionButton("Simular aprobación Nexfira",Icons.Rounded.CheckCircle,!state.busy,vm::invoiceCancellation)
        }
        if(p.action=="import-marketplace")p.data.list("venta").forEach{order->DetailGroup("Venta ${order.s("id")}",true){DataDetails(order);ActionButton("Usar esta venta",Icons.Rounded.Check,!state.busy){vm.importMarketplace(order)}}}
        if(p.action=="shipping-quotes"){if(p.rows.isEmpty())EmptyState("Sin cotizaciones","No hay servicios disponibles para este envío.");p.rows.forEach{rate->OutlinedCard(onClick={vm.chooseShipping(rate)},Modifier.fillMaxWidth().padding(vertical=6.dp)){Column(Modifier.padding(16.dp)){Text(rate.s("paqueteria"),style=MaterialTheme.typography.titleMedium);DataDetails(rate);Text("Seleccionar servicio",color=Teal)}}}}
        if(p.action!="import-marketplace"&&p.action!="dev-home")DataDetails(p.data)
        if(p.data.s("request.id").isNotBlank())ActionButton("Actualizar estado de timbrado",Icons.Rounded.Refresh,!state.busy){vm.syncRequest(p.data.s("request.id"))}
        val refact=if(p.data.has("resultado"))p.data.o("resultado")else p.data
        if(state.user?.permission(11,36)==true){
            if(refact.n("nota_credito")>0)ActionButton("Facturación de nota #${refact.s("nota_credito")}",enabled=!state.busy){vm.billingChild(refact.s("nota_credito"),6)}
            if(refact.n("documento_nuevo")>0)ActionButton("Facturación del nuevo pedido #${refact.s("documento_nuevo")}",enabled=!state.busy){vm.billingChild(refact.s("documento_nuevo"),2)}
        }
        if(p.action=="recalc")ActionButton("Aplicar costo calculado",Icons.Rounded.Check,!state.busy){vm.recalcCost(true)}
    }
}
@Composable private fun MergeScreen(state:UiState,vm:CrmViewModel){val p=state.page;val draft=p.draft;val review=p.data
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(16.dp)){
        Notice("Selecciona el registro que se conserva y el duplicado que se elimina. Revisa sus referencias antes de aplicar.")
        listOf("keep" to "Se conserva","remove" to "Se elimina").forEach{(slot,label)->
            SectionLabel(label)
            FormField(Field("${slot}Query","ID, nombre, RFC o SKU"),draft,vm::change)
            ActionButton("Buscar $label",Icons.Rounded.Search,!state.busy){vm.mergeSearch(slot)}
            p.catalog.list("${slot}Results").forEach{row->
                val selected=draft.n("${slot}Id")==row.n("id")
                OutlinedCard(onClick={vm.mergeSelect(slot,row.n("id"))},Modifier.fillMaxWidth().padding(vertical=5.dp),
                    border=BorderStroke(if(selected)2.dp else 1.dp,if(selected)Blue else Line)){
                    Column(Modifier.padding(14.dp)){Text("#${row.s("id")} · ${row.label("razon_social","descripcion")}",style=MaterialTheme.typography.titleSmall)
                        Text(row.label("rfc","sku"),color=Muted)}
                }
            }
        }
        ActionButton("Revisar referencias",Icons.Rounded.FactCheck,!state.busy,vm::mergeInspect)
        if(review.has("confirmation_token")){
            SectionLabel("Conservar #${review.s("keep.id")} · eliminar #${review.s("remove.id")}")
            if(draft.s("kind")=="entidades")Notice("El registro final será cliente y proveedor.")
            else Notice("Se reasignarán movimientos y series al modelo conservado. Los SKU anteriores quedarán como sinónimos.")
            review.list("blockers").forEach{Notice(it.text())}
            review.list("warnings").forEach{Notice(it.text())}
            DetailGroup("Referencias del duplicado",true){review.list("references").filter{it.n("count")>0}.forEach{ref->Text("${ref.s("table")}.${ref.s("column")}: ${ref.s("count")}")}}
            SectionLabel("Datos finales")
            FormFields(p.fields,draft,vm::change)
            if(review.list("blockers").isEmpty()){
                Notice("Escribe CONCILIAR ${review.s("remove.id")} para confirmar la eliminación del duplicado.")
                FormField(Field("confirmation","Confirmación"),draft,vm::change)
                ActionButton("Aplicar conciliación",Icons.Rounded.Check,!state.busy,vm::mergeApply)
            }
        }
    }
}
@Composable fun AttachmentList(files:List<JsonObject>,vm:CrmViewModel,canDelete:Boolean=false){if(files.isNotEmpty()){SectionLabel("Archivos");files.forEach{file->Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically){TextButton({vm.openAttachment(file)},Modifier.weight(1f)){Icon(Icons.Rounded.AttachFile,null);Text(file.label("nombre","archivo","tipo"),Modifier.padding(start=8.dp))};if(canDelete)IconButton({vm.removeAttachment(file)}){Icon(Icons.Rounded.DeleteOutline,"Eliminar archivo")}}}}}
@Composable private fun LocalAttachments(data:JsonObject,path:String,vm:CrmViewModel){data.list(path).forEachIndexed{i,file->Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically){Text(file.s("nombre"),Modifier.weight(1f));IconButton({vm.removeLocalFile(path,i)}){Icon(Icons.Rounded.Close,"Quitar archivo")}}}}
