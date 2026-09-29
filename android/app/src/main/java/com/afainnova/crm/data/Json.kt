package com.afainnova.crm.data

import com.google.gson.*
import java.math.BigDecimal

val gson = Gson()
fun obj(vararg values: Pair<String, Any?>): JsonObject = JsonObject().apply { values.forEach { (key, value) -> add(key, gson.toJsonTree(value)) } }
fun JsonElement?.objectOrEmpty(): JsonObject = if (this?.isJsonObject == true) asJsonObject else JsonObject()
fun JsonElement?.arrayOrEmpty(): JsonArray = if (this?.isJsonArray == true) asJsonArray else JsonArray()
fun JsonElement?.text(): String = if (this == null || isJsonNull) "" else if (isJsonPrimitive) asString else ""
fun JsonElement?.objects(): List<JsonObject> = arrayOrEmpty().filter { it.isJsonObject }.map { it.asJsonObject }
fun JsonObject.at(path: String): JsonElement? = path.split('.').fold(this as JsonElement?) { node, key -> node?.objectOrEmpty()?.get(key) }
fun JsonObject.s(path: String): String = at(path).text()
fun JsonObject.n(path: String): Int = s(path).toDoubleOrNull()?.toInt() ?: 0
fun JsonObject.money(path: String): BigDecimal = s(path).toBigDecimalOrNull() ?: BigDecimal.ZERO
fun JsonObject.flag(path: String): Boolean = s(path).lowercase() in setOf("1", "true")
fun JsonObject.o(path: String): JsonObject = at(path).objectOrEmpty()
fun JsonObject.list(path: String): List<JsonObject> = at(path).objects()
fun JsonObject.put(path: String, value: Any?) {
    val keys = path.split('.')
    var target = this
    keys.dropLast(1).forEach { key ->
        if (target[key]?.isJsonObject != true) target.add(key, JsonObject())
        target = target.getAsJsonObject(key)
    }
    target.add(keys.last(), gson.toJsonTree(value))
}
fun JsonObject.changed(path: String, value: Any?): JsonObject = deepCopy().apply { put(path, value) }
fun JsonObject.label(vararg fields: String): String = fields.firstNotNullOfOrNull { s(it).takeIf(String::isNotBlank) } ?: "Sin nombre"
fun List<JsonObject>.json(): JsonArray = JsonArray().also { result -> forEach { result.add(it) } }
fun parseObject(text: String): JsonObject = JsonParser.parseString(text).objectOrEmpty()

enum class Encoding { DATA, JSON, FIELDS }
data class Call(val path: String, val method: String = "GET", val payload: JsonObject = obj(), val encoding: Encoding = Encoding.DATA, val authenticated: Boolean = true)
interface Transport { suspend fun execute(call: Call): JsonElement }
class ApiException(val status: Int, override val message: String): Exception(message)

enum class Section(val title: String, val subtitle: String, val levels: Set<Int> = emptySet(), val sublevel: Int = 0) {
    DOCUMENTS("Documentos", "Ventas, pedidos y notas de crédito"),
    TICKETS("Soporte", "Crear, asignar y resolver tickets"),
    SERIES("Series", "Historial y trazabilidad"),
    STOCK("Consultar productos", "Existencias, movimientos y costos"),
    PRODUCTS("Productos", "Gestionar el catálogo", setOf(12), 9),
    CUSTOMERS("Clientes", "Datos fiscales y comerciales", setOf(16), 28),
    SUPPLIERS("Proveedores", "Contactos y datos fiscales", setOf(16), 29),
    SALES("Ventas", "Crear, editar, eliminar y pendientes", setOf(8)),
    BILLING("Facturación", "Timbrado, globales y CFDI externos", setOf(11), 36),
    CANCELLATION("Cancelar facturas", "Nexfira y aprobación SAT", setOf(11), 36),
    USERS("Usuarios", "Accesos y permisos", setOf(6), 1),
    MARKETPLACES("Marketplaces", "Áreas, series y configuración", setOf(6), 1),
    WAREHOUSES("Almacenes", "Gestionar almacenes", setOf(6), 1),
    DEV("Herramientas Dev", "Conciliación y soporte Nexfira", setOf(6), 1)
}

data class User(val token: String, val profile: JsonObject, val expires: Long) {
    val id get() = profile.n("id")
    val name get() = profile.s("nombre")
    val levels get() = profile.at("niveles").arrayOrEmpty().mapNotNull { it.text().toIntOrNull() }.toSet()
    fun permission(level: Int, sub: Int): Boolean = profile.o("subniveles")[level.toString()].arrayOrEmpty().any { it.text() == sub.toString() }
    fun visible(section: Section): Boolean = when (section) {
        Section.BILLING, Section.CANCELLATION -> permission(11,36)
        Section.DEV -> permission(6,1)
        Section.SERIES, Section.STOCK -> 13 !in levels
        else -> admin || section.levels.isEmpty() || section.levels.any { it in levels && (section.sublevel==0 || permission(it,section.sublevel)) }
    }
    val admin get() = 6 in levels
}

val documentCriteria = listOf("id" to "Pedido", "no_venta" to "N° venta", "nota" to "Nota de crédito", "rfc" to "RFC cliente", "razon_social" to "Nombre cliente", "correo" to "Correo cliente", "referencia" to "Referencia del pedido", "observacion" to "Observación del pedido", "comentario" to "Comentario del pedido", "guia" to "Guía del pedido")
