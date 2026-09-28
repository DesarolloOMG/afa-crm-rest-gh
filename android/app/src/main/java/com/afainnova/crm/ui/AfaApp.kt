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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.*
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.*
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
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
    val activity=androidx.activity.compose.LocalActivity.current
    DisposableEffect(state.user==null,state.unlocked){if(state.user==null||state.unlocked)activity?.window?.addFlags(WindowManager.LayoutParams.FLAG_SECURE) else activity?.window?.clearFlags(WindowManager.LayoutParams.FLAG_SECURE);onDispose{activity?.window?.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)}}
    BackHandler(enabled=state.user!=null&&p.kind!=PageKind.HOME){vm.back()}
    val snack=remember{SnackbarHostState()}
    LaunchedEffect(state.notice){state.notice?.let{snack.showSnackbar(plain(it));vm.clearNotice()}}
    Scaffold(
        topBar={if(state.user!=null)TopAppBar(title={Column{Text(if(p.kind==PageKind.HOME)"AFA" else p.title,maxLines=2,style=MaterialTheme.typography.titleLarge);if(p.kind==PageKind.HOME)Text("CRM móvil",style=MaterialTheme.typography.labelMedium,color=Teal)}},navigationIcon={if(p.kind!=PageKind.HOME)IconButton(onClick=vm::back,enabled=!state.saving){Icon(Icons.AutoMirrored.Rounded.ArrowBack,"Volver")}},actions={if(p.kind==PageKind.HOME)IconButton(onClick=vm::logout){Icon(Icons.AutoMirrored.Rounded.Logout,"Cerrar sesión")};if(p.kind==PageKind.LIST)IconButton(onClick=vm::refresh,enabled=!state.busy){Icon(Icons.Rounded.Refresh,"Actualizar")}})},
        snackbarHost={SnackbarHost(snack)},
        bottomBar={if(state.user!=null)when(p.kind){
            PageKind.FORM,PageKind.ACTION,PageKind.BILL->Surface(shadowElevation=5.dp){Row(Modifier.navigationBarsPadding().imePadding().padding(12.dp).fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(12.dp)){OutlinedButton(vm::back,Modifier.weight(1f),enabled=!state.busy){Text("Cancelar")};Button(onClick={when(p.kind){PageKind.FORM->vm.saveEntity();PageKind.BILL->vm.sendBilling();else->vm.saveAction()}},Modifier.weight(1.3f),enabled=!state.busy){Text(if(p.kind==PageKind.BILL)if(p.action=="external")"Registrar CFDI" else "Solicitar timbrado" else if(p.kind==PageKind.ACTION)when(p.action){"kardex"->"Consultar";"print-series"->"Imprimir";"line"->"Aplicar";"provider"->"Vincular";"unlock-marketplace"->"Validar";"cancel-sale"->"Eliminar";else->"Guardar"}else "Guardar")}}}
            PageKind.SALE->Surface(shadowElevation=5.dp){Row(Modifier.navigationBarsPadding().imePadding().padding(12.dp).fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(12.dp)){OutlinedButton(onClick={if(p.step==0)vm.back() else vm.saleStep(p.step-1)},Modifier.weight(1f),enabled=!state.busy){Text(if(p.step==0)"Cancelar" else "Anterior")};Button(onClick={if(p.step==5)vm.saveSale() else vm.saleStep(p.step+1)},Modifier.weight(1f),enabled=!state.busy){Text(if(p.step==5)"Guardar venta" else "Continuar")}}}
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
        Button(onClick={vm.signIn(data.s("email"),data.s("password"),data.s("code"))},enabled=!state.busy,modifier=Modifier.fillMaxWidth().padding(top=20.dp).heightIn(min=52.dp)){Text(if(state.busy)"Validando…" else "Iniciar sesión")}
        TextButton(onClick={vm.resetPassword(data.s("email"),data.s("code"))},enabled=data.s("email").isNotBlank()&&!state.busy,modifier=Modifier.align(Alignment.CenterHorizontally)){Text("Recuperar contraseña")}
    }
}

fun sectionIcon(section:Section):ImageVector=when(section){Section.DOCUMENTS->Icons.Rounded.Description;Section.TICKETS->Icons.Rounded.SupportAgent;Section.SERIES->Icons.Rounded.QrCode;Section.STOCK->Icons.Rounded.Inventory2;Section.PRODUCTS->Icons.Rounded.Category;Section.CUSTOMERS->Icons.Rounded.People;Section.SUPPLIERS->Icons.Rounded.LocalShipping;Section.SALES->Icons.Rounded.ShoppingBag;Section.BILLING->Icons.Rounded.ReceiptLong;Section.USERS->Icons.Rounded.ManageAccounts;Section.MARKETPLACES->Icons.Rounded.Storefront;Section.WAREHOUSES->Icons.Rounded.Warehouse}
@Composable private fun Home(user:User,vm:CrmViewModel){LazyVerticalGrid(GridCells.Adaptive(160.dp),Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp),horizontalArrangement=Arrangement.spacedBy(12.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
    item(span={GridItemSpan(maxLineSpan)}){Column(Modifier.padding(vertical=16.dp)){Text("Hola, ${user.name.substringBefore(' ')}",style=MaterialTheme.typography.headlineMedium,fontWeight=FontWeight.Bold);Text("¿Qué necesitas hacer hoy?",Modifier.padding(top=8.dp),color=MaterialTheme.colorScheme.onSurfaceVariant)}}
    items(Section.entries.filter{user.visible(it)}){section->Card(onClick={vm.open(section)},colors=CardDefaults.cardColors(containerColor=MaterialTheme.colorScheme.surface)){Column(Modifier.fillMaxWidth().heightIn(min=156.dp).padding(18.dp)){Icon(sectionIcon(section),null,tint=Teal,modifier=Modifier.size(28.dp));Spacer(Modifier.height(18.dp));Text(section.title,style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.SemiBold);Text(section.subtitle,Modifier.padding(top=6.dp),style=MaterialTheme.typography.bodySmall,color=MaterialTheme.colorScheme.onSurfaceVariant)}}}
}}

@Composable private fun Listing(state:UiState,vm:CrmViewModel){val p=state.page;var ids by remember{mutableStateOf("")};var saleId by remember{mutableStateOf("")}
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp)){
        item{
            if(p.section in setOf(Section.TICKETS,Section.PRODUCTS,Section.CUSTOMERS,Section.SUPPLIERS,Section.USERS,Section.MARKETPLACES,Section.WAREHOUSES,Section.SALES))Button(onClick={vm.edit()},enabled=!state.busy,modifier=Modifier.fillMaxWidth().heightIn(min=48.dp)){Icon(Icons.Rounded.Add,null);Text(if(p.section==Section.TICKETS)"Crear ticket" else if(p.section==Section.SALES)"Crear venta" else "Crear nuevo",Modifier.padding(start=8.dp))}
            if(p.section==Section.SALES){
                Row(Modifier.fillMaxWidth().padding(top=12.dp),verticalAlignment=Alignment.CenterVertically){OutlinedTextField(saleId,{saleId=it},Modifier.weight(1f),label={Text("Pedido a editar")},singleLine=true);IconButton(onClick={vm.findSale(saleId)},enabled=!state.busy){Icon(Icons.Rounded.Edit,"Editar venta")}}
                ActionButton("Eliminar venta",Icons.Rounded.DeleteOutline,!state.busy,vm::cancelSale);SectionLabel("Pedidos pendientes")
            }
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
                FormField(Field("document_type","Tipo de documento",FieldKind.SELECT,choices=choices("2" to "Ventas","6" to "Notas de crédito")),p.filters){k,v->vm.filter(k,v);vm.filter("page",1);vm.search()}
                if(p.catalog.has("configured")&&!p.catalog.flag("configured"))Notice("El timbrado aún no está configurado en el servidor.")
                DetailGroup("Selección por números de pedido"){OutlinedTextField(ids,{ids=it},Modifier.fillMaxWidth(),label={Text("Ej. 37802, 37803")});TextButton({vm.billingIds(ids)},enabled=!state.busy){Text("Agregar a selección")}}
                if(p.selected.isNotEmpty()){Notice("${p.selected.size} documentos seleccionados");FlowRow(horizontalArrangement=Arrangement.spacedBy(8.dp)){if(p.filters.n("document_type")!=6)Button({vm.billingForm("global")},enabled=!state.busy){Text("Factura global")};OutlinedButton({vm.billingForm("external")},enabled=!state.busy){Text("CFDI externo")}}}
            }
            if(p.section!=Section.TICKETS){OutlinedTextField(p.filters.s("criterio"),{vm.filter("criterio",it)},Modifier.fillMaxWidth().padding(top=8.dp),label={Text(if(p.section==Section.SERIES)"Número de serie" else "Buscar")},singleLine=true,leadingIcon={Icon(Icons.Rounded.Search,null)});Button({vm.filter("page",1);vm.search()},enabled=!state.busy,modifier=Modifier.fillMaxWidth().padding(top=10.dp)){Text("Buscar")}}
            if(p.section==Section.STOCK&&p.rows.isNotEmpty())ActionButton("Exportar existencias a Excel",Icons.Rounded.Download,!state.busy,vm::exportStock)
            if(p.loaded)Text("${if(p.section==Section.BILLING)p.catalog.s("pagination.total") else p.rows.size} resultados",Modifier.padding(top=22.dp,bottom=10.dp),style=MaterialTheme.typography.labelLarge)
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
            Text("${if(d.s("_kind")=="credit")"Nota de crédito" else "Pedido"} #${d.s("id")}",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Bold)
            Text(d.label("cliente","razon_social"),Modifier.padding(vertical=10.dp),color=Teal)
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
            Text(d.s("titulo"),style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Bold);AssistChip({},label={Text(d.s("estado").replace('_',' '))})
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
        if(p.section==Section.MARKETPLACES&&p.draft.n("api.id")>0&&!state.unlocked)ActionButton("Autorizar acceso a credenciales",Icons.Rounded.Lock,!state.busy,vm::unlockMarketplace)
        FormFields(entityFields(p.section,p.catalog,p.draft,state.unlocked),p.draft,vm::change)
        if(p.section==Section.PRODUCTS){
            ActionButton("Buscar clave SAT",Icons.Rounded.Search,!state.busy){vm.lookup("sat")}
            SectionLabel("Proveedores")
            val providers=p.draft.list("proveedores").ifEmpty{p.catalog.list("proveedores")}
            providers.forEach{provider->OutlinedCard(onClick={vm.productProvider(provider)},Modifier.fillMaxWidth().padding(vertical=4.dp)){Column(Modifier.padding(14.dp)){Text(provider.label("razon_social","nombre"));Text(if(provider.s("producto").isBlank())"Sin producto vinculado" else "Producto vinculado: ${provider.s("producto")}",style=MaterialTheme.typography.bodySmall)}}}
            SectionLabel("Precios por archivo")
            ActionButton("Cargar Excel de códigos y precios",Icons.Rounded.UploadFile,!state.busy){onFile("prices")}
            p.draft.list("precio.productos").forEach{Text("${it.s("codigo")} · ${amount(it.s("precio"))}",Modifier.padding(vertical=5.dp))}
            SectionLabel("Imágenes")
            AttachmentList(p.draft.list("imagenes_anteriores"),vm,true)
            LocalAttachments(p.draft,"imagenes",vm)
            ActionButton("Agregar imagen",Icons.Rounded.AddPhotoAlternate,!state.busy){onFile("image")}
        }
        if(p.section==Section.TICKETS){SectionLabel("Adjuntos");LocalAttachments(p.draft,"archivos",vm);ActionButton("Adjuntar archivo",Icons.Rounded.AttachFile,!state.busy){onFile("")}}
        if(p.draft.n("id")>0&&p.section in setOf(Section.USERS,Section.WAREHOUSES))TextButton(vm::deleteEntity,enabled=!state.busy){Icon(Icons.Rounded.DeleteOutline,null);Text(if(p.section==Section.USERS)"Desactivar usuario" else "Eliminar almacén")}
        Spacer(Modifier.height(24.dp))
    }
}

@Composable private fun ActionForm(state:UiState,vm:CrmViewModel,onFile:(String)->Unit){val p=state.page
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(16.dp)){
        if(p.action=="line"){Text(p.draft.s("descripcion"),style=MaterialTheme.typography.titleLarge);Text(p.draft.s("codigo"),color=Teal)}
        if(p.action=="unlock-marketplace"||(p.action=="refacturacion"&&p.fields.any{it.key=="token"}))ActionButton("Solicitar código de autorización",Icons.Rounded.Lock,!state.busy,vm::prepareCode)
        if(p.action=="refacturacion"){Notice("Revisa el receptor fiscal. La refacturación genera documentos vinculados que se timbran desde Facturación.");DataDetails(p.data)}
        FormFields(p.fields,p.draft,vm::change)
        if(p.action=="cancel-sale")ActionButton("Solicitar autorización",Icons.Rounded.Lock,!state.busy,vm::prepareCancel)
        if(p.action=="provider")ActionButton("Buscar productos del proveedor",Icons.Rounded.Search,!state.busy,vm::searchProvider)
        if(p.action=="followup"){LocalAttachments(p.draft,"archivos",vm);ActionButton("Adjuntar archivo",Icons.Rounded.AttachFile,!state.busy){onFile("")}}
    }
}

@Composable private fun SaleForm(state:UiState,vm:CrmViewModel,onFile:(String)->Unit){val p=state.page;val steps=listOf("Pedido","Cliente","Productos","Envío","Condiciones","Revisión")
    Column(Modifier.fillMaxSize()){
        ScrollableTabRow(p.step,edgePadding=8.dp){steps.forEachIndexed{i,title->Tab(p.step==i,{vm.saleStep(i)},text={Text(title)})}}
        key(p.step){Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).imePadding().padding(16.dp)){
            Text("${p.step+1} de 6 · ${steps[p.step]}",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.SemiBold)
            if(p.step==1&&(p.draft.n("documento.documento")==0||p.draft.n("documento.id_fase")<5))ActionButton("Buscar cliente",Icons.Rounded.PersonSearch,!state.busy){vm.lookup("client")}
            FormFields(saleFields(p.step,p.catalog,p.draft),p.draft,vm::change)
            when(p.step){
                0->{if(p.draft.s("documento.marketplace")=="1"&&p.draft.n("documento.documento")==0)ActionButton("Consultar pedido de Mercado Libre",Icons.Rounded.CloudDownload,!state.busy,vm::marketplaceInfo);if(p.draft.flag("documento.pedido"))ActionButton("Convertir pedido a venta",Icons.Rounded.CheckCircle,!state.busy,vm::convertOrder)}
                2->{
                    val editable=p.draft.n("documento.documento")==0||p.draft.flag("documento.editar_productos")
                    if(editable)ActionButton("Agregar producto",Icons.Rounded.Add,!state.busy){vm.lookup("product")}
                    p.draft.list("documento.productos").forEachIndexed{i,line->Card(Modifier.fillMaxWidth().padding(vertical=6.dp)){Column(Modifier.padding(16.dp)){Text(line.s("descripcion"),fontWeight=FontWeight.SemiBold);Text(line.s("codigo"),color=Teal);Text("${line.s("cantidad")} × ${amount(line.s("precio"))}");if(editable)Row{TextButton({vm.editLine(i)}){Text("Editar")};TextButton({vm.removeLine(i)}){Text("Quitar")}}}}}
                    p.catalog.list("promociones").forEach{promotion->ActionButton("Agregar promoción · ${promotion.label("nombre","descripcion")}",enabled=!state.busy){vm.addPromotion(promotion)}}
                    val total=p.draft.list("documento.productos").fold(java.math.BigDecimal.ZERO){s,l->s+l.money("cantidad")*l.money("precio")};Text("Total: ${amount(total.toPlainString())}",style=MaterialTheme.typography.titleLarge,modifier=Modifier.padding(vertical=16.dp))
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
        Text("${p.selected.size} ${if(credit)if(p.selected.size==1)"nota de crédito" else "notas de crédito" else if(p.selected.size==1)"venta" else "ventas"}",style=MaterialTheme.typography.headlineSmall)
        Text(p.selected.joinToString{"#${it.s("id")}"},Modifier.padding(vertical=8.dp),color=Teal)
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
            Notice("La solicitud permanece pendiente hasta que el servidor recupere el UUID, XML y PDF.")
        }
    }
}

@Composable private fun Lookup(state:UiState,vm:CrmViewModel){val p=state.page
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp)){
        item{OutlinedTextField(p.filters.s("criterio"),{vm.filter("criterio",it)},Modifier.fillMaxWidth(),label={Text("Buscar")},singleLine=true);Button(vm::searchLookup,enabled=!state.busy,modifier=Modifier.fillMaxWidth().padding(vertical=10.dp)){Text("Buscar")}}
        items(p.rows){row->RecordCard(if(p.action=="client")Section.CUSTOMERS else Section.PRODUCTS,row,{vm.show(row)})}
        if(p.loaded&&p.rows.isEmpty())item{EmptyState("Sin resultados","Prueba otro código o descripción.")}
    }
}
@Composable private fun InfoScreen(state:UiState,vm:CrmViewModel){val p=state.page
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)){
        if(p.action=="import-marketplace")p.data.list("venta").forEach{order->DetailGroup("Venta ${order.s("id")}",true){DataDetails(order);ActionButton("Usar esta venta",Icons.Rounded.Check,!state.busy){vm.importMarketplace(order)}}}
        if(p.action=="shipping-quotes"){if(p.rows.isEmpty())EmptyState("Sin cotizaciones","No hay servicios disponibles para este envío.");p.rows.forEach{rate->OutlinedCard(onClick={vm.chooseShipping(rate)},Modifier.fillMaxWidth().padding(vertical=6.dp)){Column(Modifier.padding(16.dp)){Text(rate.s("paqueteria"),style=MaterialTheme.typography.titleMedium);DataDetails(rate);Text("Seleccionar servicio",color=Teal)}}}}
        if(p.action!="import-marketplace")DataDetails(p.data)
        if(p.data.s("request.id").isNotBlank())ActionButton("Actualizar estado de timbrado",Icons.Rounded.Refresh,!state.busy){vm.syncRequest(p.data.s("request.id"))}
        val refact=if(p.data.has("resultado"))p.data.o("resultado")else p.data
        if(state.user?.permission(11,36)==true){
            if(refact.n("nota_credito")>0)ActionButton("Facturación de nota #${refact.s("nota_credito")}",enabled=!state.busy){vm.billingChild(refact.s("nota_credito"),6)}
            if(refact.n("documento_nuevo")>0)ActionButton("Facturación del nuevo pedido #${refact.s("documento_nuevo")}",enabled=!state.busy){vm.billingChild(refact.s("documento_nuevo"),2)}
        }
        if(p.action=="recalc")ActionButton("Aplicar costo calculado",Icons.Rounded.Check,!state.busy){vm.recalcCost(true)}
    }
}
@Composable fun AttachmentList(files:List<JsonObject>,vm:CrmViewModel,canDelete:Boolean=false){if(files.isNotEmpty()){SectionLabel("Archivos");files.forEach{file->Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically){TextButton({vm.openAttachment(file)},Modifier.weight(1f)){Icon(Icons.Rounded.AttachFile,null);Text(file.label("nombre","archivo","tipo"),Modifier.padding(start=8.dp))};if(canDelete)IconButton({vm.removeAttachment(file)}){Icon(Icons.Rounded.DeleteOutline,"Eliminar archivo")}}}}}
@Composable private fun LocalAttachments(data:JsonObject,path:String,vm:CrmViewModel){data.list(path).forEachIndexed{i,file->Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically){Text(file.s("nombre"),Modifier.weight(1f));IconButton({vm.removeLocalFile(path,i)}){Icon(Icons.Rounded.Close,"Quitar archivo")}}}}
