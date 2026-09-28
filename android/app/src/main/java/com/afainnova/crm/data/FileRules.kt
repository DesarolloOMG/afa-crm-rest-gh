package com.afainnova.crm.data

import java.io.ByteArrayInputStream
import java.util.Base64
import java.util.zip.ZipInputStream
import javax.xml.parsers.DocumentBuilderFactory
import com.google.gson.JsonObject
import org.w3c.dom.Element

object FileRules {
    const val MAX_BYTES=12*1024*1024
    fun readLimited(input:java.io.InputStream,limit:Int=MAX_BYTES):ByteArray {
        val output=java.io.ByteArrayOutputStream();val buffer=ByteArray(8192)
        while(true){val count=input.read(buffer);if(count<0)break;require(output.size()+count<=limit){ "El archivo supera el tamaño permitido." };output.write(buffer,0,count)}
        return output.toByteArray()
    }
    fun decode(data:String):ByteArray {
        val raw=data.substringAfter("base64,",data)
        require(raw.length<=MAX_BYTES*4/3+16){ "El archivo supera el límite de 12 MB." }
        return Base64.getMimeDecoder().decode(raw)
    }
    fun dataUrl(bytes:ByteArray,mime:String):String {
        require(bytes.size<=MAX_BYTES){ "El archivo supera el límite de 12 MB." }
        return "data:$mime;base64,"+Base64.getEncoder().encodeToString(bytes)
    }
    fun safeName(name:String)=name.substringAfterLast('/').substringAfterLast('\\').replace(Regex("[^\\p{L}\\p{N} ._-]"),"_").take(120).ifBlank{"archivo"}
    private fun xml(bytes:ByteArray)=DocumentBuilderFactory.newInstance().apply {
        isNamespaceAware=true
        setFeature("http://apache.org/xml/features/disallow-doctype-decl",true)
        setFeature("http://xml.org/sax/features/external-general-entities",false)
        setFeature("http://xml.org/sax/features/external-parameter-entities",false)
        isExpandEntityReferences=false
    }.newDocumentBuilder().parse(ByteArrayInputStream(bytes))
    fun cfdi(data:String):JsonObject {
        val doc=xml(decode(data))
        val root=doc.documentElement
        require(root.localName=="Comprobante"&&root.namespaceURI in setOf("http://www.sat.gob.mx/cfd/4","http://www.sat.gob.mx/cfd/3")){ "El archivo no es un CFDI válido." }
        val timbres=doc.getElementsByTagNameNS("http://www.sat.gob.mx/TimbreFiscalDigital","TimbreFiscalDigital")
        require(timbres.length==1){ "El XML debe incluir un timbre fiscal." }
        val uuid=(timbres.item(0) as Element).getAttribute("UUID")
        require(Regex("[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}").matches(uuid)){ "El UUID del XML no es válido." }
        return obj("uuid" to uuid.uppercase(),"tipo" to root.getAttribute("TipoDeComprobante"),"serie" to root.getAttribute("Serie"),"folio" to root.getAttribute("Folio"))
    }
    fun requirePdf(bytes:ByteArray){require(bytes.size>5&&String(bytes.copyOfRange(0,5),Charsets.US_ASCII)=="%PDF-"){ "Selecciona un archivo PDF válido." }}
    /** Read the two columns used by the existing product-price import. No macros or formulas execute. */
    fun prices(bytes:ByteArray):List<JsonObject> {
        val files=mutableMapOf<String,ByteArray>();var expanded=0
        ZipInputStream(ByteArrayInputStream(bytes)).use { zip->
            while(true){val entry=zip.nextEntry?:break
                if(entry.name in setOf("xl/sharedStrings.xml","xl/worksheets/sheet1.xml")){
                    val content=readLimited(zip);expanded+=content.size;require(expanded<=MAX_BYTES){ "La hoja es demasiado grande." };files[entry.name]=content
                }
            }
        }
        val strings=files["xl/sharedStrings.xml"]?.let { xml(it).getElementsByTagNameNS("*","si") }?.let{nodes->(0 until nodes.length).map{nodes.item(it).textContent}}?:emptyList()
        val sheet=files["xl/worksheets/sheet1.xml"]?:error("No se encontró la primera hoja del Excel.")
        val rows=xml(sheet).getElementsByTagNameNS("*","row")
        return (1 until rows.length).mapNotNull { index->
            val cells=(rows.item(index) as Element).getElementsByTagNameNS("*","c")
            val values=mutableMapOf<String,String>()
            for(i in 0 until cells.length){val cell=cells.item(i) as Element;val raw=cell.getElementsByTagNameNS("*","v").item(0)?.textContent?:cell.textContent;values[cell.getAttribute("r").takeWhile(Char::isLetter)]=if(cell.getAttribute("t")=="s")strings.getOrElse(raw.toIntOrNull()?:-1){""} else raw}
            val sku=values["A"].orEmpty().trim();if(sku.isBlank())null else {val price=values["B"]?.toBigDecimalOrNull()?:error("Precio inválido en la fila ${index+1}");require(price.signum()>=0){ "El precio no puede ser negativo (fila ${index+1})." };obj("codigo" to sku,"precio" to price)}
        }
    }
}
