@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class,androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
package com.afainnova.crm.ui

import android.app.DatePickerDialog
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.*
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.Alignment
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.*
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.text.HtmlCompat
import com.afainnova.crm.data.*
import com.google.gson.*
import java.time.LocalDate

val Ink=Color(0xFF162B49)
val Blue=Color(0xFF2457CD)
val Teal=Color(0xFF006D68)
val Muted=Color(0xFF52647D)
val Line=Color(0xFFD4DDEA)
val Canvas=Color(0xFFF1F4F9)
private val colors=lightColorScheme(
    primary=Blue,onPrimary=Color.White,primaryContainer=Color(0xFFE5EDFF),onPrimaryContainer=Color(0xFF173C8F),
    secondary=Teal,onSecondary=Color.White,secondaryContainer=Color(0xFFDDF2EB),onSecondaryContainer=Color(0xFF174E46),
    background=Canvas,onBackground=Ink,surface=Color.White,onSurface=Ink,
    surfaceVariant=Color(0xFFE9EFF7),onSurfaceVariant=Muted,outline=Color(0xFF8193AC),outlineVariant=Line,
    error=Color(0xFFB12E3B),errorContainer=Color(0xFFFFE9EC),onErrorContainer=Color(0xFF842432))
private val type=Typography(
    headlineLarge=TextStyle(fontSize=32.sp,lineHeight=38.sp,fontWeight=FontWeight.Bold,letterSpacing=(-.7).sp),
    headlineMedium=TextStyle(fontSize=28.sp,lineHeight=34.sp,fontWeight=FontWeight.Bold,letterSpacing=(-.5).sp),
    headlineSmall=TextStyle(fontSize=24.sp,lineHeight=30.sp,fontWeight=FontWeight.Bold,letterSpacing=(-.3).sp),
    titleLarge=TextStyle(fontSize=22.sp,lineHeight=28.sp,fontWeight=FontWeight.Bold),
    titleMedium=TextStyle(fontSize=18.sp,lineHeight=24.sp,fontWeight=FontWeight.Bold),
    titleSmall=TextStyle(fontSize=15.sp,lineHeight=21.sp,fontWeight=FontWeight.Bold),
    bodyLarge=TextStyle(fontSize=16.sp,lineHeight=24.sp),
    bodyMedium=TextStyle(fontSize=14.sp,lineHeight=21.sp),
    bodySmall=TextStyle(fontSize=12.sp,lineHeight=18.sp),
    labelLarge=TextStyle(fontSize=14.sp,lineHeight=20.sp,fontWeight=FontWeight.Bold),
    labelMedium=TextStyle(fontSize=12.sp,lineHeight=18.sp,fontWeight=FontWeight.SemiBold),
    labelSmall=TextStyle(fontSize=11.sp,lineHeight=16.sp,fontWeight=FontWeight.Bold,letterSpacing=.7.sp))
@Composable fun AfaTheme(content:@Composable ()->Unit){
    MaterialTheme(colorScheme=colors,typography=type,shapes=Shapes(
        extraSmall=RoundedCornerShape(8.dp),small=RoundedCornerShape(10.dp),medium=RoundedCornerShape(12.dp),
        large=RoundedCornerShape(18.dp),extraLarge=RoundedCornerShape(24.dp)),content=content)
}

@Composable fun inputColors()=OutlinedTextFieldDefaults.colors(
    focusedContainerColor=Color.White,unfocusedContainerColor=Color.White,
    disabledContainerColor=Color(0xFFE8EDF4),focusedBorderColor=Blue,
    unfocusedBorderColor=Color(0xFF8A9CB5),focusedLabelColor=Blue,unfocusedLabelColor=Muted)

@Composable fun PageIntro(title:String,description:String,icon:ImageVector){
    Row(Modifier.fillMaxWidth().padding(top=4.dp,bottom=12.dp),verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(14.dp)){
        Surface(color=MaterialTheme.colorScheme.primaryContainer,shape=RoundedCornerShape(14.dp)){
            Icon(icon,null,Modifier.padding(12.dp).size(26.dp),tint=Blue)
        }
        Column(Modifier.weight(1f)){
            Text(title,style=MaterialTheme.typography.headlineSmall,modifier=Modifier.semantics{heading()})
            if(description.isNotBlank())Text(description,Modifier.padding(top=4.dp),style=MaterialTheme.typography.bodyMedium,color=Muted)
        }
    }
}

@Composable fun FormFields(fields:List<Field>,data:JsonObject,onChange:(String,Any?)->Unit){
    var previous=""
    fields.forEach { field->
        if(field.group!=previous){SectionLabel(field.group);previous=field.group}
        FormField(field,data,onChange)
    }
}
@Composable fun SectionLabel(text:String){
    Row(Modifier.fillMaxWidth().padding(top=22.dp,bottom=12.dp),verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(10.dp)){
        Box(Modifier.size(4.dp,22.dp).background(Blue,RoundedCornerShape(2.dp)))
        Text(text,style=MaterialTheme.typography.titleMedium,color=Ink,modifier=Modifier.weight(1f).semantics{heading()})
    }
}
@Composable fun FormField(field:Field,data:JsonObject,onChange:(String,Any?)->Unit){
    val value=data.s(field.key)
    val title=field.title+if(field.required)" *" else ""
    when(field.kind){
        FieldKind.BOOL->Surface(color=Color.White,shape=MaterialTheme.shapes.medium,border=BorderStroke(1.dp,Line),modifier=Modifier.fillMaxWidth().padding(vertical=6.dp)){
            Row(Modifier.heightIn(min=64.dp).toggleableRow(field.enabled){onChange(field.key,if(data.flag(field.key))0 else 1)}.padding(horizontal=14.dp),verticalAlignment=Alignment.CenterVertically){
                Text(field.title,Modifier.weight(1f).padding(end=10.dp),style=MaterialTheme.typography.titleSmall,color=if(field.enabled)Ink else Muted)
                Switch(data.flag(field.key),onCheckedChange={onChange(field.key,if(it)1 else 0)},enabled=field.enabled)
            }
        }
        FieldKind.SELECT,FieldKind.MULTI->{
            var open by remember(field.key){mutableStateOf(false)}
            val selected=if(field.kind==FieldKind.MULTI)data.at(field.key).arrayOrEmpty().map{it.text()} else listOf(value)
            val label=field.choices.filter{it.id in selected}.joinToString{it.title}.ifBlank{if(field.kind==FieldKind.MULTI)"Sin selección" else value}
            SelectionField(title,label.ifBlank{"Seleccionar"},if(field.kind==FieldKind.MULTI)Icons.Rounded.Checklist else Icons.Rounded.UnfoldMore,field.enabled){open=true}
            if(open)ChoiceDialog(title,field.choices,selected,field.kind==FieldKind.MULTI,onDismiss={open=false}){ids->onChange(field.key,if(field.kind==FieldKind.MULTI)ids else ids.firstOrNull().orEmpty());if(field.kind!=FieldKind.MULTI)open=false}
        }
        FieldKind.DATE->{val context=LocalContext.current;SelectionField(title,value.ifBlank{"Seleccionar fecha"},Icons.Rounded.CalendarMonth,field.enabled){val date=runCatching{LocalDate.parse(value)}.getOrElse{LocalDate.now()};DatePickerDialog(context,{_,y,m,d->onChange(field.key,LocalDate.of(y,m+1,d).toString())},date.year,date.monthValue-1,date.dayOfMonth).show()}}
        else->{var visible by remember(field.key){mutableStateOf(false)}
            OutlinedTextField(value,{onChange(field.key,it)},Modifier.fillMaxWidth().padding(vertical=6.dp),label={Text(title,style=MaterialTheme.typography.labelLarge)},colors=inputColors(),shape=MaterialTheme.shapes.medium,enabled=field.enabled,singleLine=field.kind!=FieldKind.LONG,minLines=if(field.kind==FieldKind.LONG)3 else 1,
                keyboardOptions=KeyboardOptions(keyboardType=when(field.kind){FieldKind.NUMBER->KeyboardType.Decimal;FieldKind.EMAIL->KeyboardType.Email;FieldKind.PHONE->KeyboardType.Phone;FieldKind.PASSWORD->KeyboardType.Password;else->KeyboardType.Text},imeAction=if(field.kind==FieldKind.LONG)ImeAction.Default else ImeAction.Next),
                visualTransformation=if(field.kind==FieldKind.PASSWORD&&!visible)PasswordVisualTransformation() else VisualTransformation.None,
                trailingIcon=if(field.kind==FieldKind.PASSWORD){{IconButton(onClick={visible=!visible}){Icon(if(visible)Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility,if(visible)"Ocultar" else "Mostrar")}}}else null)
        }
    }
}
@Composable private fun SelectionField(title:String,value:String,icon:ImageVector,enabled:Boolean,onClick:()->Unit){
    Column(Modifier.fillMaxWidth().padding(vertical=7.dp).clickable(enabled=enabled,role=Role.Button,onClick=onClick)){
        Text(title,style=MaterialTheme.typography.labelLarge,color=if(enabled)Ink else Muted,modifier=Modifier.padding(start=2.dp,bottom=6.dp))
        Surface(color=if(enabled)Color.White else Color(0xFFE8EDF4),shape=MaterialTheme.shapes.medium,border=BorderStroke(1.dp,Color(0xFF8A9CB5)),modifier=Modifier.fillMaxWidth()){
            Row(Modifier.heightIn(min=56.dp).padding(start=14.dp,end=8.dp,top=8.dp,bottom=8.dp),verticalAlignment=Alignment.CenterVertically){
                Text(value,Modifier.weight(1f).padding(end=10.dp),style=MaterialTheme.typography.bodyLarge,color=if(enabled)Ink else Muted,maxLines=4,overflow=TextOverflow.Ellipsis)
                Surface(color=MaterialTheme.colorScheme.primaryContainer,shape=RoundedCornerShape(8.dp)){Icon(icon,"Seleccionar $title",Modifier.padding(7.dp).size(22.dp),tint=if(enabled)Blue else Muted)}
            }
        }
    }
}
private fun Modifier.toggleableRow(enabled:Boolean,click:()->Unit)=clickable(enabled=enabled,onClick=click)
@Composable fun ChoiceDialog(title:String,options:List<Choice>,selected:List<String>,multi:Boolean,onDismiss:()->Unit,onSelect:(List<String>)->Unit){
    var query by remember{mutableStateOf("")}
    val filtered=options.filter{it.title.contains(query,true)||it.id.contains(query,true)}
    AlertDialog(onDismissRequest=onDismiss,title={Text(title)},text={Column(Modifier.fillMaxWidth()){
        OutlinedTextField(query,{query=it},Modifier.fillMaxWidth(),label={Text("Buscar")},singleLine=true,colors=inputColors(),leadingIcon={Icon(Icons.Rounded.Search,null)})
        LazyColumn(Modifier.heightIn(max=380.dp)){items(filtered){choice->Row(Modifier.fillMaxWidth().clickable{onSelect(if(multi){if(choice.id in selected)selected-choice.id else selected+choice.id}else listOf(choice.id))}.padding(vertical=8.dp),verticalAlignment=Alignment.CenterVertically){if(multi)Checkbox(choice.id in selected,null) else RadioButton(choice.id in selected,null);Text(choice.title,Modifier.weight(1f))}}}
        if(filtered.isEmpty())Text("Sin opciones disponibles",Modifier.padding(16.dp))
    }},confirmButton={TextButton(onDismiss){Text("Listo")}},dismissButton={if(!multi)TextButton(onClick={onSelect(emptyList())}){Text("Limpiar")}})
}

@Composable fun Notice(text:String,error:Boolean=false){
    val foreground=if(error)MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onPrimaryContainer
    Surface(color=if(error)MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.primaryContainer,shape=MaterialTheme.shapes.medium,modifier=Modifier.fillMaxWidth().padding(vertical=10.dp)){
        Row(Modifier.padding(14.dp),horizontalArrangement=Arrangement.spacedBy(10.dp)){
            Icon(if(error)Icons.Rounded.ErrorOutline else Icons.Rounded.Info,null,Modifier.size(21.dp),tint=foreground)
            Text(plain(text),color=foreground,style=MaterialTheme.typography.bodyMedium)
        }
    }
}
fun plain(value:String):String=if('<' in value)HtmlCompat.fromHtml(value,HtmlCompat.FROM_HTML_MODE_LEGACY).toString().trim() else value
fun amount(value:String):String=value.toBigDecimalOrNull()?.let{java.text.NumberFormat.getCurrencyInstance(java.util.Locale.forLanguageTag("es-MX")).format(it)}?:value
@Composable fun EmptyState(title:String,text:String){Column(Modifier.fillMaxWidth().padding(vertical=40.dp),horizontalAlignment=Alignment.CenterHorizontally){Icon(Icons.Rounded.Search,null,Modifier.size(42.dp),tint=Teal);Text(title,style=MaterialTheme.typography.titleMedium,modifier=Modifier.padding(top=16.dp));Text(text,style=MaterialTheme.typography.bodyMedium,color=MaterialTheme.colorScheme.onSurfaceVariant,modifier=Modifier.padding(top=8.dp))}}

val labels=mapOf("id" to "Folio","no_venta" to "Número de venta","id_fase" to "Fase","cliente" to "Cliente","rfc" to "RFC","correo" to "Correo","telefono" to "Teléfono","telefono_alt" to "Teléfono alternativo","comentario" to "Comentario / Pack ID","direccion" to "Dirección de envío","direccion_envio" to "Dirección de envío","productos" to "Productos","movimientos" to "Movimientos","movimientos_contables" to "Movimientos contables","guias" to "Guías","seguimiento" to "Seguimiento","empresa_razon" to "Empresa","almacen" to "Almacén","sku" to "SKU","cantidad" to "Cantidad","precio" to "Precio","costo" to "Costo","series" to "Series","created_at" to "Fecha de creación","updated_at" to "Última actualización","fecha" to "Fecha","razon_social" to "Razón social","descripcion" to "Descripción","uuid" to "UUID","folio" to "Folio fiscal","series_factura" to "Series en factura","nota_de_credito" to "Nota de crédito vinculada","garantia_devolucion" to "Garantía / devolución","request" to "Solicitud de timbrado","status" to "Estado","documents_status" to "Archivos fiscales","blockers" to "Bloqueos","valid" to "Validación","receiver" to "Receptor","name" to "Nombre","documentos" to "Documentos","series_factura" to "Series en factura","serie" to "Serie","cp" to "Código postal","codigo_postal" to "Código postal","tipo" to "Tipo","estado" to "Estado","asignado_a" to "Asignado a","resolucion" to "Resolución","creador" to "Creado por","tecnico" to "Técnico","assigned_at" to "Asignado el","started_at" to "Revisión iniciada","resolved_at" to "Resuelto el","titulo" to "Título")
private val privateKeys=setOf("token","password","secret","app_id","api","extra_1","extra_2","data","file","xml","pdf","excel","excel_data","imagenes","imagenes_anteriores","archivos","archivos_factura","payload","empresas","subniveles","marketplaces","pivot","url","link","bd","tag","contrasena","id_tipo","document_type","can_hub","can_external","is_active","idempotency_key","request_payload","xml_sha256","pdf_sha256")
fun titleFor(key:String)=labels[key]?:key.replace('_',' ').replaceFirstChar{it.titlecase()}
@Composable fun DataDetails(data:JsonObject,depth:Int=0){
    data.entrySet().filter{!it.key.startsWith("_")&&it.key.lowercase() !in privateKeys&&!it.value.isJsonNull}.forEach{(key,value)->
        when{
            value.isJsonPrimitive&&value.text().isNotBlank()->Column(Modifier.fillMaxWidth()){
                Row(Modifier.fillMaxWidth().padding(vertical=11.dp),horizontalArrangement=Arrangement.spacedBy(14.dp)){
                    Text(titleFor(key),Modifier.weight(.4f),style=MaterialTheme.typography.bodyMedium,color=Muted)
                    Text(displayValue(key,value.text()),Modifier.weight(.6f),style=MaterialTheme.typography.bodyMedium,fontWeight=FontWeight.SemiBold,color=Ink)
                }
                HorizontalDivider(color=Line.copy(alpha=.55f))
            }
            value.isJsonObject&&value.asJsonObject.size()>0->DetailGroup(titleFor(key),depth==0&&key in setOf("resumen","direccion","direccion_envio","receptor","resultado")){DataDetails(value.asJsonObject,depth+1)}
            value.isJsonArray&&value.asJsonArray.size()>0->DetailGroup("${titleFor(key)} · ${value.asJsonArray.size()}",key=="productos"){value.asJsonArray.forEachIndexed{index,element->if(element.isJsonObject){if(index>0)HorizontalDivider(Modifier.padding(vertical=8.dp));DataDetails(element.asJsonObject,depth+1)} else Text(plain(element.text()),Modifier.padding(vertical=4.dp))}}
        }
    }
}
fun displayValue(key:String,value:String):String = when {
    key in setOf("precio","costo","total","mkt_total","importe","saldo","ultimo_costo","costo_promedio")->amount(value)
    key in setOf("status","documents_status","estado")->mapOf("pending" to "Pendiente","sending" to "Enviando","uncertain" to "Requiere conciliación","stamped" to "Timbrado","retrieved" to "Archivos disponibles","failed" to "Falló","en_revision" to "En revisión","nuevo" to "Nuevo","asignado" to "Asignado","resuelto" to "Resuelto","cerrado" to "Cerrado")[value]?:plain(value)
    else->plain(value)
}
fun documentDisplay(d:JsonObject):JsonObject {
    fun select(vararg keys:String)=JsonObject().apply{keys.forEach{key->d[key]?.let{add(key,it)}}}
    val summary=arrayOf("no_venta","comentario","marketplace","area","empresa_razon","almacen","total","id_fase","status","uuid","factura_serie","factura_folio","referencia","observacion")
    val covered=summary.toSet()+setOf("id","cliente","rfc","correo","telefono","telefono_alt","direccion","productos","guias","seguimiento","movimientos_contables","nota_de_credito","garantia_devolucion","pedido","documento_original","refacturacion")
    return obj("resumen" to select(*summary),
        "cliente" to select("cliente","rfc","correo","telefono","telefono_alt"),"direccion" to d["direccion"],"productos" to d["productos"],"guias" to d["guias"],"seguimiento" to d["seguimiento"],"movimientos_contables" to d["movimientos_contables"],"documentos_vinculados" to select("nota_de_credito","garantia_devolucion","pedido","documento_original","refacturacion"))
        .changed("otros_datos",JsonObject().apply{d.entrySet().filter{it.key !in covered&&it.key !in privateKeys&&!it.key.startsWith("_")}.forEach{(k,v)->add(k,v)}})
}
@Composable fun DetailGroup(title:String,initiallyOpen:Boolean=false,content:@Composable ColumnScope.()->Unit){
    var open by rememberSaveable(title){mutableStateOf(initiallyOpen)}
    val header by animateColorAsState(if(open)Ink else Color(0xFFE1E9F6),label="accordionHeader")
    val foreground=if(open)Color.White else Ink
    Surface(modifier=Modifier.fillMaxWidth().padding(vertical=7.dp),shape=MaterialTheme.shapes.large,color=Color.White,border=BorderStroke(1.dp,if(open)Ink else Color(0xFFBCCCE3))){
        Column{
            Row(Modifier.fillMaxWidth().background(header).semantics{stateDescription=if(open)"Expandido" else "Contraído"}.clickable(role=Role.Button,onClickLabel=if(open)"Contraer $title" else "Expandir $title"){open=!open}.heightIn(min=62.dp).padding(horizontal=16.dp,vertical=12.dp),verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(12.dp)){
                Column(Modifier.weight(1f)){
                    Text(title,style=MaterialTheme.typography.titleSmall,color=foreground)
                    Text(if(open)"Ocultar información" else "Ver información",style=MaterialTheme.typography.labelMedium,color=if(open)Color(0xFFBECEEA) else Muted)
                }
                Surface(color=if(open)Color(0xFF385477) else Color.White,shape=RoundedCornerShape(9.dp)){
                    Icon(if(open)Icons.Rounded.Remove else Icons.Rounded.Add,null,Modifier.padding(6.dp).size(22.dp),tint=foreground)
                }
            }
            AnimatedVisibility(open){Column(Modifier.padding(horizontal=16.dp,vertical=10.dp),content=content)}
        }
    }
}

fun sectionColor(section:Section):Color=when(section){
    Section.DOCUMENTS,Section.SALES->Blue
    Section.TICKETS->Color(0xFF875315)
    Section.BILLING->Teal
    Section.SERIES,Section.STOCK,Section.PRODUCTS,Section.WAREHOUSES->Color(0xFF6950AD)
    else->Color(0xFF3F618A)
}
@Composable fun StatusBadge(text:String){
    if(text.isBlank())return
    val value=displayValue("status",text)
    val tint=when(text.lowercase()){"resuelto","cerrado","stamped","retrieved"->Teal;"failed","uncertain"->MaterialTheme.colorScheme.error;else->Color(0xFF83520A)}
    Surface(color=tint.copy(alpha=.1f),shape=RoundedCornerShape(7.dp)){
        Row(Modifier.padding(horizontal=9.dp,vertical=5.dp),verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(6.dp)){
            Box(Modifier.size(6.dp).background(tint,RoundedCornerShape(3.dp)))
            Text(value,style=MaterialTheme.typography.labelMedium,color=tint)
        }
    }
}

@Composable fun RecordCard(section:Section,row:JsonObject,onClick:()->Unit,selected:Boolean=false,onSelect:(()->Unit)?=null,footer:(@Composable ()->Unit)?=null){
    val title=when(section){Section.DOCUMENTS,Section.SALES,Section.BILLING->"${if(row.s("_kind")=="credit"||row.n("id_tipo")==6)"Nota de crédito" else "Pedido"} #${row.s("id")}";Section.TICKETS->row.label("titulo","id");Section.MARKETPLACES->row.label("marketplace.marketplace","marketplace");Section.WAREHOUSES->row.s("almacen");else->row.label("descripcion","razon_social","nombre","empresa","codigo","sku","_sku","id")}
    val accent=sectionColor(section)
    Card(onClick=onClick,modifier=Modifier.fillMaxWidth().padding(vertical=7.dp),shape=MaterialTheme.shapes.large,border=BorderStroke(if(selected)2.dp else 1.dp,if(selected)Blue else Line),elevation=CardDefaults.cardElevation(defaultElevation=1.dp),colors=CardDefaults.cardColors(containerColor=if(selected)Color(0xFFF0F5FF) else Color.White)){
        Column(Modifier.padding(18.dp)){
            Row(verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(8.dp)){
                Icon(sectionIcon(section),null,Modifier.size(18.dp),tint=accent)
                Text(section.title.uppercase(java.util.Locale.forLanguageTag("es")),Modifier.weight(1f),style=MaterialTheme.typography.labelSmall,color=accent)
                if(onSelect!=null)Checkbox(selected,{onSelect()})
            }
            Text(title,Modifier.padding(top=10.dp),style=MaterialTheme.typography.titleMedium)
            val primary=when(section){Section.DOCUMENTS,Section.SALES,Section.BILLING->row.s("cliente");Section.TICKETS->row.s("creador.nombre");Section.MARKETPLACES->row.s("area.area");else->row.label("sku","codigo","_sku","rfc","email","serie")}
            if(primary.isNotBlank()&&primary!="Sin nombre"&&primary!=title)Text(primary,Modifier.padding(top=6.dp),style=MaterialTheme.typography.bodyMedium)
            val detail=listOf(row.s("no_venta"),row.s("marketplace").ifBlank{row.s("estado")},row.s("almacen"),row.s("request.status")).filter(String::isNotBlank).joinToString(" · ")
            if(detail.isNotBlank())Text(detail,Modifier.padding(top=8.dp),style=MaterialTheme.typography.bodySmall,color=Muted)
            val status=row.s("request.status").ifBlank{row.s("estado")}
            if(status.isNotBlank()){Spacer(Modifier.height(10.dp));StatusBadge(status)}
            HorizontalDivider(Modifier.padding(top=14.dp,bottom=12.dp),color=Line)
            Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(10.dp)){
                val total=row.label("total","mkt_total")
                if(total!="Sin nombre")Text(amount(total),Modifier.weight(1f),style=MaterialTheme.typography.titleLarge,color=Ink)else Spacer(Modifier.weight(1f))
                Text(if(section==Section.BILLING)"Revisar" else "Ver detalle",style=MaterialTheme.typography.labelLarge,color=Blue)
                Surface(color=MaterialTheme.colorScheme.primaryContainer,shape=RoundedCornerShape(8.dp)){Icon(Icons.Rounded.ChevronRight,null,Modifier.padding(4.dp).size(20.dp),tint=Blue)}
            }
            footer?.invoke()
        }
    }
}
@Composable fun ActionButton(text:String,icon:ImageVector=Icons.Rounded.ChevronRight,enabled:Boolean=true,click:()->Unit){
    val destructive=icon==Icons.Rounded.DeleteOutline
    val tint=if(destructive)MaterialTheme.colorScheme.error else Blue
    val actualIcon=if(icon==Icons.Rounded.ChevronRight)Icons.Rounded.OpenInNew else icon
    FilledTonalButton(onClick=click,enabled=enabled,shape=MaterialTheme.shapes.medium,
        colors=ButtonDefaults.filledTonalButtonColors(containerColor=if(destructive)MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.primaryContainer,contentColor=tint),
        contentPadding=PaddingValues(horizontal=14.dp,vertical=12.dp),modifier=Modifier.fillMaxWidth().padding(vertical=5.dp).heightIn(min=54.dp)){
        Icon(actualIcon,null,Modifier.size(22.dp));Spacer(Modifier.width(12.dp))
        Text(text,Modifier.weight(1f),style=MaterialTheme.typography.labelLarge)
        Spacer(Modifier.width(8.dp));Icon(Icons.Rounded.ChevronRight,null,Modifier.size(20.dp))
    }
}
