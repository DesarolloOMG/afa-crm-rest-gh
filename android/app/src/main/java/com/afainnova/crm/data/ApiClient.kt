package com.afainnova.crm.data

import com.afainnova.crm.BuildConfig
import com.google.gson.JsonElement
import com.google.gson.JsonParser
import kotlinx.coroutines.suspendCancellableCoroutine
import okhttp3.*
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

class ApiClient(private val sessions: Sessions, baseUrl: String = BuildConfig.API_URL): Transport {
    private val base = baseUrl.toHttpUrl()
    private val client = OkHttpClient.Builder().connectTimeout(20,TimeUnit.SECONDS).readTimeout(120,TimeUnit.SECONDS)
        .callTimeout(150,TimeUnit.SECONDS).retryOnConnectionFailure(false).followRedirects(false).build()
    init { require(base.isHttps) { "El servidor debe utilizar HTTPS" } }
    internal fun requestFor(call: com.afainnova.crm.data.Call): Request {
        require(!call.path.startsWith("/") && "://" !in call.path && ".." !in call.path)
        val url = requireNotNull(base.resolve(call.path)).newBuilder()
        if (call.authenticated) {
            val user = sessions.read() ?: throw ApiException(401, "Tu sesión expiró. Inicia sesión nuevamente.")
            // The legacy middleware accepts the token parameter; never log or share this URL.
            url.addQueryParameter("token", user.token)
        }
        val request = Request.Builder().url(url.build()).header("Accept", "application/json")
        if (call.method != "GET") {
            val body = when (call.encoding) {
                Encoding.JSON -> call.payload.toString().toRequestBody("application/json; charset=utf-8".toMediaType())
                Encoding.DATA -> FormBody.Builder().add("data",call.payload.toString()).build()
                Encoding.FIELDS -> FormBody.Builder().apply { call.payload.entrySet().forEach { (key,value) -> add(key, if(value.isJsonPrimitive) value.asString else value.toString()) } }.build()
            }
            request.method(call.method, body)
        }
        return request.build()
    }
    override suspend fun execute(call: com.afainnova.crm.data.Call): JsonElement {
        val request=requestFor(call)
        return suspendCancellableCoroutine { continuation ->
            val pending = client.newCall(request)
            continuation.invokeOnCancellation { pending.cancel() }
            pending.enqueue(object: Callback {
                override fun onFailure(call: okhttp3.Call, e: IOException) {
                    if(continuation.isActive) continuation.resumeWithException(ApiException(0,"No fue posible conectar. Revisa tu conexión. Si estabas guardando, consulta el registro antes de reintentar."))
                }
                override fun onResponse(call: okhttp3.Call, response: Response) {
                    response.use {
                        try {
                            val body = response.body ?: throw ApiException(response.code,"El servidor respondió sin contenido.")
                            if(body.contentLength() > 32L * 1024 * 1024) throw ApiException(413,"La respuesta es demasiado grande. Usa un criterio más específico.")
                            val json = try { JsonParser.parseString(String(FileRules.readLimited(body.byteStream(),32*1024*1024),Charsets.UTF_8)) } catch (_:Exception) { throw ApiException(response.code,"El servidor devolvió una respuesta no válida o demasiado grande.") }
                            val data=json.objectOrEmpty()
                            val code=data.n("code")
                            if (!response.isSuccessful || (code != 0 && code !in 200..299)) {
                                val message=data.s("message").ifBlank { data.s("error").ifBlank { "La operación no pudo completarse (${response.code})." } }
                                if(response.code==401 || code==401) sessions.clear()
                                throw ApiException(if(code!=0)code else response.code,message)
                            }
                            if(continuation.isActive) continuation.resume(json)
                        } catch(e:Exception) { if(continuation.isActive)continuation.resumeWithException(e) }
                    }
                }
            })
        }
    }
}

fun segment(value: String): String = java.net.URLEncoder.encode(value, "UTF-8").replace("+", "%20")
