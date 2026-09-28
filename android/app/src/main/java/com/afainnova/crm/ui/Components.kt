@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class,androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
package com.afainnova.crm.ui

import android.app.DatePickerDialog
import androidx.compose.foundation.*
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.Alignment
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.*
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.core.text.HtmlCompat
import com.afainnova.crm.data.*
import com.google.gson.*
import java.time.LocalDate

val Teal=Color(0xFF006B62)
private val colors=lightColorScheme(primary=Teal,onPrimary=Color.White,primaryContainer=Color(0xFFCBEDE4),onPrimaryContainer=Color(0xFF002D29),secondary=Color(0xFF526861),secondaryContainer=Color(0xFFE0ECE7),background=Color(0xFFF5F7F4),surface=Color(0xFFFDFDFB),surfaceVariant=Color(0xFFE7ECE6),error=Color(0xFFAD3034))
@Composable fun AfaTheme(content:@Composable ()->Unit){MaterialTheme(colorScheme=colors,typography=Typography(),content=content)}

@Composable fun FormFields(fields:List<Field>,data:JsonObject,onChange:(String,Any?)->Unit){
    var previous=""
    fields.forEach { field->
        if(field.group!=previous){SectionLabel(field.group);previous=field.group}
        FormField(field,data,onChange)
    }
}
@Composable fun SectionLabel(text:String){Text(text,style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.SemiBold,color=Teal,modifier=Modifier.padding(top=16.dp,bottom=8.dp))}
@Composable fun FormField(field:Field,data:JsonObject,onChange:(String,Any?)->Unit){
    val value=data.s(field.key)
    val title=field.title+if(field.required)" *" else ""
    when(field.kind){
        FieldKind.BOOL->Row(Modifier.fillMaxWidth().heightIn(min=52.dp).toggleableRow(field.enabled){onChange(field.key,if(data.flag(field.key))0 else 1)},verticalAlignment=Alignment.CenterVertically){Text(field.title,Modifier.weight(1f));Switch(data.flag(field.key),onCheckedChange={onChange(field.key,if(it)1 else 0)},enabled=field.enabled)}
        FieldKind.SELECT,FieldKind.MULTI->{
            var open by remember(field.key){mutableStateOf(false)}
            val selected=if(field.kind==FieldKind.MULTI)data.at(field.key).arrayOrEmpty().map{it.text()} else listOf(value)
            val label=field.choices.filter{it.id in selected}.joinToString{it.title}.ifBlank{if(field.kind==FieldKind.MULTI)"Sin selección" else value}
            OutlinedCard(onClick={if(field.enabled)open=true},modifier=Modifier.fillMaxWidth().padding(vertical=4.dp),enabled=field.enabled){Row(Modifier.padding(14.dp),verticalAlignment=Alignment.CenterVertically){Column(Modifier.weight(1f)){Text(title,style=MaterialTheme.typography.labelMedium,color=Teal);Text(label.ifBlank{"Seleccionar"},maxLines=3,overflow=TextOverflow.Ellipsis)};Icon(Icons.Rounded.ExpandMore,"Seleccionar")}}
            if(open)ChoiceDialog(title,field.choices,selected,field.kind==FieldKind.MULTI,onDismiss={open=false}){ids->onChange(field.key,if(field.kind==FieldKind.MULTI)ids else ids.firstOrNull().orEmpty());if(field.kind!=FieldKind.MULTI)open=false}
        }
        FieldKind.DATE->{val context=LocalContext.current;OutlinedButton(onClick={val date=runCatching{LocalDate.parse(value)}.getOrElse{LocalDate.now()};DatePickerDialog(context,{_,y,m,d->onChange(field.key,LocalDate.of(y,m+1,d).toString())},date.year,date.monthValue-1,date.dayOfMonth).show()},modifier=Modifier.fillMaxWidth().heightIn(min=52.dp),enabled=field.enabled){Icon(Icons.Rounded.CalendarMonth,null);Spacer(Modifier.width(8.dp));Text("$title: ${value.ifBlank{"Seleccionar"}}")}}
        else->{var visible by remember(field.key){mutableStateOf(false)}
            OutlinedTextField(value,{onChange(field.key,it)},Modifier.fillMaxWidth().padding(vertical=4.dp),label={Text(title)},enabled=field.enabled,singleLine=field.kind!=FieldKind.LONG,minLines=if(field.kind==FieldKind.LONG)3 else 1,
                keyboardOptions=KeyboardOptions(keyboardType=when(field.kind){FieldKind.NUMBER->KeyboardType.Decimal;FieldKind.EMAIL->KeyboardType.Email;FieldKind.PHONE->KeyboardType.Phone;FieldKind.PASSWORD->KeyboardType.Password;else->KeyboardType.Text},imeAction=if(field.kind==FieldKind.LONG)ImeAction.Default else ImeAction.Next),
                visualTransformation=if(field.kind==FieldKind.PASSWORD&&!visible)PasswordVisualTransformation() else VisualTransformation.None,
                trailingIcon=if(field.kind==FieldKind.PASSWORD){{IconButton(onClick={visible=!visible}){Icon(if(visible)Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility,if(visible)"Ocultar" else "Mostrar")}}}else null)
        }
    }
}
private fun Modifier.toggleableRow(enabled:Boolean,click:()->Unit)=clickable(enabled=enabled,onClick=click)
@Composable fun ChoiceDialog(title:String,options:List<Choice>,selected:List<String>,multi:Boolean,onDismiss:()->Unit,onSelect:(List<String>)->Unit){
    var query by remember{mutableStateOf("")}
    val filtered=options.filter{it.title.contains(query,true)||it.id.contains(query,true)}
    AlertDialog(onDismissRequest=onDismiss,title={Text(title)},text={Column(Modifier.fillMaxWidth()){
        OutlinedTextField(query,{query=it},Modifier.fillMaxWidth(),label={Text("Buscar")},singleLine=true)
        LazyColumn(Modifier.heightIn(max=380.dp)){items(filtered){choice->Row(Modifier.fillMaxWidth().clickable{onSelect(if(multi){if(choice.id in selected)selected-choice.id else selected+choice.id}else listOf(choice.id))}.padding(vertical=8.dp),verticalAlignment=Alignment.CenterVertically){if(multi)Checkbox(choice.id in selected,null) else RadioButton(choice.id in selected,null);Text(choice.title,Modifier.weight(1f))}}}
        if(filtered.isEmpty())Text("Sin opciones disponibles",Modifier.padding(16.dp))
    }},confirmButton={TextButton(onDismiss){Text("Listo")}},dismissButton={if(!multi)TextButton(onClick={onSelect(emptyList())}){Text("Limpiar")}})
}

@Composable fun Notice(text:String,error:Boolean=false){Surface(color=if(error)MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.secondaryContainer,shape=MaterialTheme.shapes.medium,modifier=Modifier.fillMaxWidth().padding(vertical=8.dp)){Text(plain(text),Modifier.padding(16.dp),color=if(error)MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onSecondaryContainer)}}
fun plain(value:String):String=if('<' in value)HtmlCompat.fromHtml(value,HtmlCompat.FROM_HTML_MODE_LEGACY).toString().trim() else value
fun amount(value:String):String=value.toBigDecimalOrNull()?.let{java.text.NumberFormat.getCurrencyInstance(java.util.Locale.forLanguageTag("es-MX")).format(it)}?:value
@Composable fun EmptyState(title:String,text:String){Column(Modifier.fillMaxWidth().padding(vertical=40.dp),horizontalAlignment=Alignment.CenterHorizontally){Icon(Icons.Rounded.Search,null,Modifier.size(42.dp),tint=Teal);Text(title,style=MaterialTheme.typography.titleMedium,modifier=Modifier.padding(top=16.dp));Text(text,style=MaterialTheme.typography.bodyMedium,color=MaterialTheme.colorScheme.onSurfaceVariant,modifier=Modifier.padding(top=8.dp))}}

val labels=mapOf("id" to "Folio","no_venta" to "Número de venta","id_fase" to "Fase","cliente" to "Cliente","rfc" to "RFC","correo" to "Correo","telefono" to "Teléfono","telefono_alt" to "Teléfono alternativo","comentario" to "Comentario / Pack ID","direccion" to "Dirección de envío","direccion_envio" to "Dirección de envío","productos" to "Productos","movimientos" to "Movimientos","movimientos_contables" to "Movimientos contables","guias" to "Guías","seguimiento" to "Seguimiento","empresa_razon" to "Empresa","almacen" to "Almacén","sku" to "SKU","cantidad" to "Cantidad","precio" to "Precio","costo" to "Costo","series" to "Series","created_at" to "Fecha de creación","updated_at" to "Última actualización","fecha" to "Fecha","razon_social" to "Razón social","descripcion" to "Descripción","uuid" to "UUID","folio" to "Folio fiscal","series_factura" to "Series en factura","nota_de_credito" to "Nota de crédito vinculada","garantia_devolucion" to "Garantía / devolución","request" to "Solicitud de timbrado","status" to "Estado","documents_status" to "Archivos fiscales","blockers" to "Bloqueos","valid" to "Validación","receiver" to "Receptor","name" to "Nombre","documentos" to "Documentos","series_factura" to "Series en factura","serie" to "Serie","cp" to "Código postal","codigo_postal" to "Código postal","tipo" to "Tipo","estado" to "Estado","asignado_a" to "Asignado a","resolucion" to "Resolución","creador" to "Creado por","tecnico" to "Técnico","assigned_at" to "Asignado el","started_at" to "Revisión iniciada","resolved_at" to "Resuelto el","titulo" to "Título")
private val privateKeys=setOf("token","password","secret","app_id","api","extra_1","extra_2","data","file","xml","pdf","excel","excel_data","imagenes","imagenes_anteriores","archivos","archivos_factura","payload","empresas","subniveles","marketplaces","pivot","url","link","bd","tag","contrasena","id_tipo","document_type","can_hub","can_external","is_active","idempotency_key","request_payload","xml_sha256","pdf_sha256")
fun titleFor(key:String)=labels[key]?:key.replace('_',' ').replaceFirstChar{it.titlecase()}
@Composable fun DataDetails(data:JsonObject,depth:Int=0){
    data.entrySet().filter{!it.key.startsWith("_")&&it.key.lowercase() !in privateKeys&&!it.value.isJsonNull}.forEach{(key,value)->
        when{
            value.isJsonPrimitive&&value.text().isNotBlank()->Row(Modifier.fillMaxWidth().padding(vertical=7.dp),horizontalArrangement=Arrangement.spacedBy(16.dp)){Text(titleFor(key),Modifier.weight(.42f),style=MaterialTheme.typography.bodyMedium,color=MaterialTheme.colorScheme.onSurfaceVariant);Text(displayValue(key,value.text()),Modifier.weight(.58f),style=MaterialTheme.typography.bodyMedium)}
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
@Composable fun DetailGroup(title:String,initiallyOpen:Boolean=false,content:@Composable ColumnScope.()->Unit){var open by remember(title){mutableStateOf(initiallyOpen)};OutlinedCard(Modifier.fillMaxWidth().padding(vertical=6.dp)){Column(Modifier.padding(14.dp)){Row(Modifier.fillMaxWidth().clickable{open=!open}.heightIn(min=42.dp),verticalAlignment=Alignment.CenterVertically){Text(title,Modifier.weight(1f),style=MaterialTheme.typography.titleSmall);Icon(if(open)Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore,null)};if(open)content()}}}

@Composable fun RecordCard(section:Section,row:JsonObject,onClick:()->Unit,selected:Boolean=false,onSelect:(()->Unit)?=null,footer:(@Composable ()->Unit)?=null){
    val title=when(section){Section.DOCUMENTS,Section.SALES,Section.BILLING->"${if(row.s("_kind")=="credit"||row.n("id_tipo")==6)"Nota de crédito" else "Pedido"} #${row.s("id")}";Section.TICKETS->row.label("titulo","id");Section.MARKETPLACES->row.label("marketplace.marketplace","marketplace");Section.WAREHOUSES->row.s("almacen");else->row.label("descripcion","razon_social","nombre","empresa","codigo","sku","_sku","id")}
    Card(onClick=onClick,modifier=Modifier.fillMaxWidth().padding(vertical=5.dp),colors=CardDefaults.cardColors(containerColor=if(selected)MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface)){
        Column(Modifier.padding(18.dp)){
            Row(verticalAlignment=Alignment.CenterVertically){Text(title,Modifier.weight(1f),style=MaterialTheme.typography.titleMedium,fontWeight=FontWeight.SemiBold);if(onSelect!=null)Checkbox(selected,{onSelect()}) else Icon(Icons.Rounded.ChevronRight,null,tint=Teal)}
            val primary=when(section){Section.DOCUMENTS,Section.SALES,Section.BILLING->row.s("cliente");Section.TICKETS->row.s("creador.nombre");Section.MARKETPLACES->row.s("area.area");else->row.label("sku","codigo","_sku","rfc","email","serie")}
            if(primary.isNotBlank()&&primary!="Sin nombre"&&primary!=title)Text(primary,Modifier.padding(top=6.dp),style=MaterialTheme.typography.bodyMedium)
            val detail=listOf(row.s("no_venta"),row.s("marketplace").ifBlank{row.s("estado")},row.s("almacen"),row.s("request.status")).filter(String::isNotBlank).joinToString(" · ")
            if(detail.isNotBlank())Text(detail,Modifier.padding(top=8.dp),style=MaterialTheme.typography.bodySmall,color=MaterialTheme.colorScheme.onSurfaceVariant)
            val total=row.label("total","mkt_total");if(total!="Sin nombre")Text(amount(total),Modifier.padding(top=10.dp),style=MaterialTheme.typography.titleMedium,color=Teal)
            footer?.invoke()
        }
    }
}
@Composable fun ActionButton(text:String,icon:androidx.compose.ui.graphics.vector.ImageVector=Icons.Rounded.ChevronRight,enabled:Boolean=true,click:()->Unit){OutlinedButton(onClick=click,enabled=enabled,modifier=Modifier.fillMaxWidth().heightIn(min=48.dp)){Icon(icon,null,Modifier.size(20.dp));Spacer(Modifier.width(10.dp));Text(text,Modifier.weight(1f))}}
