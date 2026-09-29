package com.afainnova.crm

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.afainnova.crm.data.*
import com.google.gson.*
import kotlinx.coroutines.*
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.*
import java.math.BigDecimal

enum class PageKind { HOME, LIST, DETAIL, FORM, SALE, BILL, ACTION, LOOKUP, INFO, MERGE }
data class Page(val kind:PageKind=PageKind.HOME,val section:Section=Section.DOCUMENTS,val title:String="",val filters:JsonObject=obj(),val catalog:JsonObject=obj(),val rows:List<JsonObject> = emptyList(),val data:JsonObject=obj(),val draft:JsonObject=obj(),val fields:List<Field> = emptyList(),val action:String="",val step:Int=0,val selected:List<JsonObject> = emptyList(),val dirty:Boolean=false,val loaded:Boolean=false)
data class Confirmation(val title:String,val message:String)
data class UiState(val user:User?=null,val page:Page=Page(),val busy:Boolean=false,val saving:Boolean=false,val error:String?=null,val notice:String?=null,val confirmation:Confirmation?=null,val mfa:JsonObject=obj(),val unlocked:Boolean=false)
sealed interface UiEvent {
    data class Link(val url:String):UiEvent
    data class File(val name:String,val base64:String):UiEvent
}

class CrmViewModel(val repo:CrmRepository):ViewModel() {
    private val mutable=MutableStateFlow(UiState(user=repo.sessions.read()))
    val state=mutable.asStateFlow()
    private val channel=Channel<UiEvent>(Channel.BUFFERED)
    val events=channel.receiveAsFlow()
    private val history=ArrayDeque<Page>()
    private var request:Job?=null
    private var requestGeneration=0
    private var confirmAction:(()->Unit)?=null
    private val current get()=mutable.value.page
    fun clearError(){mutable.update{it.copy(error=null)}}
    fun clearNotice(){mutable.update{it.copy(notice=null)}}
    fun error(message:String){mutable.update{it.copy(error=message)}}
    private fun page(block:(Page)->Page){mutable.update{it.copy(page=block(it.page))}}
    private fun push(next:Page){history.addLast(current);mutable.update{it.copy(page=next,error=null,notice=null,unlocked=false)}}
    private fun pop(){if(history.isNotEmpty())mutable.update{it.copy(page=history.removeLast(),error=null,unlocked=false)}}
    fun back(){if(state.value.saving)return;if(state.value.busy){requestGeneration++;request?.cancel();mutable.update{it.copy(busy=false)}};if(current.dirty)confirm("Descartar cambios","Los cambios sin guardar se perderán."){pop()} else pop()}
    fun confirm(title:String,message:String,action:()->Unit){confirmAction=action;mutable.update{it.copy(confirmation=Confirmation(title,message))}}
    fun cancelConfirm(){confirmAction=null;mutable.update{it.copy(confirmation=null)}}
    fun acceptConfirm(){val action=confirmAction;cancelConfirm();action?.invoke()}
    private fun work(mutation:Boolean=false,block:suspend ()->Unit) {
        if(state.value.busy)return
        val generation=++requestGeneration
        mutable.update{it.copy(busy=true,saving=mutation,error=null)}
        request=viewModelScope.launch {
            try {block()} catch(e:CancellationException){throw e} catch(e:Exception){
                if(e is ApiException && e.status==401){history.clear();mutable.update{it.copy(user=null,page=Page())}}
                error(e.message?.take(1200)?:"No fue posible completar la operación.")
            } finally {if(requestGeneration==generation)mutable.update{it.copy(busy=false,saving=false)}}
        }
    }
    private fun result(json:JsonElement, fallback:String="Operación completada") {
        mutable.update{it.copy(notice=json.objectOrEmpty().s("message").ifBlank{fallback})}
        val r=json.objectOrEmpty()
        if(r.s("file").isNotBlank())channel.trySend(UiEvent.File(r.s("name").ifBlank{"documento.pdf"},r.s("file")))
    }
    fun signIn(email:String,password:String,code:String){work {
        require(email.isNotBlank()&&password.isNotBlank()){ "Escribe tu correo y contraseña." }
        val r=repo.login(email,password,code)
        val user=repo.sessions.read()
        mutable.update{it.copy(user=user,mfa=if(user==null)r else obj(),page=Page())}
        if(user==null&&!r.flag("mfa_setup")&&!r.flag("mfa_required"))error(r.s("message").ifBlank{"No fue posible iniciar sesión."})
    }}
    fun resetPassword(email:String,code:String){confirm("Recuperar contraseña","Se solicitará la recuperación para $email."){work{result(repo.reset(email,code))}}}
    fun logout(){confirm("Cerrar sesión","Se cerrará tu sesión en este dispositivo."){repo.sessions.clear();history.clear();mutable.value=UiState()}}
    fun open(section:Section) {
        if(state.value.busy)return
        if(state.value.user?.visible(section)!=true)return
        if(section==Section.CANCELLATION){invoiceCancellation();return}
        if(section==Section.DEV){push(Page(PageKind.INFO,Section.DEV,section.title,action="dev-home"));return}
        push(Page(PageKind.LIST,section,section.title,defaultFilters(section)))
        work {
            val c=repo.catalog(section)
            page{it.copy(catalog=c)}
            if(section in setOf(Section.USERS,Section.MARKETPLACES,Section.WAREHOUSES,Section.TICKETS,Section.SALES,Section.BILLING))loadResults()
        }
    }
    fun openMerge(kind:String) {
        if(state.value.user?.permission(6,1)!=true)return
        push(Page(PageKind.MERGE,Section.DEV,if(kind=="entidades")"Conciliar entidades" else "Conciliar productos",
            draft=obj("kind" to kind,"keepQuery" to "","removeQuery" to "","keepId" to 0,"removeId" to 0,"confirmation" to "")))
    }
    fun mergeSearch(slot:String){val p=current;val kind=p.draft.s("kind");val query=p.draft.s("${slot}Query").trim()
        if(query.length<2){error("Escribe al menos dos caracteres para buscar.");return}
        work{val rows=repo.get("developer/conciliar/$kind/buscar?query=${segment(query)}").objectOrEmpty().list("data")
            page{it.copy(catalog=it.catalog.changed("${slot}Results",rows),data=obj(),fields=emptyList(),draft=it.draft.changed("confirmation",""))}
        }
    }
    fun mergeSelect(slot:String,id:Int){page{it.copy(draft=it.draft.changed("${slot}Id",id).changed("confirmation",""),data=obj(),fields=emptyList())}}
    fun mergeInspect(){val p=current;val keep=p.draft.n("keepId");val remove=p.draft.n("removeId")
        if(keep<1||remove<1||keep==remove){error("Selecciona dos registros diferentes.");return}
        work{val review=repo.post("developer/conciliar/${p.draft.s("kind")}/revisar",obj("keep_id" to keep,"remove_id" to remove),Encoding.JSON).objectOrEmpty().o("data")
            val labels=mapOf("razon_social" to "Razón social","rfc" to "RFC","descripcion" to "Descripción","sku" to "SKU","telefono" to "Teléfono","correo" to "Correo","codigo_postal_fiscal" to "Código postal fiscal","info_extra" to "Información adicional JSON")
            val fields=review.at("fields").arrayOrEmpty().map { it.text() }.filter(String::isNotBlank).map {Field("edit.$it",labels[it]?:it,if(it=="info_extra"||it=="descripcion")FieldKind.LONG else FieldKind.TEXT) }
            val draft=p.draft.deepCopy();review.o("keep").entrySet().forEach{(key,value)->if(review.at("fields").arrayOrEmpty().any{it.text()==key})draft.put("edit.$key",value.text())}
            page{it.copy(data=review,fields=fields,draft=draft)}
        }
    }
    fun mergeApply(){val p=current;val review=p.data
        if(review.s("confirmation_token").isBlank()){error("Revisa primero los registros.");return}
        if(review.list("blockers").isNotEmpty()){error("Hay conflictos pendientes en la revisión.");return}
        val remove=review.n("remove.id")
        if(p.draft.s("confirmation")!="CONCILIAR $remove"){error("Escribe CONCILIAR $remove para confirmar.");return}
        confirm("Conciliar ${p.draft.s("kind")}","Se conservará #${review.n("keep.id")} y se eliminará #$remove después de mover sus referencias."){
            work(mutation=true){val edited=obj();p.fields.forEach{field->edited.put(field.key.removePrefix("edit."),p.draft.s(field.key))}
                val response=repo.post("developer/conciliar/${p.draft.s("kind")}/aplicar",obj("keep_id" to review.n("keep.id"),"remove_id" to remove,
                    "confirmation_token" to review.s("confirmation_token"),"confirmation_text" to p.draft.s("confirmation"),"fields" to edited),Encoding.JSON)
                pop();result(response.objectOrEmpty().o("data"))
            }
        }
    }
    fun devNexfira(){action("Liberar intento Nexfira","dev-nexfira",listOf(Field("documento","ID del documento",FieldKind.NUMBER,true),
        Field("reason","Motivo (mínimo 10 caracteres)",FieldKind.LONG,true)),obj("documento" to "","reason" to "","confirmation" to ""))}
    fun inspectDevNexfira(){val id=current.draft.n("documento");if(id<1){error("Escribe el ID del documento.");return}
        work{val review=repo.get("developer/nexfira/$id").objectOrEmpty().o("data");page{it.copy(data=review,draft=it.draft.changed("confirmation",""))}}
    }
    fun resetDevNexfira(){val p=current;val review=p.data;val id=p.draft.n("documento")
        val automatic=review.flag("can_reset")
        val manual=review.flag("can_manual_reset")&&p.draft.flag("rejected_confirmed")&&p.draft.flag("duplicate_risk_accepted")
            &&p.draft.s("manual_confirmation")=="LIBERAR $id"
        if(!automatic&&!manual){error("Revisa los bloqueos y completa las confirmaciones requeridas.");return}
        if(p.draft.s("reason").trim().length<10||p.draft.s("confirmation")!="LIBERAR $id"){error("Escribe un motivo y LIBERAR $id para confirmar.");return}
        confirm("Liberar intento","Se archivará el intento local #${review.s("request_id")}. Revisa los documentos vinculados."){
            work(mutation=true){val payload=obj("request_id" to review.n("request_id"),
                "confirmation_token" to review.s("confirmation_token"),"reason" to p.draft.s("reason"))
                if(!automatic&&manual)payload.put("manual_confirmation",obj("rejected_confirmed" to true,
                    "duplicate_risk_accepted" to true,"document_confirmation" to p.draft.s("manual_confirmation")))
                val response=repo.post("developer/nexfira/$id/liberar",payload,Encoding.JSON)
                pop();result(response.objectOrEmpty().o("data"))
            }
        }
    }
    fun filter(key:String,value:Any?){page{it.copy(filters=it.filters.changed(key,value),selected=if(key in setOf("fulfillment","document_type"))emptyList() else it.selected)}}
    fun change(key:String,value:Any?) {
        if(current.kind==PageKind.MERGE&&key in setOf("keepQuery","removeQuery")){
            val slot=key.removeSuffix("Query")
            page{it.copy(draft=it.draft.changed(key,value).changed("${slot}Id",0).changed("confirmation",""),
                data=obj(),fields=emptyList(),catalog=it.catalog.changed("${slot}Results",emptyList<Any>()))}
            return
        }
        page { p->
            val next=p.draft.changed(key,value)
            if(p.kind==PageKind.SALE)when(key){
                "empresa"->next.put("documento.almacen","")
                "area"->{next.put("documento.marketplace","");next.put("area_text",p.catalog.list("areas").find{it.s("id")==value.toString()}.objectOrEmpty().s("area"))}
                "documento.periodo"->next.put("documento.cobro.generar_ingreso",if(value.toString()=="1")1 else 0)
                "documento.marketplace"->{
                    val market=p.catalog.list("areas").flatMap{it.list("marketplaces")}.find{it.s("id")==value.toString()}.objectOrEmpty()
                    next.put("terminar",if(market.s("app_id").isBlank())1 else 0)
                }
            }
            if(key=="precio.empresa")next.put("precio.precio",next.list("precios_empresa").find {it.s("id_empresa")==value.toString()}?.money("precio")?:BigDecimal.ZERO)
            if(key=="informacionGlobal.periodicity")next.put("informacionGlobal.months",if(value.toString()=="05")"13" else "01")
            if(p.section==Section.CUSTOMERS&&key=="regimen")next.put("fiscal",p.catalog.list("regimenes").find{it.s("codigo")==value.toString()}.objectOrEmpty().label("regimen","descripcion"))
            p.copy(draft=next,dirty=true,step=if(p.kind==PageKind.BILL&&p.step==1&&!key.startsWith("lineEdits."))0 else p.step)
        }
    }
    fun search(){work{loadResults()}}
    private suspend fun loadResults() {
        val p=current
        if(p.section in setOf(Section.DOCUMENTS,Section.SERIES,Section.PRODUCTS,Section.CUSTOMERS,Section.SUPPLIERS)&&p.filters.s("criterio").isBlank()){page{it.copy(rows=emptyList(),loaded=false)};return}
        if(p.section==Section.BILLING){val data=repo.billing(p.filters);page{it.copy(rows=data.list("documents"),catalog=data,loaded=true)}}
        else {val rows=repo.search(p.section,p.filters,p.catalog);page{it.copy(rows=rows,loaded=true)}}
    }
    fun refresh(){work{val c=repo.catalog(current.section);page{it.copy(catalog=c)};loadResults()}}
    fun show(item:JsonObject){
        val p=current
        when(p.kind) {
            PageKind.LOOKUP -> chooseLookup(item)
            else -> when(p.section){
                Section.PRODUCTS,Section.CUSTOMERS,Section.SUPPLIERS,Section.USERS,Section.MARKETPLACES -> edit(item)
                Section.SALES -> sale(item.s("id"))
                Section.BILLING -> billingForm("individual",listOf(item))
                else -> {
                    push(Page(PageKind.DETAIL,p.section,title=item.label("titulo","descripcion","cliente","empresa","almacen","id"),data=item,catalog=p.catalog,filters=p.filters))
                    if(item.s("_kind")=="credit")work{val d=repo.credit(item.s("id")).changed("_kind","credit");page{it.copy(data=d)}}
                }
            }
        }
    }
    fun edit(item:JsonObject?=null){
        val p=current
        if(p.section==Section.SALES){sale();return}
        val draft=if(item==null)newRecord(p.section) else normalizeRecord(p.section,item)
        if(p.section==Section.CUSTOMERS){val values=listOf(draft.s("regimen"),draft.s("regimen_id"),draft.s("regimen_letra"),draft.s("fiscal")).filter(String::isNotBlank);p.catalog.list("regimenes").find{r->values.any{it in listOf(r.s("codigo"),r.s("id"),r.s("regimen"))}}?.let{draft.put("regimen",it.s("codigo"));draft.put("fiscal",it.s("regimen"))}}
        if(p.section==Section.PRODUCTS){draft.put("empresa",p.filters.s("empresa").ifBlank{"1"});draft.put("precio.empresa",draft.s("empresa"));draft.put("precio.precio",draft.list("precios_empresa").find{it.s("id_empresa")==draft.s("empresa")}?.money("precio")?:BigDecimal.ZERO)}
        push(Page(if(p.section==Section.WAREHOUSES&&item!=null)PageKind.DETAIL else PageKind.FORM,p.section,(if(item==null)"Crear · " else "Editar · ")+p.section.title,catalog=p.catalog,draft=draft,data=draft))
    }
    fun saveEntity(){
        val p=current
        validateFields(entityFields(p.section,p.catalog,p.draft,state.value.unlocked),p.draft)?.let{error(it);return}
        confirm("Guardar cambios","Se guardará la información de ${p.section.title.lowercase()} en el CRM."){work(mutation=true) {
            val r=repo.save(p.section,p.draft)
            pop();val c=repo.catalog(p.section);page{it.copy(catalog=c)};loadResults();result(r)
        }}
    }
    fun deleteEntity(){val p=current;confirm(if(p.section==Section.USERS)"Desactivar usuario" else "Eliminar almacén","Esta acción afectará a ${p.draft.label("nombre","almacen")}. Confirma para continuar."){work(mutation=true){
        val r=if(p.section==Section.USERS)repo.get("configuracion/usuario/gestion/desactivar/${segment(p.draft.s("id"))}") else repo.post("configuracion/almacen/eliminar",obj("id" to p.draft.s("id")),Encoding.JSON)
        pop();val c=repo.catalog(p.section);page{it.copy(catalog=c)};loadResults();result(r)
    }}}
    fun action(title:String,id:String,fields:List<Field>,draft:JsonObject=obj(),data:JsonObject=current.data){push(Page(PageKind.ACTION,current.section,title,catalog=current.catalog,filters=current.filters,draft=draft,data=data,fields=fields,action=id))}
    fun ticketAssign(){work{val options=repo.get("ticket/tecnicos").objects().options();action("Asignar ticket","ticket/asignar",listOf(Field("asignado_a","Técnico",FieldKind.SELECT,true,options)),current.data.deepCopy())}}
    fun ticketResolve(){action("Resolver ticket","ticket/terminar",listOf(Field("resolucion","Resolución",FieldKind.LONG,true)),current.data.deepCopy())}
    fun startTicket(){val ticket=current.data;confirm("Iniciar revisión","El ticket pasará a revisión."){work(mutation=true){result(repo.post("ticket/iniciar-revision",ticket));page{it.copy(data=ticket.changed("estado","en_revision"))}}}}
    fun followup(){work{val printers=repo.get("print/impresoras").objects().options();action("Seguimiento del documento","followup",listOf(Field("seguimiento","Seguimiento",FieldKind.LONG),Field("guia","Tipo de archivo",FieldKind.SELECT,false,choices("1" to "Información","2" to "Guía de envío")),Field("impresora","Impresora de la guía",FieldKind.SELECT,false,printers)),obj("documento" to current.data.s("id"),"seguimiento" to "","archivos" to emptyList<Any>(),"guia" to "1","impresora" to "","necesita_token" to false,"token" to ""))}}
    fun fiscal(){val p=current;work{
        val stamped=p.data.s("uuid").isNotBlank()&&p.data.n("id_fase")==6
        val preview=repo.fiscalPreview(p.data.s("id"),stamped)
        if(!preview.flag("puede_refacturar")){push(Page(PageKind.INFO,p.section,"Refacturación",data=preview));return@work}
        val fields=listOf(Field("receptor.rfc","RFC",required=true),Field("receptor.razon_social","Razón social",required=true),Field("receptor.codigo_postal_fiscal","Código postal fiscal",FieldKind.NUMBER,true),Field("receptor.regimen","Régimen fiscal",FieldKind.SELECT,true,preview.list("regimenes").options("codigo","regimen","descripcion")),Field("receptor.id_cfdi","Uso CFDI",FieldKind.SELECT,true,preview.list("usos_cfdi").options()),Field("receptor.correo","Correo",FieldKind.EMAIL,true),Field("receptor.telefono","Teléfono",FieldKind.PHONE)) + if(preview.flag("requiere_token"))listOf(Field("token","Código de autorización",required=true)) else emptyList()
        action(if(stamped)"Refacturar / cambiar cliente" else "Editar cliente fiscal",if(stamped)"refacturacion" else "cliente-fiscal",fields,obj("documento" to p.data.s("id"),"receptor" to preview.o("receptor")),preview)
    }}
    fun prepareCode(){work{result(repo.get("authenticator/prepare"),"Solicitud de código enviada")}}
    fun unlockMarketplace(){action("Autorizar credenciales","unlock-marketplace",listOf(Field("code","Código de autorización",required=true)),obj("marketplace_api" to current.draft.n("api.id")))}
    fun cancelSale(){work{
        val c=repo.get("venta/venta/cancelar/data").objectOrEmpty()
        action("Eliminar venta","cancel-sale",listOf(Field("documento","Pedido a eliminar",FieldKind.NUMBER,true),Field("usuario","Usuario que autoriza",FieldKind.SELECT,true,c.list("usuarios").options()),Field("motivo","Motivo",FieldKind.LONG,true),Field("token","Código de autorización",required=true)),obj("documento" to "","garantia" to 0,"motivo" to "","usuario" to "","token" to ""))
    }}
    fun prepareCancel(){val d=current.draft;work{require(d.s("usuario").isNotBlank()){ "Selecciona quién autoriza." };result(repo.post("authenticator/prepare-with-option",obj("usuario" to d.s("usuario"),"token" to d.s("token"))))}}
    fun saveAction(){
        val p=current
        validateFields(p.fields,p.draft)?.let{error(it);return}
        if(p.fields.any{it.key in setOf("token","code")&&it.required}&&!Regex("[0-9]{6}").matches(p.draft.s(if(p.action=="unlock-marketplace")"code" else "token"))){error("Escribe el código de autorización de seis dígitos.");return}
        if(p.action=="line"){applyLine();return}
        if(p.action=="provider"){applyProvider();return}
        if(p.action=="kardex"){work{val r=repo.post("general/busqueda/producto/kardex-crm",p.draft).objectOrEmpty();push(Page(PageKind.INFO,Section.STOCK,"Movimientos del producto",data=r));if(r.s("excel_data").isNotBlank())channel.send(UiEvent.File(r.s("excel_name"),r.s("excel_data")))};return}
        confirm("Confirmar · ${p.title}",when(p.action){"refacturacion"->"Se generará una nota de crédito y un documento con el nuevo receptor. El timbrado se realiza desde Facturación.";"cancel-sale"->"Se eliminará la venta ${p.draft.s("documento")}. Revisa el pedido y el motivo antes de confirmar.";else->"Se enviará la información al CRM."}){work(mutation=true) {
            val r=when(p.action){
                "followup" -> {require(p.draft.s("seguimiento").isNotBlank()||p.draft.list("archivos").isNotEmpty()){ "Agrega un seguimiento o un archivo." };repo.post("general/busqueda/venta/guardar",p.draft)}
                "cliente-fiscal" -> repo.post("general/busqueda/venta/cliente-fiscal/${segment(p.draft.s("documento"))}",p.draft,Encoding.JSON)
                "refacturacion" -> repo.post("general/busqueda/venta/refacturacion",p.draft,Encoding.JSON)
                "unlock-marketplace" -> repo.post("configuracion/sistema/marketplace/ver-credenciales",p.draft)
                "cancel-sale" -> {repo.post("authenticator/validate-with-option",obj("usuario" to p.draft.s("usuario"),"token" to p.draft.s("token")));repo.post("venta/venta/cancelar",obj("documento" to p.draft.s("documento"),"garantia" to p.draft.n("garantia"),"motivo" to p.draft.s("motivo")),Encoding.FIELDS)}
                "print-series" -> repo.post("print/etiquetas/busqueda",p.draft)
                else -> repo.post(p.action,p.draft)
            }
            pop()
            when(p.action){
                "unlock-marketplace" -> {page{it.copy(draft=it.draft.changed("api.secret",r.objectOrEmpty().s("data")))};mutable.update{it.copy(unlocked=true)}}
                "followup","cliente-fiscal" -> {val d=repo.document(p.draft.s("documento"));page{it.copy(data=d)}}
                "refacturacion" -> push(Page(PageKind.INFO,Section.BILLING,"Resultado de refacturación",data=r.objectOrEmpty().o("data")))
                "ticket/asignar"->page{it.copy(data=p.draft.changed("estado","asignado"))}
                "ticket/terminar"->page{it.copy(data=p.draft.changed("estado","resuelto"))}
            }
            result(r)
        }}
    }
    fun download(path:String){work{val r=repo.get(path);if(r.isJsonPrimitive)channel.send(UiEvent.Link(r.text())) else {val o=r.objectOrEmpty();channel.send(UiEvent.File(o.label("name","nombre"),o.label("file","data")))}}}
    fun openAttachment(file:JsonObject){work{channel.send(UiEvent.Link(repo.fileLink(file.s("dropbox"))))}}
    fun removeAttachment(file:JsonObject){val p=current;confirm("Eliminar archivo",file.label("nombre","archivo")){work(mutation=true){
        if(p.section==Section.PRODUCTS){repo.post("dropbox/delete",obj("path" to file.s("dropbox")),Encoding.JSON);repo.get("compra/producto/gestion/imagen/${segment(file.s("dropbox"))}");page{it.copy(draft=it.draft.changed("imagenes_anteriores",it.draft.list("imagenes_anteriores").filterNot{x->x.s("dropbox")==file.s("dropbox")}))}}
        else {repo.get("general/busqueda/venta/borrar/${segment(file.s("dropbox"))}");val d=repo.document(p.data.s("id"));page{it.copy(data=d)}}
    }}}
    fun attach(file:JsonObject,target:String=""){
        val p=current
        try {
            if(target=="pdf"||target=="xml"){
                if(target=="xml"){val identity=FileRules.cfdi(file.s("data"));require(identity.s("tipo")==if(p.data.n("document_type")==6)"E" else "I"){ "El tipo de CFDI no corresponde a los documentos seleccionados." };change("uuid",identity.s("uuid"));change("_identity",identity)}
                if(target=="pdf")FileRules.requirePdf(FileRules.decode(file.s("data")))
                change(target,file.s("data"));change("_${target}Name",file.s("nombre"))
                return
            }
            val path=if(p.kind==PageKind.SALE)"documento.archivos" else if(p.section==Section.PRODUCTS)"imagenes" else "archivos"
            val attached=p.draft.list(path)
            if(p.section==Section.PRODUCTS)require(attached.size+p.draft.list("imagenes_anteriores").size<6){ "Puedes guardar hasta seis imágenes." }
            val record=file.deepCopy()
            if(p.action=="followup"||p.kind==PageKind.SALE){record.put("guia",p.draft.s("guia").ifBlank{"1"});record.put("impresora",p.draft.s("impresora"));if(record.s("guia")=="2")require(record.s("impresora").isNotBlank()){ "Selecciona una impresora para la guía." }}
            change(path,attached+record)
        }catch(e:Exception){error(e.message?:"Archivo inválido")}
    }
    fun removeLocalFile(path:String,index:Int){change(path,current.draft.list(path).filterIndexed{i,_->i!=index})}
    fun selectBilling(item:JsonObject){
        if(item.flag("already_invoiced")||item.flag("request.is_active"))return
        page { p->p.copy(selected=if(p.selected.any{it.s("id")==item.s("id")})p.selected.filterNot{it.s("id")==item.s("id")} else p.selected+item) }
    }
    fun billingIds(ids:String){work{
        val parsed=ids.split(Regex("[ ,;\\n]+")).filter(String::isNotBlank).map{requireNotNull(it.toIntOrNull()){ "Usa números de pedido separados por coma." }}
        val r=repo.post("venta/venta/facturacion/seleccion",obj("documentos" to parsed,"fulfillment" to current.filters.n("fulfillment"),"document_type" to current.filters.n("document_type")),Encoding.JSON).objectOrEmpty().o("data")
        page{it.copy(selected=(it.selected+r.list("documents")).distinctBy {row->row.s("id")})}
        if(r["not_available"].arrayOrEmpty().size()>0)mutable.update{it.copy(notice="Pedidos no disponibles: ${r["not_available"].arrayOrEmpty().joinToString{it.text()}}")}
    }}
    fun billingForm(mode:String,documents:List<JsonObject> = current.selected){work{
        require(documents.isNotEmpty()){ "Selecciona al menos un documento." }
        require(documents.none {it.flag("request.is_active")||it.s("request.status")=="uncertain"||it.flag("already_invoiced")}){ "Hay documentos con una solicitud activa. Actualiza su estado." }
        if(mode!="external"){
            require(current.catalog.flag("configured")){ "El servicio de timbrado no está configurado." }
            require(documents.all {it.flag("can_hub")}){ "La selección contiene documentos que no pueden timbrarse por este medio." }
        } else require(documents.all {it.flag("can_external")}){ "La selección contiene documentos que no admiten CFDI externo." }
        if(mode=="global"){
            require(documents.size>=2){ "Selecciona al menos dos ventas." }
            require(documents.none{it.n("id_tipo")==6||it.n("document_type")==6}){ "Las notas de crédito se timbran individualmente." }
            require(documents.map{it.s("rfc").trim().uppercase()}.distinct().size==1){ "Las ventas deben tener el mismo receptor fiscal." }
            require(documents.map{it.s("billing_series")}.distinct().size==1){ "Las ventas deben utilizar la misma serie fiscal." }
        }
        val preview=if(mode=="external")obj() else repo.billingPreview(documents.first().s("id"))
        val content=preview.o("payload.content")
        val values=obj("series" to documents.first().s("billing_series"),"folio" to "","paymentMethod" to content.s("paymentMethod").ifBlank{"PUE"},"paymentForm" to content.s("paymentForm").ifBlank{"03"},"relationshipCode" to "03","agrupacion" to "ventas","informacionGlobal" to content.o("globalInformation").takeIf{it.size()>0}.let{it?:obj("periodicity" to "04","months" to java.time.LocalDate.now().monthValue.toString().padStart(2,'0'),"year" to java.time.LocalDate.now().year)})
        push(Page(PageKind.BILL,Section.BILLING,when(mode){"global"->"Factura global";"external"->"Registrar CFDI externo";else->"Timbrado individual"},catalog=current.catalog,data=preview.changed("document_type",current.filters.n("document_type")),draft=values,selected=documents,action=mode))
    }}
    fun reviewBilling(){val p=current;work{
        require(p.step!=1||!p.dirty){"Guarda los importes editados antes de actualizar la vista previa."}
        val payload=p.draft.deepCopy()
        validateBilling(payload,p.data,p.data.n("document_type")==6)
        if(p.data.s("payload.content.receiver.rfc").uppercase()!="XAXX010101000"||p.data.n("document_type")==6)payload.remove("informacionGlobal")
        payload.put("modo",p.action);payload.put("documentos",p.selected.map{it.n("id")})
        val review=repo.post("venta/venta/facturacion/revisar",payload,Encoding.JSON).objectOrEmpty().o("data")
        require(review.flag("valid")){review.at("blockers").arrayOrEmpty().joinToString{it.text()}.ifBlank{"No se pudo preparar la factura."}}
        val edits=obj();review.list("editable_lines").forEach{line->edits.put("${line.s("id")}.precio",line.s("precio"));edits.put("${line.s("id")}.descuento",line.s("descuento"))}
        page{it.copy(data=review.changed("document_type",p.data.n("document_type")),draft=it.draft.changed("lineEdits",edits),step=1,dirty=false)}
    }}
    fun saveBillingLine(line:JsonObject){val p=current;work(mutation=true){
        val id=line.s("id");val price=p.draft.s("lineEdits.$id.precio");val discount=p.draft.s("lineEdits.$id.descuento")
        repo.post("venta/venta/facturacion/pedido/${segment(line.s("id_documento"))}/partida/${segment(id)}",obj("precio" to price,"descuento" to discount),Encoding.JSON)
        page{it.copy(step=0,data=p.data.changed("review_hash",""),dirty=false)}
        mutable.update{it.copy(notice="Importe guardado en el pedido. Vuelve a revisar la factura.")}
    }}
    fun sendBilling(){val p=current;try {
        if(p.action!="external"&&p.step==0){reviewBilling();return}
        require(p.action=="external"||!p.dirty){"Guarda los importes editados y vuelve a revisar la factura."}
        val payload=p.draft.deepCopy();payload.entrySet().filter{it.key.startsWith("_")}.map{it.key}.forEach(payload::remove)
        if(p.action=="external"){
            require(payload.s("pdf").isNotBlank()&&payload.s("xml").isNotBlank()){ "Adjunta el PDF y XML del CFDI." }
            val identity=FileRules.cfdi(payload.s("xml"));require(identity.s("uuid").equals(payload.s("uuid"),true)){ "El UUID no coincide con el XML." }
            require(identity.s("tipo")==if(p.data.n("document_type")==6)"E" else "I"){ "El tipo de CFDI no corresponde a la selección." }
        } else {
            validateBilling(payload,p.data,p.data.n("document_type")==6)
            if(p.data.s("payload.content.receiver.rfc").uppercase()!="XAXX010101000"||p.data.n("document_type")==6)payload.remove("informacionGlobal")
        }
        payload.put("documentos",p.selected.map{it.n("id")})
        if(p.action!="external")payload.put("review_hash",p.data.s("review_hash"))
        payload.remove("lineEdits")
        confirm("Confirmar facturación","Documentos: ${p.selected.joinToString{it.s("id")}}. ${if(p.action=="external")"Se vincularán el UUID, XML y PDF seleccionados." else "Se enviará la solicitud con serie ${payload.s("series")} y folio ${payload.s("folio").ifBlank{"automático"}}."}"){work(mutation=true){
            val fresh=repo.post("venta/venta/facturacion/seleccion",obj("documentos" to p.selected.map{it.n("id")},"document_type" to p.data.n("document_type")),Encoding.JSON).objectOrEmpty().o("data").list("documents")
            require(fresh.size==p.selected.size&&fresh.none{it.flag("already_invoiced")||it.flag("request.is_active")||it.s("request.status")=="uncertain"}){ "La selección cambió o tiene una solicitud pendiente. Actualiza su estado antes de continuar." }
            val path=when(p.action){"external"->"externa";"global"->"global";else->"individual/${segment(p.selected.first().s("id"))}"}
            val r=repo.post("venta/venta/facturacion/$path",payload,Encoding.JSON)
            pop();result(r);push(Page(PageKind.INFO,Section.BILLING,"Estado de facturación",data=r.objectOrEmpty()))
        }}
    }catch(e:Exception){error(e.message?:"Revisa los datos fiscales.")}}
    fun syncRequest(id:String){work(mutation=true){val r=repo.post("venta/venta/facturacion/solicitud/${segment(id)}/actualizar",obj(),Encoding.JSON);if(current.kind==PageKind.LIST)loadResults() else page{it.copy(data=r.objectOrEmpty())};result(r)}}
    fun billingChild(id:String,type:Int){work{
        val f=defaultFilters(Section.BILLING).changed("document_type",type).changed("criterio",id)
        val c=repo.billing(f)
        push(Page(PageKind.LIST,Section.BILLING,"Facturación",filters=f,catalog=c,rows=c.list("documents"),loaded=true))
    }}
    fun invoiceCancellation(){push(Page(PageKind.ACTION,Section.BILLING,"Cancelar factura Nexfira",action="invoice-cancel",
        fields=listOf(Field("folio","Folio",required=true),Field("serie","Serie si se repite"),Field("motivo","Motivo SAT",FieldKind.SELECT,true,choices("02" to "02 · Sin relación","01" to "01 · Sustitución","03" to "03 · No se llevó a cabo","04" to "04 · Operación nominativa")),Field("uuid_sustitucion","UUID sustituto si motivo 01"),Field("auth_code","Código autenticador",FieldKind.PASSWORD,true)),
        draft=obj("folio" to "","serie" to "","motivo" to "02","uuid_sustitucion" to "","auth_code" to "")))}
    fun previewInvoiceCancellation(){val p=current;work{
        require(p.draft.s("folio").isNotBlank()){ "Escribe el folio de la factura." }
        val detail=repo.get("venta/venta/facturacion/cancelacion?folio=${segment(p.draft.s("folio"))}&serie=${segment(p.draft.s("serie"))}").objectOrEmpty().o("data")
        page{it.copy(data=detail)}
    }}
    fun submitInvoiceCancellation(){val p=current;try{
        require(p.data.s("factura.folio")==p.draft.s("folio") && (p.draft.s("serie").isBlank()||p.data.s("factura.serie")==p.draft.s("serie"))){"Primero consulta y revisa la factura."}
        require(Regex("^[0-9]{6}$").matches(p.draft.s("auth_code"))){"Escribe los seis dígitos del autenticador."}
        confirm("Cancelar factura","Se solicitará la cancelación de ${p.draft.s("serie")}-${p.draft.s("folio")}. Las ventas seguirán facturadas hasta la aprobación."){work(mutation=true){
            val r=repo.post("venta/venta/facturacion/cancelacion",p.draft,Encoding.JSON)
            val detail=repo.get("venta/venta/facturacion/cancelacion?folio=${segment(p.draft.s("folio"))}&serie=${segment(p.draft.s("serie"))}").objectOrEmpty().o("data")
            page{it.copy(data=detail,draft=it.draft.changed("auth_code",""))};result(r)
        }}
    }catch(e:Exception){error(e.message?:"Revisa la factura.")}}
    fun refreshInvoiceCancellation(){val p=current;work(mutation=true){
        val r=repo.post("venta/venta/facturacion/cancelacion/actualizar",obj("folio" to p.draft.s("folio"),"serie" to p.draft.s("serie")),Encoding.JSON)
        page{it.copy(data=r.objectOrEmpty().o("data"))};result(r)
    }}
    fun simulateInvoiceCancellation(){val p=current;confirm("Simular aprobación","Se liberarán las ventas de la factura ${p.draft.s("folio")} para volver a timbrar."){work(mutation=true){
        val r=repo.post("developer/nexfira/cancelacion/simular",obj("folio" to p.draft.s("folio"),"serie" to p.draft.s("serie")),Encoding.JSON)
        page{it.copy(data=r.objectOrEmpty().o("data"))};result(r)
    }}}
    fun sale(id:String=""){work{
        val c=repo.catalog(Section.SALES)
        val draft=if(id.isBlank())newSale() else repo.loadSale(id)
        if(id.isNotBlank())c.list("empresas").find{e->e.list("almacenes").any{it.s("id")==draft.s("documento.almacen")}}?.let{draft.put("empresa",it.s("id"))}
        if(id.isBlank()&&c.list("areas").size==1){draft.put("area",c.list("areas").first().s("id"));draft.put("area_text",c.list("areas").first().s("area"))}
        push(Page(PageKind.SALE,Section.SALES,if(id.isBlank())"Nueva venta" else "Editar pedido #$id",catalog=c,draft=draft))
    }}
    fun saleStep(step:Int){page{it.copy(step=step.coerceIn(0,5))}}
    fun findSale(id:String){if(id.isBlank()){error("Escribe el número de pedido.");return};sale(id)}
    fun lookup(kind:String){push(Page(PageKind.LOOKUP,current.section,when(kind){"client"->"Seleccionar cliente";"sat"->"Buscar clave SAT";else->"Seleccionar producto"},filters=obj("criterio" to ""),action=kind,catalog=current.catalog))}
    fun searchLookup(){work{
        val value=current.filters.s("criterio").trim();require(value.isNotBlank()){ "Escribe un criterio de búsqueda." }
        val r=when(current.action){"client"->repo.get("venta/venta/crear/buscar-cliente/${segment(value)}");"sat"->repo.post("compra/producto/gestion/codigo/sat",obj("criterio" to value),Encoding.FIELDS);else->repo.get("compra/producto/buscar/${segment(value)}")}.objectOrEmpty()
        page{it.copy(rows=if(r["data"]?.isJsonObject==true)listOf(r.o("data")) else r.list("data"),loaded=true)}
    }}
    private fun chooseLookup(item:JsonObject){val kind=current.action;pop();when(kind){
        "client"->{change("cliente",item.changed("input",item.s("rfc")).changed("select",item.s("id")));change("documento.periodo",item.s("condicion"));if(item.s("rfc") in setOf("XAXX010101000","XEXX010101000"))change("documento.uso_venta","23");work{
            val r=repo.get("venta/venta/crear/cliente/direccion/${segment(item.s("rfc"))}").objectOrEmpty()
            if(r.o("direccion").size()>0){val d=r.o("direccion");change("documento.direccion_envio",d.changed("colonia",d.s("id_direccion_pro")));loadPostal()}
            if(r.o("informacion").s("correo").isNotBlank())listOf("correo","telefono","telefono_alt").forEach{change("cliente.$it",r.o("informacion").s(it))}
        }}
        "sat"->change("clave_sat",item.label("clave","codigo","c_ClaveProdServ","id"))
        else->action("Agregar producto","line",lineFields,newLine(item),obj("index" to -1))
    }}
    fun editLine(index:Int){action("Editar producto","line",lineFields,current.draft.list("documento.productos")[index].deepCopy(),obj("index" to index))}
    private fun applyLine(){val p=current;work{
        require(p.draft.money("cantidad")>BigDecimal.ZERO&&p.draft.money("precio")>=BigDecimal.ZERO){ "La cantidad debe ser positiva y el precio no puede ser negativo." }
        val parent=history.last();val lines=parent.draft.list("documento.productos").toMutableList();val index=p.data.n("index")
        if(index<0)lines.add(p.draft) else lines[index]=p.draft
        if(p.draft.n("tipo")==1){val quantity=lines.filter{it.s("codigo")==p.draft.s("codigo")}.fold(BigDecimal.ZERO){s,l->s+l.money("cantidad")};val r=repo.get("venta/venta/crear/producto/existencia/${segment(p.draft.s("codigo"))}/${segment(parent.draft.s("documento.almacen"))}/$quantity").objectOrEmpty();if(r.list("promociones").isNotEmpty())page{it.copy(catalog=it.catalog.changed("promociones",r.list("promociones")))}}
        val promos=current.catalog.list("promociones");pop();change("documento.productos",lines);if(promos.isNotEmpty())page{it.copy(catalog=it.catalog.changed("promociones",promos))}
    }}
    fun removeLine(index:Int){val line=current.draft.list("documento.productos")[index];confirm("Quitar producto",line.s("descripcion")){work(mutation=true){if(current.draft.n("documento.documento")>0&&line.n("id")>0)repo.get("venta/venta/editar/producto/borrar/${line.s("id")}");change("documento.productos",current.draft.list("documento.productos").filterIndexed{i,_->i!=index})}}}
    fun addPromotion(promotion:JsonObject){work{
        val lines=promotion.list("productos").map{newLine(it).apply{it.entrySet().forEach{(k,v)->add(k,v)}}}
        for(line in lines)repo.get("venta/venta/crear/producto/existencia/${segment(line.s("codigo"))}/${segment(current.draft.s("documento.almacen"))}/${segment(line.s("cantidad"))}")
        change("documento.productos",current.draft.list("documento.productos")+lines);change("_promotion",true);page{it.copy(catalog=it.catalog.changed("promociones",emptyList<Any>()))}
    }}
    fun postal(){work{loadPostal()}}
    private suspend fun loadPostal(){val r=repo.get("catalogo/buscar/cp/${segment(current.draft.s("documento.direccion_envio.codigo_postal"))}").objectOrEmpty();page{it.copy(catalog=it.catalog.changed("colonias",r["colonias"]?:r["data"]))};val first=(r["colonias"]?:r["data"]).objects().firstOrNull().objectOrEmpty();change("documento.direccion_envio.ciudad",r["ciudades"].arrayOrEmpty().firstOrNull().text().ifBlank{r.s("ciudad").ifBlank{first.s("ciudad")}});change("documento.direccion_envio.estado",r.s("estado").ifBlank{first.s("estado")} )}
    fun saveSale(){val p=current;confirm("Guardar venta","Se guardará el pedido con ${p.draft.list("documento.productos").size} productos. Revisa cliente, envío y cobro."){work(mutation=true){
        require(p.draft.n("terminar")==1){ "Consulta y selecciona la venta del marketplace antes de guardar." }
        val fields=(0..5).flatMap{saleFields(it,p.catalog,p.draft)}
        validateFields(fields,p.draft)?.let{throw IllegalArgumentException(it)}
        val d=p.draft.deepCopy()
        val market=p.catalog.list("areas").flatMap{it.list("marketplaces")}.find{it.s("id")==d.s("documento.marketplace")}.objectOrEmpty()
        val total=d.list("documento.productos").fold(BigDecimal.ZERO){s,l->s+l.money("cantidad")*l.money("precio")}*d.money("documento.tipo_cambio")
        val public=p.catalog.list("marketplaces").any{it.s("marketplace")==market.s("marketplace")}
        require(!public||d.s("documento.venta").trim()!="."){ "El marketplace requiere un número de venta válido." }
        if(public)d.put("documento.cobro.importe",total)
        require(market.s("marketplace")=="WALMART"||d.money("documento.total").setScale(0,java.math.RoundingMode.HALF_UP)<=(total+BigDecimal(3)).setScale(0,java.math.RoundingMode.HALF_UP)){ "El total del marketplace no concuerda con los productos." }
        p.catalog.list("colonias").find{it.s("codigo")==d.s("documento.direccion_envio.colonia")}?.let{d.put("documento.direccion_envio.colonia_text",it.s("colonia"))}
        val r=repo.saveSale(d);pop();result(r)
    }}}
    fun convertOrder(){val id=current.draft.s("documento.documento");confirm("Convertir pedido a venta","Se convertirá el pedido #$id."){work(mutation=true){result(repo.get("venta/venta/pedido/pendiente/convertir/${segment(id)}"));val d=repo.loadSale(id);page{it.copy(draft=d)}}}}
    fun marketplaceInfo(){val p=current;work{
        val m=if(p.kind==PageKind.SALE)p.catalog.list("areas").flatMap{it.list("marketplaces")}.find{it.s("id")==p.draft.s("documento.marketplace")}.objectOrEmpty() else p.data.o("api").changed("id",p.data.s("api.id_marketplace_area")).changed("publico",1).changed("marketplace",p.data.s("marketplace"))
        val id=if(p.kind==PageKind.SALE)p.draft.s("documento.venta") else p.data.s("no_venta")
        val importing=p.kind==PageKind.SALE&&p.draft.n("documento.documento")==0
        if(importing){require(m.s("id")=="1"&&m.s("marketplace").substringBefore(' ').equals("mercadolibre",true)){ "La consulta de importación conectada en el front corresponde a Mercado Libre." };repo.get("venta/venta/crear/existe/${segment(id)}/${segment(m.s("id"))}")}
        if(p.kind==PageKind.SALE)m.put("marketplace",m.s("marketplace").substringBefore(' '))
        val r=repo.post("venta/venta/crear/informacion",obj("venta" to id,"marketplace" to m,"marketplace_area" to m.s("id")),Encoding.FIELDS).objectOrEmpty()
        push(Page(PageKind.INFO,p.section,"Información del marketplace",data=r,action=if(importing)"import-marketplace" else "",catalog=m))
    }}
    fun importMarketplace(order:JsonObject){val p=current;work{
        val parent=history.last();repo.get("venta/venta/crear/existe/${segment(order.s("id"))}/${segment(parent.draft.s("documento.marketplace"))}")
        val imported=importMercadoLibre(parent.draft,order,parent.catalog)
        pop();page{it.copy(draft=imported,dirty=true)}
        if(imported.s("documento.direccion_envio.codigo_postal").isNotBlank())loadPostal()
        mutable.update{it.copy(notice="Datos del pedido cargados. Revisa el cliente, la colonia y agrega los productos de la venta.")}
    }}
    fun quoteShipping(){val id=current.draft.s("documento.documento");work{
        val r=repo.post("venta/shopify/cotizar-guia",obj("documento" to id)).objectOrEmpty();val types=r.list("paqueterias")
        val rates=r.o("guias").list("data").filterNot{it.flag("error")}.flatMap{carrier->carrier.list("cotizacion").mapNotNull{rate->types.find{it.s("codigo")==rate.s("service")&&it.s("paqueteria")==carrier.s("paqueteria")}?.let{rate.changed("id_paqueteria",it.s("id_paqueteria")).changed("paqueteria",carrier.s("paqueteria"))}}}.sortedBy{it.money("total")}
        push(Page(PageKind.INFO,Section.SALES,"Cotizaciones de envío",rows=rates,action="shipping-quotes"))
    }}
    fun chooseShipping(rate:JsonObject){pop();change("documento.paqueteria",rate.s("id_paqueteria"));change("documento.direccion_envio.tipo_envio",rate.s("service"))}
    fun exportStock(){val p=current;work{val r=repo.post("general/busqueda/producto/existencia",p.filters.changed("etiquetas",p.filters.s("etiquetas").split(',').map(String::trim))).objectOrEmpty();require(r.s("excel").isNotBlank()){ "No hay un archivo para esta consulta." };channel.send(UiEvent.File("existencias.xlsx",r.s("excel")))}}
    fun printSeries(){work{
        val printers=repo.get("print/impresoras").objects().options()
        val movement=current.data.list("movimientos").lastOrNull().objectOrEmpty()
        action("Imprimir etiqueta","print-series",listOf(Field("impresora","Impresora",FieldKind.SELECT,true,printers)),obj("serie" to current.filters.s("criterio"),"codigo" to movement.s("sku"),"descripcion" to movement.s("descripcion")))
    }}
    fun stockMovements(){val p=current;action("Consultar movimientos","kardex",listOf(Field("tipo_documento","Tipo de documento",FieldKind.SELECT,false,p.catalog.list("tipos_documento").options("id","tipo")),Field("fecha_inicial","Desde",FieldKind.DATE),Field("fecha_final","Hasta",FieldKind.DATE),Field("needs_excel","Exportar Excel",FieldKind.BOOL)),obj("empresa" to p.filters.s("empresa"),"producto" to p.data.label("codigo","sku","_sku"),"tipo_documento" to "","fecha_inicial" to "","fecha_final" to "","needs_excel" to false))}
    fun lastMovements(){val p=current;work{
        val r=repo.post("general/busqueda/producto/kardex-crm",obj("empresa" to p.filters.s("empresa"),"producto" to p.data.label("codigo","sku","_sku"),"tipo_documento" to "","fecha_inicial" to "","fecha_final" to "","needs_excel" to false)).objectOrEmpty()
        val rows=r.list("documentos").map{warehouse->
            fun latest(types:Set<String>):JsonObject {val movement=warehouse.list("documentos").firstOrNull{it.s("tipo") in types}?:return obj("estado" to "Sin movimientos");val date=runCatching{java.time.LocalDate.parse(movement.s("created_at").take(10))}.getOrNull();return movement.changed("dias_transcurridos",date?.let{java.time.temporal.ChronoUnit.DAYS.between(it,java.time.LocalDate.now())})}
            obj("almacen" to warehouse.s("almacen_nombre"),"ultima_recepcion" to latest(setOf("COMPRA")),"ultima_entrada_o_traspaso" to latest(setOf("ENTRADA","TRASPASO")))
        }
        push(Page(PageKind.INFO,Section.STOCK,"Últimos movimientos y antigüedad",data=obj("almacenes" to rows)))
    }}
    fun synonyms(){val p=current;work{val r=repo.post("compra/producto/sinonimo/producto",obj("data" to p.data.label("codigo","sku","_sku")),Encoding.FIELDS);push(Page(PageKind.INFO,p.section,"Sinónimos del producto",data=r.objectOrEmpty()))}}
    fun recalcCost(apply:Boolean=false){val sku=current.data.label("codigo","sku","_sku");val execute={work{val r=repo.post("general/busqueda/producto/costo/recalcular",obj("sku" to sku,"aplicar" to if(apply)"1" else "0"),Encoding.FIELDS);if(apply){pop();result(r)}else push(Page(PageKind.INFO,Section.STOCK,"Recalcular costo",data=r.objectOrEmpty().changed("sku",sku),action="recalc"))}};if(apply)confirm("Aplicar costo calculado","Se actualizará el costo del producto $sku.",execute) else execute()}
    fun productProvider(provider:JsonObject){action("Vincular producto de proveedor","provider",listOf(Field("producto_text","Buscar por código o descripción",required=true)),provider.deepCopy())}
    fun searchProvider(){work{val d=current.draft;val r=repo.post("compra/producto/gestion/producto-proveedor",obj("proveedor" to d.s("id"),"producto" to d.s("producto_text"))).objectOrEmpty();page{it.copy(fields=it.fields.filterNot{f->f.key=="producto"}+Field("producto","Producto del proveedor",FieldKind.SELECT,true,r.list("data").options("id","descripcion","codigo")))}}}
    private fun applyProvider(){val d=current.draft;pop();val providers=current.draft.list("proveedores").filterNot{it.s("id")==d.s("id")};change("proveedores",providers+d)}
    companion object { fun factory(repo:CrmRepository)=object:ViewModelProvider.Factory { @Suppress("UNCHECKED_CAST") override fun <T:ViewModel> create(modelClass:Class<T>):T=CrmViewModel(repo) as T } }
}
