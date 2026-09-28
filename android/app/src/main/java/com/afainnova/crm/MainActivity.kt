package com.afainnova.crm

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.OpenableColumns
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.FileProvider
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.lifecycleScope
import com.afainnova.crm.data.*
import com.afainnova.crm.ui.AfaApp
import kotlinx.coroutines.*
import java.io.File

class MainActivity:ComponentActivity() {
    private lateinit var vm:CrmViewModel
    private var fileTarget=""
    private val picker=registerForActivityResult(ActivityResultContracts.OpenDocument()){uri->if(uri!=null)readFile(uri)}
    override fun onCreate(savedInstanceState:Bundle?){
        super.onCreate(savedInstanceState);enableEdgeToEdge()
        val sessions=SessionStore(this)
        vm=ViewModelProvider(this,CrmViewModel.factory(repositoryFactory?.invoke(this)?:CrmRepository(ApiClient(sessions),sessions)))[CrmViewModel::class.java]
        lifecycleScope.launch {vm.events.collect{event->try{when(event){
            is UiEvent.Link->{val uri=Uri.parse(event.url);require(uri.scheme=="https"){ "El enlace recibido no es seguro." };startActivity(Intent(Intent.ACTION_VIEW,uri))}
            is UiEvent.File->{val file=withContext(Dispatchers.IO){val dir=File(cacheDir,"shared").apply{mkdirs()};File(dir,FileRules.safeName(event.name)).apply{writeBytes(FileRules.decode(event.base64))}};val uri=FileProvider.getUriForFile(this@MainActivity,"$packageName.files",file);val type=when(file.extension.lowercase()){ "pdf"->"application/pdf";"xml"->"application/xml";"xlsx"->"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";else->"application/octet-stream" };startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType(type).putExtra(Intent.EXTRA_STREAM,uri).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION),"Guardar o compartir ${file.name}"))}
        }}catch(e:Exception){vm.error(e.message?:"No se pudo abrir el archivo.")}}}
        setContent {AfaApp(vm,onFile={target->fileTarget=target;picker.launch(when(target){"pdf"->arrayOf("application/pdf");"xml"->arrayOf("text/xml","application/xml","application/octet-stream");"prices"->arrayOf("application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");"image"->arrayOf("image/*");else->arrayOf("*/*")})})}
    }
    private fun readFile(uri:Uri){lifecycleScope.launch{
        try{
            val record=withContext(Dispatchers.IO){
                var name="archivo"
                contentResolver.query(uri,arrayOf(OpenableColumns.DISPLAY_NAME,OpenableColumns.SIZE),null,null,null)?.use{cursor->if(cursor.moveToFirst()){name=cursor.getString(0);if(!cursor.isNull(1))require(cursor.getLong(1)<=FileRules.MAX_BYTES){ "El archivo supera el límite de 12 MB." }}}
                val bytes=contentResolver.openInputStream(uri)?.use{FileRules.readLimited(it)}?:error("No se pudo leer el archivo.")
                require(bytes.size<=FileRules.MAX_BYTES){ "El archivo supera el límite de 12 MB." }
                if(fileTarget=="pdf")FileRules.requirePdf(bytes)
                if(fileTarget=="prices")obj("prices" to FileRules.prices(bytes)) else {
                    val mime=contentResolver.getType(uri)?:"application/octet-stream"
                    obj("nombre" to FileRules.safeName(name),"tipo" to mime.substringBefore('/'),"data" to FileRules.dataUrl(bytes,mime))
                }
            }
            if(fileTarget=="prices")vm.change("precio.productos",record["prices"]) else vm.attach(record,fileTarget)
        }catch(e:Exception){vm.error(e.message?:"No se pudo adjuntar el archivo.")}
    }}
    companion object {
        // Tests install a fake transport explicitly before launching. Never selected from intents or preferences.
        @Volatile internal var repositoryFactory:((android.content.Context)->CrmRepository)?=null
    }
}
