package com.afainnova.crm.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

interface Sessions { fun read(): User?; fun save(token: String): User; fun clear() }

class SessionStore(context: Context): Sessions {
    private val prefs = context.getSharedPreferences("afa_session", Context.MODE_PRIVATE)
    private val alias = "afa.crm.session"
    private fun key(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(alias, null) as? SecretKey)?.let { return it }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build())
        }.generateKey()
    }
    override fun read(): User? = try {
        val stored = prefs.getString("session", null)
        if (stored == null) null else {
            val pieces = stored.split(':')
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, Base64.decode(pieces[0], Base64.NO_WRAP)))
            val user = decode(String(cipher.doFinal(Base64.decode(pieces[1], Base64.NO_WRAP)), Charsets.UTF_8))
            user.takeIf { it.expires > System.currentTimeMillis() / 1000 } ?: run { clear(); null }
        }
    } catch (_: Exception) { clear(); null }
    override fun save(token: String): User {
        val user = decode(token)
        require(user.expires > System.currentTimeMillis() / 1000) { "La sesión ya expiró" }
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key())
        val data = cipher.doFinal(token.toByteArray(Charsets.UTF_8))
        check(prefs.edit().putString("session", Base64.encodeToString(cipher.iv, Base64.NO_WRAP)+":"+Base64.encodeToString(data,Base64.NO_WRAP)).commit())
        return user
    }
    override fun clear() { prefs.edit().clear().commit() }
    companion object {
        fun decode(token: String): User {
            val pieces = token.split('.')
            require(pieces.size == 3) { "Respuesta de sesión inválida" }
            val payload = parseObject(String(Base64.decode(pieces[1], Base64.URL_SAFE or Base64.NO_WRAP or Base64.NO_PADDING), Charsets.UTF_8))
            val profile = if(payload["sub"]?.isJsonObject == true) payload.o("sub") else parseObject(payload.s("sub"))
            return User(token, profile, payload.s("exp").toLong())
        }
    }
}
